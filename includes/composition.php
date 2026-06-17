<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Importação de "obras da composição".
 *
 * No artimage, alguns produtos são composições: a página de detalhe lista, na
 * seção "obras dessa composição", outras obras que pertencem ao mesmo conjunto.
 * Essas obras NÃO aparecem na listagem das subcategorias, então o coletor normal
 * nunca as encontra.
 *
 * Característica importante (descoberta na origem): a relação é SIMÉTRICA. A página
 * de QUALQUER membro lista todos os OUTROS membros (a peça inteira + os módulos),
 * excluindo ela mesma. Logo não há uma árvore pai→filho no HTML: é um GRUPO.
 *
 * Modelo adotado:
 *  - Todos os membros viram produtos simples compráveis (SKU próprio).
 *  - Cada membro guarda o meta `_artimage_composition_group` (chave estável do grupo)
 *    e `_artimage_detalhe_id` (id de origem). Isso liga o grupo de forma determinística.
 *  - A chave do grupo é o MENOR id de detalhe do conjunto (atual + membros listados),
 *    portanto idêntica a partir de qualquer ponto de entrada.
 *
 * Guardrails:
 *  - Feature flag `art_image_import_compositions` (default ON; filtro para desligar).
 *  - Só dispara na seção certa (texto "obras dessa composição") — não confunde com
 *    outras seções "related" (ex.: cross-sell).
 *  - "Expandir uma vez por grupo": um transient por grupo evita a tempestade de
 *    re-enfileiramento (13 membros × 12 links = 156 tentativas).
 *  - Dedup por SKU: nunca cria um produto cujo SKU já existe; membros já existentes
 *    apenas recebem o meta de grupo (ficam ligados na hora).
 *  - Teto de membros por composição (filtro `art_image_composition_max_members`).
 *  - Nunca interrompe a importação: toda a lógica roda dentro de try/catch.
 */
class ArtImageComposition
{
    /** Meta com a chave do grupo da composição (menor id de detalhe do conjunto). */
    const META_GROUP = '_artimage_composition_group';

    /** Meta com o id de detalhe de origem do produto (útil para ordenação/diagnóstico). */
    const META_DETALHE_ID = '_artimage_detalhe_id';

    /** Prefixo do transient "grupo já expandido". */
    const EXPAND_FLAG_PREFIX = 'artimage_comp_exp_';

    /** Teto de segurança de membros por composição. */
    const MAX_MEMBERS = 200;

