<?php
if (! defined('ABSPATH')) {
    exit;
}

// Garante que o WooCommerce está ativo
if (! class_exists('WooCommerce')) {
    return;
}

/**
 * Classe responsável por importar dados do ArtImageApiClient
 */
class ArtImageImporter
{
    private $client;

    public function __construct()
    {
        $this->client = new ArtImageApiClient();
    }

    /**
     * Importa categorias principais para product_cat
     */
    public function import_categories(): array
    {
        if (get_transient('artimage_import_lock')) {
            return ['Outro processo de importação já está em execução – abortando.'];
        }
        set_transient('artimage_import_lock', 1, 15 * MINUTE_IN_SECONDS);

        $logs = [];
        $cats = $this->client->get_main_categories();

        foreach ($cats as $cat) {
            $term = term_exists($cat['slug'], 'product_cat');
            if (! $term) {
                $result = wp_insert_term($cat['nome'], 'product_cat', [
                    'slug' => $cat['slug'],
                ]);
                if (is_wp_error($result)) {
                    $logs[] = "Erro ao criar categoria “{$cat['nome']}”: " . $result->get_error_message();
                } else {
                    $logs[] = "Categoria criada: {$cat['nome']}";
                }
            } else {
                $logs[] = "Categoria já existe: {$cat['nome']}";
            }
        }
        
        delete_transient('artimage_import_lock');
        return $logs;
    }

    /**
     * Importa subcategorias para product_cat, com parent
     */
    public function import_subcategories(): array
    {
        if (get_transient('artimage_import_lock')) {
            return ['Outro processo de importação já está em execução – abortando.'];
        }
        set_transient('artimage_import_lock', 1, 15 * MINUTE_IN_SECONDS);

        $logs = [];

        // Busca termos principais
        $parents = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        foreach ($parents as $parent) {
            // monta URL de listagem de subcats
            $url  = "https://artimage.com.br/produtos/{$parent->slug}";
            $subs = $this->client->get_subcategories($url);

            foreach ($subs as $sub) {
                $term = term_exists($sub['slug'], 'product_cat');
                if (! $term) {
                    $result = wp_insert_term($sub['nome'], 'product_cat', [
                        'slug'   => $sub['slug'],
                        'parent' => $parent->term_id,
                    ]);
                    if (is_wp_error($result)) {
                        $logs[] = "Erro ao criar subcategoria “{$sub['nome']}”: " . $result->get_error_message();
                    } else {
                        $logs[] = "Subcategoria criada: {$sub['nome']} (pai: {$parent->name})";
                    }
                } else {
                    $logs[] = "Subcategoria já existe: {$sub['nome']}";
                }
            }
        }

        delete_transient('artimage_import_lock');
        return $logs;
    }

