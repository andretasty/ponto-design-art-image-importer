<?php
/**
 * Funções auxiliares para melhorar o sistema de importação
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador centralizado de locks para importação
 *
 * Esta classe unifica o controle de locks que antes estava espalhado em múltiplos
 * transients (artimage_master_import_lock, artimage_*_batch_lock, etc.)
 */
class ArtImageLockManager {

    /**
     * Nome da option que armazena o lock
     */
    const LOCK_OPTION = 'artimage_import_lock';

    /**
     * Timeout padrão do lock em segundos (15 minutos)
     */
    const DEFAULT_TIMEOUT = 900;

    /**
     * Tipos válidos de importação
     */
    const VALID_TYPES = ['categories', 'subcategories', 'products', 'artists', 'sync'];

    /**
     * Adquire um lock para um tipo de importação
     *
     * @param string $type Tipo de importação
     * @param int $timeout Timeout em segundos (padrão: 15 minutos)
     * @return bool True se conseguiu adquirir o lock
     */
    public static function acquire($type, $timeout = self::DEFAULT_TIMEOUT) {
        if (!in_array($type, self::VALID_TYPES, true)) {
            return false;
        }

        $current_lock = self::get_current();

        // Se já existe um lock ativo de outro tipo, não permite
        if ($current_lock !== null && $current_lock['type'] !== $type) {
            // Verifica se o lock expirou
            if (time() < $current_lock['expires']) {
                return false;
            }
            // Lock expirou, pode sobrescrever
        }

        $lock_data = [
            'type' => $type,
            'started' => time(),
            'expires' => time() + $timeout,
        ];

        update_option(self::LOCK_OPTION, $lock_data, false);

        // Também mantém o transient legado para compatibilidade
        set_transient('artimage_master_import_lock', $type, $timeout);

        return true;
    }

    /**
     * Libera o lock de um tipo específico
     *
     * @param string $type Tipo de importação (opcional, libera qualquer se não especificado)
     * @return bool True se liberou o lock
     */
    public static function release($type = null) {
        $current_lock = self::get_current();

        if ($current_lock === null) {
            return true; // Não havia lock
        }

        // Se especificou tipo, só libera se for o mesmo
        if ($type !== null && $current_lock['type'] !== $type) {
            return false;
        }

        delete_option(self::LOCK_OPTION);
        delete_transient('artimage_master_import_lock');

        return true;
    }

    /**
     * Obtém informações do lock atual
     *
     * @return array|null Dados do lock ou null se não houver
     */
    public static function get_current() {
        $lock_data = get_option(self::LOCK_OPTION);

        if (empty($lock_data) || !is_array($lock_data)) {
            return null;
        }

        // Verifica se expirou
        if (isset($lock_data['expires']) && time() > $lock_data['expires']) {
            self::release();
            return null;
        }

        return $lock_data;
    }

    /**
     * Verifica se existe um lock ativo
     *
     * @param string|null $type Tipo específico para verificar (opcional)
     * @return bool
     */
    public static function is_locked($type = null) {
        $current_lock = self::get_current();

        if ($current_lock === null) {
            return false;
        }

        if ($type !== null) {
            return $current_lock['type'] === $type;
        }

        return true;
    }

    /**
     * Obtém o tipo do lock atual
     *
     * @return string|null
     */
    public static function get_type() {
        $current_lock = self::get_current();
        return $current_lock ? $current_lock['type'] : null;
    }

    /**
     * Limpa todos os locks (força)
     *
     * @return void
     */
    public static function clear_all() {
        delete_option(self::LOCK_OPTION);
        delete_transient('artimage_master_import_lock');

        // Também limpa locks de batch legados
        $batch_locks = [
            'artimage_product_batch_lock',
            'artimage_category_batch_lock',
            'artimage_subcategory_batch_lock',
            'artimage_artist_batch_lock',
        ];

        foreach ($batch_locks as $lock) {
            delete_transient($lock);
        }

        if (class_exists('ArtImageLogger')) {
            ArtImageLogger::info('Todos os locks foram limpos via LockManager');
        }
    }

