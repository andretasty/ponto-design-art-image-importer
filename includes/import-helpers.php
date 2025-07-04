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
    @ini_set('max_execution_time', 300);
    @ini_set('memory_limit', '256M');
    
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
 */
add_filter('art_image_debug_subcategory_limit', function() {
    return 5; 
}); 