    /**
     * Prepara a fila de importação de produtos.
     * Chamado pela primeira vez para construir a lista de todos os produtos.
     */
    private function prepare_product_import_queue(): array
    {
        $logs = [];
        $product_queue = [];

        $subs = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'fields'     => 'all', // Garante que temos term_id, slug, name, parent
        ]);

        if (is_wp_error($subs) || empty($subs)) {
            return ['logs' => ["Nenhuma subcategoria encontrada para buscar produtos."], 'product_queue' => [], 'total_to_import' => 0];
        }

        $logs[] = "Iniciando coleta de produtos de " . count($subs) . " subcategorias...";

        foreach ($subs as $sub) {
            $logs[] = "Coletando produtos da subcategoria: {$sub->name} ({$sub->slug})...";
            $products_data = $this->client->get_products($sub->slug); // Assume que isso retorna um array de produtos

            if (empty($products_data)) {
                $logs[] = "Nenhum produto encontrado para {$sub->name}.";
                continue;
            }

            foreach ($products_data as $p_data) {
                // Adiciona informações da subcategoria necessárias para o processamento posterior
                $product_queue[] = [
                    'product_data' => $p_data,
                    'subcategory_id' => $sub->term_id,
                    'subcategory_parent_id' => $sub->parent,
                ];
            }
            $logs[] = "Coletados " . count($products_data) . " produtos de {$sub->name}.";
        }

        $total_to_import = count($product_queue);
        set_transient('artimage_product_import_queue', $product_queue, 1 * HOUR_IN_SECONDS);
        set_transient('artimage_product_import_total', $total_to_import, 1 * HOUR_IN_SECONDS);
        set_transient('artimage_product_processed_count', 0, 1 * HOUR_IN_SECONDS); // Inicializa o contador de processados

        $logs[] = "Total de {$total_to_import} produtos adicionados à fila de importação.";
        return ['logs' => $logs, 'product_queue' => $product_queue, 'total_to_import' => $total_to_import];
    }

    /**
     * Importa um lote de produtos do WooCommerce a partir da fila.
     *
     * @param int $page Número da página/lote atual.
     * @param int $batch_size Quantidade de produtos por lote.
     * @return array Detalhes do processamento do lote.
     */
    public function import_products_batch(int $page = 1, int $batch_size = 5): array
    {
        $logs = [];
        $processed_in_batch = 0;

        // Verifica o bloqueio de importação geral
        if ($page === 1 && get_transient('artimage_import_lock')) {
            return [
                'status' => 'error',
                'logs' => ['Outro processo de importação (não de produtos) já está em execução – abortando.'],
                'processed_count' => 0,
                'current_total_processed' => (int) get_transient('artimage_product_processed_count'),
                'total_to_import' => (int) get_transient('artimage_product_import_total'),
                'has_more' => false,
                'next_page' => $page
            ];
        }
        if ($page === 1) {
            // Define um bloqueio específico para a importação de produtos em lote
            set_transient('artimage_product_batch_lock', 1, 15 * MINUTE_IN_SECONDS);
            delete_transient('artimage_cancel_import_flag'); // Limpa flag de cancelamento anterior
            
            $preparation_result = $this->prepare_product_import_queue();
            $logs = array_merge($logs, $preparation_result['logs']);
            // $product_queue = $preparation_result['product_queue']; // Não precisa aqui, será lido do transient
            $total_to_import = $preparation_result['total_to_import'];

            if ($total_to_import === 0) {
                 delete_transient('artimage_product_batch_lock');
                 $logs[] = "Nenhum produto encontrado na fila para importar.";
                 return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'processed_count' => 0,
                    'current_total_processed' => 0,
                    'total_to_import' => 0,
                    'has_more' => false,
                    'next_page' => 1
                ];
            }
        } else {
            // Verifica se o bloqueio de lote de produtos ainda existe (ou seja, se o processo foi iniciado)
            if (!get_transient('artimage_product_batch_lock')) {
                return [
                    'status' => 'error',
                    'logs' => ['Erro: Processo de importação de produtos em lote não iniciado ou bloqueio expirou.'],
                    'has_more' => false,
                ];
            }
        }
        
        // Lê a fila e totais dos transientes em todas as chamadas de lote
        $product_queue = get_transient('artimage_product_import_queue');
        $total_to_import = (int) get_transient('artimage_product_import_total');

        if ($product_queue === false || $total_to_import === 0 && $page > 1) {
             delete_transient('artimage_product_batch_lock');
             return [
                'status' => 'error',
                'logs' => ['Erro: Fila de importação de produtos não encontrada ou vazia inesperadamente.'],
                'has_more' => false,
            ];
        }
        if (empty($product_queue) && $page === 1 && $total_to_import === 0) {
            // Caso especial: prepare_product_import_queue não encontrou nada e já retornou.
            // Este bloco é para segurança, mas o retorno de prepare_product_import_queue já deve ter tratado.
             delete_transient('artimage_product_batch_lock');
             return [
                'status' => 'completed',
                'logs' => $logs, // $logs da preparação
                'has_more' => false,
            ];
        }


        // Verifica flag de cancelamento
        if (get_transient('artimage_cancel_import_flag')) {
            delete_transient('artimage_product_batch_lock');
            delete_transient('artimage_product_import_queue');
            delete_transient('artimage_product_import_total');
            delete_transient('artimage_product_processed_count');
            delete_transient('artimage_cancel_import_flag'); // Limpa a própria flag
            $logs[] = "Importação de produtos cancelada pelo usuário.";
            return [
                'status' => 'cancelled',
                'logs' => $logs,
                'has_more' => false,
            ];
        }

        $current_total_processed = (int) get_transient('artimage_product_processed_count');
        // O offset é relativo ao início da fila original, não ao que resta.
        $offset = ($page - 1) * $batch_size;
        
        // Pegamos o lote da fila completa
        $batch_items = array_slice((array)$product_queue, $offset, $batch_size);

        if (empty($batch_items)) {
            // Se não há mais itens E o total processado é igual ou maior que o total a importar, então está completo.
            if ($current_total_processed >= $total_to_import) {
                delete_transient('artimage_product_batch_lock');
                delete_transient('artimage_product_import_queue');
                delete_transient('artimage_product_import_total');
                delete_transient('artimage_product_processed_count');
                $logs[] = "Importação de produtos concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
                 return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            } else {
                // Lote vazio, mas não terminou - pode ser um erro ou fim da fila antes do esperado.
                // Se a fila original ($product_queue) já foi totalmente processada pelo array_slice e $offset
                // e $current_total_processed < $total_to_import, isso indica um problema.
                // No entanto, o `array_slice` em uma fila já processada retornará vazio.
                // A condição principal é $current_total_processed < $total_to_import
                $logs[] = "Nenhum item restante na fila para o lote {$page}, mas a importação não foi marcada como concluída. Verificando...";
                 delete_transient('artimage_product_batch_lock'); // Limpar bloqueio em caso de erro
                 return [
                    'status' => 'error',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
        }
        
        $logs[] = "Processando lote {$page}. Itens no lote: " . count($batch_items) . ". Processados anteriormente: {$current_total_processed} de {$total_to_import}.";

        foreach ($batch_items as $item_to_process) {
            $p = $item_to_process['product_data'];
            $sub_term_id = $item_to_process['subcategory_id'];
            $sub_parent_id = $item_to_process['subcategory_parent_id'];

            $exists = wc_get_product_id_by_sku($p['code']);
            if ($exists) {
                $logs[] = "Produto já existe (SKU {$p['code']}): {$p['title']}";
            } else {
                $product = new WC_Product_Simple();
                $product->set_name(sanitize_text_field($p['title']));
                $product->set_sku(sanitize_text_field($p['code']));
                $price_numeric = floatval(str_replace(['R$', '.', ','], ['', '', '.'], $p['price']));
                $product->set_regular_price($price_numeric);
                $product->set_status('publish');
                
                $prod_id = $product->save();

                if ($prod_id && !is_wp_error($prod_id)) {
                    wp_set_object_terms($prod_id, [(int)$sub_term_id], 'product_cat', false);
                    if ($sub_parent_id) {
                        wp_set_object_terms($prod_id, [(int)$sub_parent_id], 'product_cat', true);
                    }
                    update_post_meta($prod_id, '_external_link', esc_url_raw($p['link']));
                    update_post_meta($prod_id, '_size', sanitize_text_field($p['size']));
                    $logs[] = "Produto criado: {$p['title']} (ID {$prod_id})";
                } else {
                    $error_message = is_wp_error($prod_id) ? $prod_id->get_error_message() : 'Erro desconhecido ao salvar produto.';
                    $logs[] = "Erro ao criar produto “{$p['title']}”: " . $error_message;
                }
            }
            $current_total_processed++; // Incrementa para cada item tentado no lote
            $processed_in_batch++;
        }
        
        set_transient('artimage_product_processed_count', $current_total_processed, 1 * HOUR_IN_SECONDS);
        $has_more = $current_total_processed < $total_to_import;

        if (!$has_more) {
            $logs[] = "Importação de produtos concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
            delete_transient('artimage_product_batch_lock');
            delete_transient('artimage_product_import_queue');
            delete_transient('artimage_product_import_total');
            delete_transient('artimage_product_processed_count');
        } else {
            $logs[] = "Lote {$page} concluído. {$current_total_processed}/{$total_to_import} produtos processados no total.";
        }

        return [
            'status' => $has_more ? 'processing' : ($current_total_processed >= $total_to_import ? 'completed' : 'error'),
            'logs' => $logs,
            'processed_in_batch' => $processed_in_batch, // Renomeado de processed_count para clareza
            'current_total_processed' => $current_total_processed,
            'total_to_import' => $total_to_import,
            'has_more' => $has_more,
            'next_page' => $page + 1
        ];
    }

    /**
     * Importa artistas para CPT 'artists'
     */
    public function import_artists(): array
    {
        if (get_transient('artimage_import_lock')) {
            return ['Outro processo de importação já está em execução – abortando.'];
        }
        set_transient('artimage_import_lock', 1, 15 * MINUTE_IN_SECONDS);

        $logs    = [];
        $artists = $this->client->get_artists('https://artimage.com.br/artistas');

        foreach ($artists as $a) {
            // busca por slug externo
            $existing = get_posts([
                'post_type'  => 'artists',
                'meta_key'   => '_external_slug',
                'meta_value' => $a['slug'],
                'fields'     => 'ids',
            ]);

            if ($existing) {
                $logs[] = "Artista já existe: {$a['title']}";
                continue;
            }

            // cria post type artists
            $post_id = wp_insert_post([
                'post_type'    => 'artists',
                'post_title'   => $a['title'],
                'post_content' => $a['description'],
                'post_status'  => 'publish',
            ]);

            if (is_wp_error($post_id)) {
                $logs[] = "Erro ao criar artista {$a['title']}: " . $post_id->get_error_message();
                continue;
            }

            // metadados
            update_post_meta($post_id, '_external_slug', $a['slug']);
            update_post_meta($post_id, '_image_url',     $a['image']);

            $logs[] = "Artista criado: {$a['title']} (ID {$post_id})";
        }

        delete_transient('artimage_import_lock');
        return $logs;
    }

    // --- AJAX wrappers ---

    public function ajax_import_categories()
    {
        wp_send_json_success($this->import_categories());
    }

    public function ajax_import_subcategories()
    {
        wp_send_json_success($this->import_subcategories());
    }

    public function ajax_import_products()
    {
        // O nonce e a permissão já foram verificados em async-handler.php
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        // Permite filtrar o tamanho do lote, default 5.
        // Pequenos lotes são melhores para evitar timeouts e para feedback mais rápido.
        $batch_size = apply_filters('art_image_product_import_batch_size', 5);

        $result = $this->import_products_batch($page, $batch_size);
        wp_send_json_success($result); // Envia o array estruturado diretamente
    }

    public function ajax_import_artists()
    {
        wp_send_json_success($this->import_artists());
    }
}
