<?php
/**
 * Funções auxiliares para melhorar o sistema de importação
 */

if (!defined('ABSPATH')) {
    exit;
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
 */
function art_image_clear_import_state() {
    $transients = [
        'artimage_product_import_queue',
        'artimage_product_import_total',
        'artimage_product_processed_count',
        'artimage_master_import_lock',
        'artimage_product_batch_lock',
        'artimage_cancel_product_import_flag',
        'artimage_product_subs_list'
    ];
    
    foreach ($transients as $transient) {
        delete_transient($transient);
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
 * MODO DE TESTE: Limita o número de subcategorias para a importação de produtos.
 * Remova ou comente esta linha para importar todas as subcategorias.
 * REMOVIDO PARA PERMITIR IMPORTAÇÃO COMPLETA
 */
// add_filter('art_image_debug_subcategory_limit', function() {
//     return 1; 
// });

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

function pd_parcelamento_resumido() {
	global $product;
	if ( ! $product || $product->get_price() <= 0 ) {
		return;
	}
	$price = wc_get_price_to_display( $product );
	
	// 3x sem juros
	$valor_3x = $price / 3;
	
	// até 12x com juros (2,99% fixa, mais 1,7% por mês)
	$juros_fixo   = 0.0299;
	$juros_mensal = 0.017;
	$parcelas_max = 12;
	$total_com_juros = $price * ( 1 + $juros_fixo ) * ( 1 + $juros_mensal * $parcelas_max );
	$valor_12x       = $total_com_juros / $parcelas_max;
	
	echo '<div class="pd-parcelamento-resumido">';
	echo '<div class="linha-parcelamento primeira">Até 3x de ' . wc_price( $valor_3x ) . ' s/ juros</div>';
	echo '<div class="linha-parcelamento segunda">Ou até ' . $parcelas_max . 'x de ' . wc_price( $valor_12x ) . '</div>';
	echo '</div>';
}

function pd_parcelamento_shortcode( $atts ) {
	global $product;
	
	// Se não estiver na página de produto, tenta pegar o produto atual
	if ( ! $product && is_product() ) {
		$product = wc_get_product( get_the_ID() );
	}
	
	if ( ! $product || $product->get_price() <= 0 ) {
		return '';
	}
	
	$price = wc_get_price_to_display( $product );
	
	// 3x sem juros
	$valor_3x = $price / 3;
	
	// até 12x com juros (2,99% fixa, mais 1,7% por mês)
	$juros_fixo   = 0.0299;
	$juros_mensal = 0.017;
	$parcelas_max = 12;
	$total_com_juros = $price * ( 1 + $juros_fixo ) * ( 1 + $juros_mensal * $parcelas_max );
	$valor_12x       = $total_com_juros / $parcelas_max;
	
	$output = '<div class="pd-parcelamento-resumido shortcode-parcelamento">';
	$output .= '<div class="linha-parcelamento primeira">Até 3x de <strong>' . wc_price( $valor_3x ) . '</strong> s/ juros</div>';
	$output .= '<div class="linha-parcelamento segunda">Ou até ' . $parcelas_max . 'x de <strong>' . wc_price( $valor_12x ) . '</strong></div>';
	$output .= '</div>';
	
	return $output;
}