    /**
     * Renova o timeout do lock atual
     *
     * @param int $timeout Novo timeout em segundos
     * @return bool
     */
    public static function renew($timeout = self::DEFAULT_TIMEOUT) {
        $current_lock = self::get_current();

        if ($current_lock === null) {
            return false;
        }

        $current_lock['expires'] = time() + $timeout;
        update_option(self::LOCK_OPTION, $current_lock, false);
        set_transient('artimage_master_import_lock', $current_lock['type'], $timeout);

        return true;
    }

    /**
     * Obtém tempo restante do lock em segundos
     *
     * @return int Segundos restantes ou 0 se não houver lock
     */
    public static function get_remaining_time() {
        $current_lock = self::get_current();

        if ($current_lock === null) {
            return 0;
        }

        $remaining = $current_lock['expires'] - time();
        return max(0, $remaining);
    }
}

/**
 * Funções auxiliares para importação
 */

/**
 * Configura otimizações para importação
 */
function art_image_configure_import_optimizations() {
    // Aumenta limites se possível
    @ini_set('max_execution_time', 86400);
    @ini_set('memory_limit', '512M');
    
    // Desabilita cache de objetos durante importação
    wp_suspend_cache_invalidation(true);
    wp_defer_term_counting(true);
    wp_defer_comment_counting(true);
    
    // Define constantes se não existirem
    if (!defined('WP_IMPORTING')) {
        define('WP_IMPORTING', true);
    }
}

/**
 * Restaura configurações após importação
 */
function art_image_restore_import_settings() {
    wp_suspend_cache_invalidation(false);
    wp_defer_term_counting(false);
    wp_defer_comment_counting(false);
    
    // Força recontagem
    wp_update_term_count_now([], 'product_cat');
    wp_update_term_count_now([], 'artist');
}

/**
 * Verifica se a importação pode continuar
 */
function art_image_can_continue_import() {
    // Verifica tempo de execução
    static $start_time = null;
    if ($start_time === null) {
        $start_time = time();
    }
    
    $max_time = (int) ini_get('max_execution_time');
    if ($max_time > 0) {
        $elapsed = time() - $start_time;
        // Para com 30 segundos de margem
        if ($elapsed > ($max_time - 30)) {
            ArtImageTimezoneHelper::log_with_timezone('Importação pausada: próximo do limite de tempo');
            return false;
        }
    }
    
    // Verifica memória
    $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    $memory_usage = memory_get_usage(true);
    $memory_percentage = ($memory_usage / $memory_limit) * 100;
    
    if ($memory_percentage > 80) {
        ArtImageTimezoneHelper::log_with_timezone('Importação pausada: uso de memória em ' . round($memory_percentage) . '%');
        return false;
    }
    
    return true;
}

/**
 * Registra erro de importação
 */