    /**
     * Registra os hooks de exibição no front.
     */
    public static function init(): void
    {
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'render_members'], 15);
    }

    /**
     * Feature flag. Default ligado; pode ser desligado via filtro.
     */
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('art_image_import_compositions', true);
    }

    /**
     * Extrai os membros da composição a partir do HTML da página de detalhe.
     *
     * Retorna [] quando a página não é uma composição (guardrail: exige o texto
     * "obras dessa composição"). Cada membro: ['id','link','title','code'].
     */
    public static function parse_from_html(string $html): array
    {
        // Guardrail 1: só ativa na seção correta.
        if (stripos($html, 'obras dessa composi') === false) {
            return [];
        }

        // Isola o bloco da seção: do heading até o primeiro </section>.
        if (!preg_match('#obras dessa composi.*?</section>#is', $html, $secm)) {
            return [];
        }
        $block = $secm[0];

        $members = [];
        $seen = [];

        // Cada item: <a href=".../produtos/detalhe/ID" class="item ...">
        //   ... <span class="item-title">TÍTULO</span> ...
        //   ... <span class="item-code ...">SKU</span>
        // Obs.: itens da composição NÃO têm item-price (por isso o regex do grid
        // de listagem não casa aqui). Pegamos só link + título + SKU; preço,
        // técnica e imagens vêm da própria página de cada obra na importação.
        if (preg_match_all(
            '#href="([^"]*/produtos/detalhe/(\d+))"[^>]*class="item[^"]*"[^>]*>.*?item-title">([^<]*)<.*?item-code[^"]*">([^<]+)<#is',
            $block,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id = (int) $m[2];
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $code = trim(html_entity_decode($m[4]));
                if ($code === '') {
                    continue; // sem SKU não dá para deduplicar/importar com segurança
                }
                $seen[$id] = true;
                $members[] = [
                    'id'    => $id,
                    'link'  => html_entity_decode(trim($m[1])),
                    'title' => trim(html_entity_decode($m[3])),
                    'code'  => $code,
                ];
            }
        }

        // Guardrail 2: teto de membros (página anômala não derruba o sync).
        $max = (int) apply_filters('art_image_composition_max_members', self::MAX_MEMBERS);
        if ($max > 0 && count($members) > $max) {
            if (class_exists('ArtImageLogger')) {
                ArtImageLogger::error("Composição com mais de {$max} membros — truncando por segurança", ['total' => count($members)]);
            }
            $members = array_slice($members, 0, $max);
        }

        return $members;
    }

    /**
     * Chamado após cada produto ser salvo na importação.
     *
     * - Calcula a chave do grupo e grava o meta de pertencimento neste produto.
     * - Liga (meta) os membros já existentes e enfileira os que ainda não existem.
     * - "Expandir uma vez por grupo" evita reprocessar a cada membro do conjunto.
     *
     * @param array $context ['link','subcategory_id','subcategory_parent_id']
     * @param array $details Resultado de get_product_details (espera composition_members).
     */
    public static function handle_after_import(int $product_id, string $sku, array $context, array $details): void
    {
        if (!self::is_enabled() || $product_id <= 0) {
            return;
        }

        try {
            $members = $details['composition_members'] ?? [];
            if (empty($members) || !is_array($members)) {
                return;
            }

            $link = (string) ($context['link'] ?? '');
            $current_id = self::detalhe_id_from_url($link);
            if ($current_id <= 0) {
                $current_id = (int) ($details['detalhe_id'] ?? 0);
            }

            // Chave do grupo = menor id de detalhe do conjunto (atual + membros).
            // Estável a partir de qualquer membro (a relação é simétrica).
            $ids = [$current_id];
            foreach ($members as $mm) {
                $ids[] = (int) ($mm['id'] ?? 0);
            }
            $ids = array_filter($ids, static function ($v) {
                return $v > 0;
            });
            if (empty($ids)) {
                return;
            }
            $group = (string) min($ids);

            // Marca este produto como membro do grupo.
            update_post_meta($product_id, self::META_GROUP, $group);
            if ($current_id > 0) {
                update_post_meta($product_id, self::META_DETALHE_ID, $current_id);
            }

            // Guardrail "expandir uma vez por grupo".
            $flag = self::EXPAND_FLAG_PREFIX . $group;
            if (get_transient($flag)) {
                return; // outro membro já expandiu este grupo nesta janela
            }
            set_transient($flag, time(), 12 * HOUR_IN_SECONDS);

            self::enqueue_missing_members($members, $group, $context);
        } catch (\Throwable $e) {
            if (class_exists('ArtImageLogger')) {
                ArtImageLogger::error('Falha ao processar composição: ' . $e->getMessage(), ['sku' => $sku]);
            }
        }
    }

    /**
     * Liga membros existentes (meta) e enfileira os ausentes, com dedup por SKU.
     */
    private static function enqueue_missing_members(array $members, string $group, array $context): void
    {
        $payloads = [];
        $linked_existing = 0;

        foreach ($members as $m) {
            $code = sanitize_text_field($m['code'] ?? '');
            if ($code === '') {
                continue;
            }

            // DEDUP: SKU é a chave única. Se já existe, NÃO recria — apenas garante
            // que o produto existente fique ligado ao grupo (exibição imediata).
            $existing = wc_get_product_id_by_sku($code);
            if ($existing) {
                update_post_meta($existing, self::META_GROUP, $group);
                if ((int) ($m['id'] ?? 0) > 0) {
                    update_post_meta($existing, self::META_DETALHE_ID, (int) $m['id']);
                }
                $linked_existing++;
                continue;
            }

            $payloads[] = [
                'product_data' => [
                    'link'  => esc_url_raw($m['link'] ?? ''),
                    'code'  => $code,
                    'title' => sanitize_text_field($m['title'] ?? ''),
                ],
                'subcategory_id'        => (int) ($context['subcategory_id'] ?? 0),
                'subcategory_parent_id' => (int) ($context['subcategory_parent_id'] ?? 0),
                'composition_group'     => $group,
            ];
        }

        if (empty($payloads)) {
            self::log("Composição {$group}: nenhum membro novo a enfileirar ({$linked_existing} já existiam e foram ligados)");
            return;
        }

        if (class_exists('ArtImageASManager') && ArtImageASManager::is_available()) {
            // Caminho principal: Action Scheduler (sync agendado). Cada obra vira
            // um job normal de produto, absorvido pelo batch system existente.
            ArtImageASManager::schedule_product_batches($payloads);
            $total = (int) get_option('artimage_as_total_products', 0);
            update_option('artimage_as_total_products', $total + count($payloads));
        } else {
            // Fallback: fila manual via transient (importação manual sem AS).
            $queue = get_transient('artimage_product_import_queue');
            if (!is_array($queue)) {
                $queue = [];
            }
            $queue = array_merge($queue, $payloads);
            set_transient('artimage_product_import_queue', array_values($queue), 12 * HOUR_IN_SECONDS);
            $total = (int) get_transient('artimage_product_import_total');
            set_transient('artimage_product_import_total', $total + count($payloads), 12 * HOUR_IN_SECONDS);
        }

        self::log("Composição {$group}: " . count($payloads) . " obras novas enfileiradas, {$linked_existing} já existiam (dedup)");
    }

    /**
     * Extrai o id de detalhe de uma URL de produto.
     */
    private static function detalhe_id_from_url(string $url): int
    {
        if ($url !== '' && preg_match('#/detalhe/(\d+)#', $url, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private static function log(string $message): void
    {
        if (class_exists('ArtImageLogger')) {
            ArtImageLogger::action_scheduler($message);
        }
    }

    // =========================================================================
    // Exibição no front
    // =========================================================================

    /**
     * Renderiza "Obras desta composição" na página do produto, listando os
     * demais membros do mesmo grupo.
     */
    public static function render_members(): void
    {
        global $product;
        if (!$product || !is_object($product)) {
            return;
        }

        $pid   = $product->get_id();
        $group = get_post_meta($pid, self::META_GROUP, true);
        if (!$group) {
            return;
        }

        // Filtra só pelo grupo (sem join extra de ordenação, para não excluir
        // eventual membro sem `detalhe_id`). Ordena por ID asc — a peça inteira,
        // importada primeiro, tende a aparecer no início.
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'post__not_in'   => [$pid],
            'meta_query'     => [[
                'key'   => self::META_GROUP,
                'value' => $group,
            ]],
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        if (empty($ids)) {
            return;
        }

        echo '<section class="artimage-composition">';
        echo '<h2 class="artimage-composition__title">' . esc_html__('Obras desta composição', 'ponto-design-art-image-importer') . '</h2>';
        echo '<ul class="artimage-composition__grid">';

        foreach ($ids as $mid) {
            $mp = wc_get_product($mid);
            if (!$mp) {
                continue;
            }
            $url = get_permalink($mid);
            echo '<li class="artimage-composition__item">';
            echo '<a href="' . esc_url($url) . '">';
            echo $mp->get_image('woocommerce_thumbnail'); // já escapado pelo WC
            echo '<span class="artimage-composition__name">' . esc_html($mp->get_name()) . '</span>';
            echo '<span class="artimage-composition__price">' . wp_kses_post($mp->get_price_html()) . '</span>';
            echo '</a>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</section>';

        // Estilo mínimo, themable (sobrescreva no tema se quiser).
        echo '<style>
            .artimage-composition{margin:2em 0;clear:both}
            .artimage-composition__grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1em}
            .artimage-composition__item a{display:block;text-decoration:none;color:inherit}
            .artimage-composition__item img{width:100%;height:auto;display:block;margin-bottom:.4em}
            .artimage-composition__name{display:block;font-size:.85em;line-height:1.2}
            .artimage-composition__price{display:block;font-size:.85em;font-weight:600;margin-top:.2em}
        </style>';
    }
}
