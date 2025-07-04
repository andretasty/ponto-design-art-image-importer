<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manipuladores AJAX para importações manuais
 */

// Hook para conectar todos os handlers AJAX - Refatorado para batch
add_action('wp_ajax_art_image_import_categories', 'art_image_handle_categories_import');
add_action('wp_ajax_art_image_import_subcategories', 'art_image_handle_subcategories_import');
add_action('wp_ajax_art_image_import_artists', 'art_image_handle_artists_import');
add_action('wp_ajax_art_image_import_products', 'art_image_handle_products_import'); // Renomeado

add_action('wp_ajax_art_image_cancel_categories_import', 'art_image_handle_cancel_categories_import');
add_action('wp_ajax_art_image_cancel_subcategories_import', 'art_image_handle_cancel_subcategories_import');
add_action('wp_ajax_art_image_cancel_artists_import', 'art_image_handle_cancel_artists_import');
add_action('wp_ajax_art_image_cancel_products_import', 'art_image_handle_cancel_products_import');
add_action('wp_ajax_art_image_get_active_imports', 'art_image_handle_get_active_imports');
add_action('wp_ajax_art_image_prepare_product_import_queue_batch', 'art_image_handle_prepare_product_import_queue_batch');
add_action('wp_ajax_art_image_reschedule_all', 'art_image_handle_reschedule_all');

// Handlers de manutenção
add_action('wp_ajax_art_image_clear_locks', 'art_image_handle_clear_locks');
add_action('wp_ajax_art_image_clear_queue', 'art_image_handle_clear_queue');
add_action('wp_ajax_art_image_reset_all', 'art_image_handle_reset_all');
add_action('wp_ajax_art_image_force_sync', 'art_image_handle_force_sync');
add_action('wp_ajax_art_image_test_cron', 'art_image_handle_test_cron');
add_action('wp_ajax_art_image_cleanup_legacy', 'art_image_handle_cleanup_legacy');

function art_image_handle_get_active_imports() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $active_import = $importer->get_active_import_status();
    wp_send_json_success(['active_import_type' => $active_import]);
}

function art_image_handle_categories_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $result = $importer->import_categories($page); // Batch size is defaulted in the method
    wp_send_json_success($result);
}

function art_image_handle_subcategories_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $result = $importer->import_subcategories($page); // Batch size is defaulted
    wp_send_json_success($result);
}

function art_image_handle_artists_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $result = $importer->import_artists($page); // Batch size is defaulted
    wp_send_json_success($result);
}

function art_image_handle_products_import() { // Renamed from art_image_handle_batch_import
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    // Batch size is handled within import_products_batch (default 5, filterable)
    $batch_size = apply_filters('art_image_product_import_batch_size', 5);
    $result = $importer->import_products_batch($page, $batch_size);
    wp_send_json_success($result);
}

function art_image_handle_prepare_product_import_queue_batch() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $current_sub_index = isset($_POST['current_sub_index']) ? intval($_POST['current_sub_index']) : 0;
    $result = $importer->prepare_product_import_queue_batch($current_sub_index);
    wp_send_json_success($result);
}


// Cancellation Handlers
function art_image_handle_cancel_categories_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $importer->ajax_cancel_categories_import(); // This method now exists in importer.php
}

function art_image_handle_cancel_subcategories_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $importer->ajax_cancel_subcategories_import();
}

function art_image_handle_cancel_artists_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $importer->ajax_cancel_artists_import();
}

function art_image_handle_cancel_products_import() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();
    $importer->ajax_cancel_products_import();
}

/**
 * Handler para reagendar todos os eventos
 */
function art_image_handle_reschedule_all() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    try {
        $schedule_time = get_option('art_image_schedule_time', '02:00');
        
        // Remove eventos antigos do sistema legacy (cron.php)
        $timestamp = wp_next_scheduled('art_image_daily_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'art_image_daily_event');
            ArtImageTimezoneHelper::log_with_timezone('Removido evento legacy art_image_daily_event');
        }
        
        // Reagenda apenas o evento de sincronização completa (sync-manager.php)
        $result = ArtImageTimezoneHelper::reschedule_event('art_image_daily_sync', $schedule_time, 'daily');
        
        if ($result) {
            ArtImageTimezoneHelper::log_with_timezone('Todos os eventos reagendados via admin');
            wp_send_json_success(['message' => 'Eventos reagendados com sucesso!']);
        } else {
            wp_send_json_error(['message' => 'Erro ao reagendar evento de sincronização']);
        }
        
    } catch (Exception $e) {
        ArtImageTimezoneHelper::log_with_timezone('Erro ao reagendar eventos: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Erro: ' . $e->getMessage()]);
    }
}