function art_image_log_import_error($context, $error_message, $data = []) {
    $log_entry = sprintf(
        '[ERRO] %s: %s',
        $context,
        $error_message
    );
    
    if (!empty($data)) {
        $log_entry .= ' | Dados: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    
    ArtImageTimezoneHelper::log_with_timezone($log_entry);
    
    // Salva erro em transient para exibir no admin
    $errors = get_transient('art_image_import_errors') ?: [];
    $errors[] = [
        'time' => current_time('mysql'),
        'context' => $context,
        'message' => $error_message,
        'data' => $data
    ];
    
    // Mantém apenas os últimos 50 erros
    if (count($errors) > 50) {
        $errors = array_slice($errors, -50);
    }
    
    set_transient('art_image_import_errors', $errors, DAY_IN_SECONDS);
}

/**
 * Limpa erros de importação
 */
function art_image_clear_import_errors() {
    delete_transient('art_image_import_errors');
}

/**
 * Obtém erros de importação recentes
 */
function art_image_get_import_errors($limit = 10) {
    $errors = get_transient('art_image_import_errors') ?: [];
    return array_slice($errors, -$limit);
}

/**
 * Hook de otimização antes da importação
 */
add_action('art_image_before_import', 'art_image_configure_import_optimizations');

/**
 * Hook de restauração após importação
 */
add_action('art_image_after_import', 'art_image_restore_import_settings');

/**
 * Filtro para ajustar batch size baseado no ambiente
 */
add_filter('art_image_product_import_batch_size', function($batch_size) {
    // Se memória for baixa, reduz batch size
    $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    if ($memory_limit < 128 * 1024 * 1024) {
        return min($batch_size, 3);
    }
    
    // Se tempo de execução for baixo, reduz batch size
    $max_time = (int) ini_get('max_execution_time');
    if ($max_time > 0 && $max_time < 60) {
        return min($batch_size, 2);
    }
    
    return $batch_size;
});

/**
 * Filtro para configurar timeout de download de imagens
 */
add_filter('art_image_download_timeout', function($timeout) {
    // Timeout padrão de 30 segundos, mas permite configuração
    return apply_filters('art_image_api_timeout', 30);
});

/**
 * Filtro para configurar número máximo de imagens na galeria
 */
add_filter('art_image_max_gallery_images', function($max_images) {
    return 5; // Máximo 5 imagens na galeria por padrão
});

/**
 * Função para verificar e recuperar estado da importação
 */
function art_image_get_import_state() {
    return [
        'queue' => get_transient('artimage_product_import_queue'),
        'total' => get_transient('artimage_product_import_total'),
        'processed' => get_transient('artimage_product_processed_count'),
        'lock' => get_transient('artimage_master_import_lock'),
        'batch_lock' => get_transient('artimage_product_batch_lock')
    ];
}

/**
 * Função para limpar todos os transients de importação
 *
 * @deprecated Use art_image_clear_transients() instead
 */
function art_image_clear_import_state() {
    art_image_clear_transients('all');
}

/**
 * Limpa transients de importação de forma centralizada
 *
 * @param string|array $types Tipo(s) de transients a limpar: 'all', 'locks', 'queues', 'counters', 'flags'
 *                            ou array de tipos específicos: ['categories', 'products', 'artists', 'subcategories']
 * @return int Número de transients deletados
 */
function art_image_clear_transients($types = 'all') {
    $all_transients = [
        'locks' => [
            'artimage_master_import_lock',
            'artimage_product_batch_lock',
            'artimage_category_batch_lock',
            'artimage_subcategory_batch_lock',
            'artimage_artist_batch_lock',
        ],
        'queues' => [
            'artimage_product_import_queue',
            'artimage_category_import_queue',
            'artimage_subcategory_import_queue',
            'artimage_artist_import_queue',
            'artimage_product_subs_list',
        ],
        'counters' => [
            'artimage_product_import_total',
            'artimage_product_processed_count',
            'artimage_category_import_total',
            'artimage_category_processed_count',
            'artimage_subcategory_import_total',
            'artimage_subcategory_processed_count',
            'artimage_artist_import_total',
            'artimage_artist_processed_count',
        ],
        'flags' => [
            'artimage_cancel_product_import_flag',
            'artimage_cancel_category_import_flag',
            'artimage_cancel_subcategory_import_flag',
            'artimage_cancel_artist_import_flag',
        ],
    ];

    // Transients por tipo de entidade
    $entity_transients = [
        'categories' => [
            'artimage_category_import_queue',
            'artimage_category_import_total',
            'artimage_category_processed_count',
            'artimage_category_batch_lock',
            'artimage_cancel_category_import_flag',
        ],
        'subcategories' => [
            'artimage_subcategory_import_queue',
            'artimage_subcategory_import_total',
            'artimage_subcategory_processed_count',
            'artimage_subcategory_batch_lock',
            'artimage_cancel_subcategory_import_flag',
        ],
        'products' => [
            'artimage_product_import_queue',
            'artimage_product_import_total',
            'artimage_product_processed_count',
            'artimage_product_batch_lock',
            'artimage_cancel_product_import_flag',
            'artimage_product_subs_list',
        ],
        'artists' => [
            'artimage_artist_import_queue',
            'artimage_artist_import_total',
            'artimage_artist_processed_count',
            'artimage_artist_batch_lock',
            'artimage_cancel_artist_import_flag',
        ],
    ];

    $to_delete = [];

    // Se for 'all', deleta todos
    if ($types === 'all') {
        foreach ($all_transients as $group) {
            $to_delete = array_merge($to_delete, $group);
        }
    }
    // Se for um grupo específico (locks, queues, counters, flags)
    elseif (is_string($types) && isset($all_transients[$types])) {
        $to_delete = $all_transients[$types];
    }
    // Se for um array de tipos de entidade
    elseif (is_array($types)) {
        foreach ($types as $type) {
            if (isset($entity_transients[$type])) {
                $to_delete = array_merge($to_delete, $entity_transients[$type]);
            } elseif (isset($all_transients[$type])) {
                $to_delete = array_merge($to_delete, $all_transients[$type]);
            }
        }
    }
    // Se for um tipo de entidade específico como string
    elseif (is_string($types) && isset($entity_transients[$types])) {
        $to_delete = $entity_transients[$types];
    }

    // Remove duplicatas
    $to_delete = array_unique($to_delete);

    // Deleta os transients
    $deleted = 0;
    foreach ($to_delete as $transient) {
        if (delete_transient($transient)) {
            $deleted++;
        }
    }

    return $deleted;
}

/**
 * Limpa transients de uma entidade específica e libera o master lock se necessário
 *
 * @param string $type Tipo de entidade: 'categories', 'subcategories', 'products', 'artists'
 */
function art_image_cleanup_entity_transients($type) {
    art_image_clear_transients([$type]);

    // Libera o master lock se estiver travado neste tipo
    $master_lock = get_transient('artimage_master_import_lock');
    if ($master_lock === $type) {
        delete_transient('artimage_master_import_lock');
    }
}

/**
 * Adiciona endpoint AJAX para verificar estado da importação
 */
add_action('wp_ajax_art_image_check_import_state', function() {
    check_ajax_referer('art_image_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sem permissão');
    }
    
    $state = art_image_get_import_state();
    
    wp_send_json_success([
        'has_queue' => !empty($state['queue']),
        'queue_size' => is_array($state['queue']) ? count($state['queue']) : 0,
        'total' => $state['total'] ?: 0,
        'processed' => $state['processed'] ?: 0,
        'lock' => $state['lock'],
        'batch_lock' => $state['batch_lock']
    ]);
});

