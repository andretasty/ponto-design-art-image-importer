<?php
/**
 * Funções auxiliares para melhorar o sistema de importação
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hook para aumentar limites do PHP durante importação
 */
add_action('art_image_before_import', function() {
    // Aumenta o tempo limite de execução
    @set_time_limit(300); // 5 minutos
    
    // Aumenta o limite de memória
    @ini_set('memory_limit', '512M');
    
    // Desabilita cache de objetos durante importação para economizar memória
    wp_suspend_cache_addition(true);
});

/**
 * Hook após importação para restaurar configurações
 */
add_action('art_image_after_import', function() {
    // Restaura cache de objetos
    wp_suspend_cache_addition(false);
});

/**
 * Filtro para reduzir o batch size de produtos
 */
add_filter('art_image_product_import_batch_size', function($size) {
    // Reduz para 3 produtos por vez para evitar timeouts
    return 3;
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