/**
 * Handler para limpar locks de importação
 */
function art_image_handle_clear_locks() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    // Limpa todos os locks de transient
    delete_transient('artimage_master_import_lock');
    delete_transient('artimage_product_batch_lock');
    delete_transient('artimage_category_batch_lock');
    delete_transient('artimage_subcategory_batch_lock');
    delete_transient('artimage_artist_batch_lock');
    
    // Limpa flags de cancelamento
    delete_transient('artimage_cancel_product_import_flag');
    delete_transient('artimage_cancel_category_import_flag');
    delete_transient('artimage_cancel_subcategory_import_flag');
    delete_transient('artimage_cancel_artist_import_flag');
    
    // Limpa o lock do sync-manager
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
    $sync_manager = new ArtImageSyncManager();
    $sync_manager->force_clear_lock();
    
    ArtImageTimezoneHelper::log_with_timezone('Todos os locks de importação limpos via admin (incluindo sync-manager)');
    wp_send_json_success(['message' => 'Locks limpos com sucesso!']);
}

/**
 * Handler para limpar fila de produtos
 */
function art_image_handle_clear_queue() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    // Limpa fila e contadores
    delete_transient('artimage_product_import_queue');
    delete_transient('artimage_product_import_total');
    delete_transient('artimage_product_processed_count');
    delete_transient('artimage_product_subs_list');
    
    ArtImageTimezoneHelper::log_with_timezone('Fila de produtos limpa via admin');
    wp_send_json_success(['message' => 'Fila limpa com sucesso!']);
}

/**
 * Handler para reset completo
 */
function art_image_handle_reset_all() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    // Lista de todos os transients do plugin
    $transients = [
        // Locks
        'artimage_master_import_lock',
        'artimage_product_batch_lock',
        'artimage_category_batch_lock',
        'artimage_subcategory_batch_lock',
        'artimage_artist_batch_lock',
        
        // Filas
        'artimage_product_import_queue',
        'artimage_category_import_queue',
        'artimage_subcategory_import_queue',
        'artimage_artist_import_queue',
        'artimage_product_subs_list',
        
        // Contadores
        'artimage_product_import_total',
        'artimage_product_processed_count',
        'artimage_category_import_total',
        'artimage_category_processed_count',
        'artimage_subcategory_import_total',
        'artimage_subcategory_processed_count',
        'artimage_artist_import_total',
        'artimage_artist_processed_count',
        
        // Flags de cancelamento
        'artimage_cancel_product_import_flag',
        'artimage_cancel_category_import_flag',
        'artimage_cancel_subcategory_import_flag',
        'artimage_cancel_artist_import_flag',
    ];
    
    foreach ($transients as $transient) {
        delete_transient($transient);
    }
    
    ArtImageTimezoneHelper::log_with_timezone('Reset completo de importação realizado via admin');
    wp_send_json_success(['message' => 'Reset completo realizado!']);
}

/**
 * Handler para forçar execução da sincronização
 */
function art_image_handle_force_sync() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
    $manager = new ArtImageSyncManager();
    
    $manager->force_immediate_execution();
    
    ArtImageTimezoneHelper::log_with_timezone('Execução forçada da sincronização via admin');
    wp_send_json_success(['message' => 'Sincronização forçada iniciada! Verifique os logs.']);
}

/**
 * Handler para testar WP-Cron
 */
function art_image_handle_test_cron() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
    $manager = new ArtImageSyncManager();
    
    $result = $manager->test_wp_cron();
    
    if ($result) {
        ArtImageTimezoneHelper::log_with_timezone('Teste de WP-Cron bem-sucedido via admin');
        wp_send_json_success(['message' => 'WP-Cron está funcionando corretamente!']);
    } else {
        ArtImageTimezoneHelper::log_with_timezone('Teste de WP-Cron falhou via admin');
        wp_send_json_error(['message' => 'WP-Cron não está funcionando. Verifique os logs.']);
    }
}

/**
 * Handler para limpar eventos legacy
 */
function art_image_handle_cleanup_legacy() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }
    
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
    
    $result = ArtImageSyncManager::cleanup_legacy_events();
    
    ArtImageTimezoneHelper::log_with_timezone('Limpeza de eventos legacy executada via admin');
    wp_send_json_success(['message' => 'Eventos legacy removidos com sucesso!', 'details' => $result]);
}