/**
 * Adiciona endpoint AJAX para limpar estado da importação
 */
add_action('wp_ajax_art_image_clear_import_state', function() {
    check_ajax_referer('art_image_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sem permissão');
    }
    
    art_image_clear_import_state();
    
    wp_send_json_success(['message' => 'Estado da importação limpo']);
});

/**
 * MODO DE TESTE: Limites para importação de teste.
 * Descomente estas linhas para limitar a importação durante testes.
 */
// add_filter('art_image_debug_category_limit', function() { return 3; });
// add_filter('art_image_debug_subcategory_limit', function() { return 3; });
// add_filter('art_image_debug_artist_limit', function() { return 3; });
// add_filter('art_image_debug_product_limit', function() { return 5; });

/**
 * Registra log detalhado sobre processamento de produto
 */
function art_image_log_product_processing($sku, $action, $details = []) {
    $log_entry = sprintf(
        '[PRODUTO] SKU: %s | Ação: %s',
        $sku,
        $action
    );
    
    if (!empty($details)) {
        $formatted_details = [];
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $formatted_details[] = $key . ': ' . count($value) . ' itens';
            } else {
                $formatted_details[] = $key . ': ' . $value;
            }
        }
        $log_entry .= ' | ' . implode(' | ', $formatted_details);
    }
    
    ArtImageTimezoneHelper::log_with_timezone($log_entry);
}

