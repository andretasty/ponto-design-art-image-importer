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
add_action('wp_ajax_art_image_retry_failed_products', 'art_image_handle_retry_failed_products');

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
        
        // Reagenda usando o novo sistema
        require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
        $result = ArtImageSyncManager::schedule_sync_event();
        
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

    // Usa função centralizada para limpar locks e flags
    art_image_clear_transients(['locks', 'flags']);

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

    // Usa função centralizada para limpar filas e contadores
    art_image_clear_transients(['queues', 'counters']);

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

    // Usa função centralizada para limpar todos os transients
    $deleted = art_image_clear_transients('all');

    ArtImageTimezoneHelper::log_with_timezone("Reset completo de importação realizado via admin ({$deleted} transients removidos)");
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

    $result = ArtImageSyncManager::cleanup_all_legacy_events();
    ArtImageSyncManager::schedule_sync_event();

    ArtImageTimezoneHelper::log_with_timezone('Limpeza de eventos legacy executada via admin');
    wp_send_json_success(['message' => 'Eventos legacy removidos com sucesso!', 'details' => $result]);
}

// =========================================================================
// HANDLERS AJAX PARA ACTION SCHEDULER
// =========================================================================

add_action('wp_ajax_art_image_as_get_progress', 'art_image_handle_as_get_progress');
add_action('wp_ajax_art_image_as_start_sync', 'art_image_handle_as_start_sync');
add_action('wp_ajax_art_image_as_cancel', 'art_image_handle_as_cancel');
add_action('wp_ajax_art_image_as_retry_failed', 'art_image_handle_as_retry_failed');
add_action('wp_ajax_art_image_as_get_failed', 'art_image_handle_as_get_failed');
add_action('wp_ajax_art_image_as_cleanup', 'art_image_handle_as_cleanup');

/**
 * Retorna o progresso atual da sincronização via Action Scheduler
 */
function art_image_handle_as_get_progress() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    if (!ArtImageASManager::is_available()) {
        wp_send_json_error(['message' => 'Action Scheduler não disponível.']);
    }

    $progress = ArtImageASManager::get_progress();

    // Adicionar próxima sincronização agendada
    $progress['next_scheduled'] = ArtImageASManager::get_next_scheduled_sync();
    $progress['using_action_scheduler'] = true;

    wp_send_json_success($progress);
}

/**
 * Inicia sincronização via Action Scheduler
 */
function art_image_handle_as_start_sync() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    if (!ArtImageASManager::is_available()) {
        wp_send_json_error(['message' => 'Action Scheduler não disponível.']);
    }

    // Verificar se já há sincronização em andamento
    if (ArtImageASManager::is_sync_running()) {
        wp_send_json_error(['message' => 'Já existe uma sincronização em andamento.']);
    }

    require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
    $manager = new ArtImageSyncManager();
    $manager->as_trigger_sync_start();

    ArtImageTimezoneHelper::log_with_timezone('[AS] Sincronização iniciada via admin AJAX');

    // Forçar processamento imediato das actions pendentes
    // Isso inicia a primeira fase sem esperar pelo próximo cron
    $processed = ArtImageASManager::run_pending_actions(5);
    ArtImageTimezoneHelper::log_with_timezone("[AS] Actions processadas imediatamente: {$processed}");

    wp_send_json_success([
        'message' => 'Sincronização iniciada via Action Scheduler!',
        'session_id' => get_option('artimage_as_session_id'),
        'actions_processed' => $processed
    ]);
}

/**
 * Cancela todas as actions pendentes do Action Scheduler
 */
function art_image_handle_as_cancel() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    if (!ArtImageASManager::is_available()) {
        wp_send_json_error(['message' => 'Action Scheduler não disponível.']);
    }

    $cancelled = ArtImageASManager::cancel_all();

    ArtImageTimezoneHelper::log_with_timezone("[AS] Canceladas {$cancelled} actions via admin");

    wp_send_json_success([
        'message' => "Canceladas {$cancelled} actions pendentes.",
        'cancelled' => $cancelled
    ]);
}

/**
 * Retenta todas as actions falhas
 */
function art_image_handle_as_retry_failed() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    if (!ArtImageASManager::is_available()) {
        wp_send_json_error(['message' => 'Action Scheduler não disponível.']);
    }

    $retried = ArtImageASManager::retry_failed();

    ArtImageTimezoneHelper::log_with_timezone("[AS] Retentadas {$retried} actions via admin");

    wp_send_json_success([
        'message' => "Retentadas {$retried} actions falhas.",
        'retried' => $retried
    ]);
}

/**
 * Retorna lista de actions falhas para diagnóstico
 */
function art_image_handle_as_get_failed() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    if (!ArtImageASManager::is_available()) {
        wp_send_json_error(['message' => 'Action Scheduler não disponível.']);
    }

    $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
    $failed = ArtImageASManager::get_failed_actions($limit);

    wp_send_json_success([
        'failed_actions' => $failed,
        'count' => count($failed)
    ]);
}

/**
 * Limpa actions antigas completadas
 */
function art_image_handle_as_cleanup() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    if (!ArtImageASManager::is_available()) {
        wp_send_json_error(['message' => 'Action Scheduler não disponível.']);
    }

    $days = isset($_POST['days']) ? absint($_POST['days']) : 7;
    $deleted = ArtImageASManager::cleanup_completed($days);

    ArtImageTimezoneHelper::log_with_timezone("[AS] Limpas {$deleted} actions completadas via admin");

    wp_send_json_success([
        'message' => "Removidas {$deleted} actions completadas antigas.",
        'deleted' => $deleted
    ]);
}

/**
 * Retry manual de produtos com falha (aba Falhas)
 */
function art_image_handle_retry_failed_products() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sem permissão']);
    }
    // Retry manual: force = true (ignora o limite de 3 tentativas)
    $rows = ArtImageFailedProducts::get_retryable(true);
    $count = ArtImageASManager::schedule_retry_batches($rows);
    wp_send_json_success([
        'message' => "{$count} produto(s) re-enfileirado(s). Serão processados pelo cron.",
        'count' => $count,
    ]);
}