/**
 * Verifica se uma URL de imagem é válida
 */
function art_image_is_valid_image_url($url) {
    if (empty($url)) {
        return false;
    }
    
    // Verifica se é uma URL válida
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Verifica se a extensão é de imagem
    $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    
    return in_array($extension, $valid_extensions);
}

/**
 * Extrai dimensões de uma string como "50x70cm"
 *
 * @param string $size_str A string de tamanho.
 * @return array Um array com 'width', 'height', 'length'.
 */
function art_image_parse_dimensions($size_str) {
    $dimensions = [
        'width'  => null,
        'height' => null,
        'length' => null,
    ];

    if (empty($size_str)) {
        return $dimensions;
    }

    // Usa regex para extrair todos os "números" que podem conter dígitos e vírgulas/pontos.
    preg_match_all('/[0-9,.]+/', $size_str, $matches);

    if (!empty($matches[0])) {
        // Limpa e converte os números encontrados para float, tratando vírgula como decimal.
        $numeric_parts = array_map(function($num_str) {
            return floatval(str_replace(',', '.', $num_str));
        }, $matches[0]);
        
        // A convenção para obras de arte é geralmente Altura x Largura x Profundidade
        if (isset($numeric_parts[0])) {
            $dimensions['height'] = $numeric_parts[0];
        }
        if (isset($numeric_parts[1])) {
            $dimensions['width'] = $numeric_parts[1];
        }
        if (isset($numeric_parts[2])) {
            $dimensions['length'] = $numeric_parts[2]; // Profundidade (length no WC)
        }
    }

    return $dimensions;
}

/**
 * Conta produtos importados nas últimas 24 horas
 */
function art_image_get_recent_import_stats() {
    global $wpdb;
    
    $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
    
    $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'images_downloaded' => 0,
        'errors' => 0
    ];
    
    // Conta produtos criados/atualizados
    $products_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p 
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
         WHERE p.post_type = 'product' 
         AND pm.meta_key = '_artimage_last_sync_date' 
         AND pm.meta_value >= %s",
        $yesterday
    ));
    
    $stats['products_total'] = (int) $products_count;
    
    // Conta imagens baixadas
    $images_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} 
         WHERE meta_key = '_artimage_original_url' 
         AND post_id IN (
             SELECT post_id FROM {$wpdb->posts} 
             WHERE post_date >= %s AND post_type = 'attachment'
         )",
        $yesterday
    ));
    
    $stats['images_downloaded'] = (int) $images_count;
    
    return $stats;
}

/**
 * Adiciona endpoint AJAX para verificar estatísticas de importação
 */
add_action('wp_ajax_art_image_get_import_stats', function() {
    check_ajax_referer('art_image_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sem permissão');
    }
    
    $stats = art_image_get_recent_import_stats();
    $errors = art_image_get_import_errors(5); // Últimos 5 erros
    
    wp_send_json_success([
        'stats' => $stats,
        'recent_errors' => $errors,
        'timestamp' => current_time('mysql')
    ]);
});

/**
 * Função de teste para verificar processamento de detalhes de produtos
 */
function art_image_test_product_details_processing() {
    if (!current_user_can('manage_options')) {
        return ['error' => 'Sem permissão'];
    }
    
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/api-client.php';
    $client = new ArtImageApiClient();
    
    // Busca algumas subcategorias para teste
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'parent' => ['!=', 0],
        'number' => 3
    ]);
    
    $test_results = [];
    
    foreach ($terms as $term) {
        $parent_term = get_term($term->parent, 'product_cat');
        $parent_slug = $parent_term && !is_wp_error($parent_term) ? $parent_term->slug : '';
        $filtro_sub_id = get_term_meta($term->term_id, 'filtro_sub_id', true);
        
        if ($parent_slug && $filtro_sub_id) {
            $products_url = "https://artimage.com.br/produtos/{$parent_slug}?filtro-sub={$filtro_sub_id}";
            $products = $client->get_products($products_url);
            
            if (!empty($products)) {
                $first_product = $products[0];
                if (!empty($first_product['link'])) {
                    $details = $client->get_product_details($first_product['link']);
                    
                    $test_results[] = [
                        'subcategoria' => $term->name,
                        'produto_titulo' => $first_product['title'] ?? 'N/A',
                        'produto_sku' => $first_product['code'] ?? 'N/A',
                        'detalhes_encontrados' => count($details),
                        'campos_detalhes' => array_keys($details),
                        'imagens_encontradas' => isset($details['images']) ? count($details['images']) : 0,
                        'tem_technique' => isset($details['technique']),
                        'tem_frame' => isset($details['frame']),
                        'tem_size' => isset($details['size']),
                        'tem_artist' => isset($details['artist'])
                    ];
                    break; // Testa apenas um produto por subcategoria
                }
            }
        }
    }
    
    return $test_results;
}

/**
 * Endpoint AJAX para teste de processamento de detalhes
 */
add_action('wp_ajax_art_image_test_product_details', function() {
    check_ajax_referer('art_image_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sem permissão');
    }
    
    $results = art_image_test_product_details_processing();
    
    wp_send_json_success([
        'test_results' => $results,
        'timestamp' => current_time('mysql')
    ]);
}); 


// Adiciona parcelamento na listagem de produtos (shop/categoria)
add_action( 'woocommerce_after_shop_loop_item_title', 'pd_parcelamento_resumido', 20 );

// Shortcode para usar na página do produto: [parcelamento_produto]
add_shortcode( 'parcelamento_produto', 'pd_parcelamento_shortcode' );

/**
 * Exibe resumo de parcelamento sem juros (12x) e valor no PIX com 4% de desconto
 * na listagem (shop/categoria).
 */
function pd_parcelamento_resumido() {
    global $product;

    if ( ! $product || $product->get_price() <= 0 ) {
        return;
    }

    // Preço já no formato que a loja exibe (com/sem impostos conforme config)
    $price = wc_get_price_to_display( $product );

    // Configurações
    $max_installments = 12;
    $discount_pix_percent = 4; // 4% de desconto

    // Cálculos (sem juros)
    $valor_12x  = $price / $max_installments;
    $valor_pix  = $price * ( 1 - ( $discount_pix_percent / 100 ) );

    echo '<div class="pd-parcelamento-resumido">';
        echo '<div class="linha-parcelamento">Até ' . $max_installments . 'x de ' . wc_price( $valor_12x ) . ' s/ juros</div>';
        echo '<div class="linha-pix">Ou ' . wc_price( $valor_pix ) . ' no PIX/Boleto</div>';
    echo '</div>';
}

/**
 * Shortcode [parcelamento_produto] para exibir as mesmas informações na página de produto.
 */
function pd_parcelamento_shortcode( $atts ) {
    global $product;

    // Se não estiver setado, tenta pegar o produto atual (quando estiver em uma página de produto)
    if ( ! $product && is_product() ) {
        $product = wc_get_product( get_the_ID() );
    }

    if ( ! $product || $product->get_price() <= 0 ) {
        return '';
    }

    $price = wc_get_price_to_display( $product );

    // Configurações
    $max_installments = 12;
    $discount_pix_percent = 4; // 4% de desconto

    // Cálculos (sem juros)
    $valor_12x  = $price / $max_installments;
    $valor_pix  = $price * ( 1 - ( $discount_pix_percent / 100 ) );

    $output  = '<div class="pd-parcelamento-resumido shortcode-parcelamento">';
    $output .=     '<div class="linha-parcelamento">Até ' . $max_installments . 'x de <strong>' . wc_price( $valor_12x ) . '</strong> s/ juros</div>';
    $output .=     '<div class="linha-pix">Ou <strong>' . wc_price( $valor_pix ) . '</strong> no PIX</div>';
    $output .= '</div>';

    return $output;
}