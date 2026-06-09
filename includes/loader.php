<?php
/**
 * Carregador principal do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Carrega o sistema de logging primeiro (dependência de todos os outros)
require_once ART_IMAGE_PLUGIN_DIR . 'includes/logger.php';

// Rastreamento de falhas de produto
require_once ART_IMAGE_PLUGIN_DIR . 'includes/failed-products.php';

// Carrega a API
require_once ART_IMAGE_PLUGIN_DIR . 'includes/api-client.php';

// Carrega funções auxiliares de fuso horário
require_once ART_IMAGE_PLUGIN_DIR . 'includes/timezone-helper.php';

// Carrega o gerenciador do Action Scheduler (requer WooCommerce)
require_once ART_IMAGE_PLUGIN_DIR . 'includes/action-scheduler-manager.php';

// Carrega o gerenciador de sincronização
require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';

// Nota: dedicated-cron.php foi descontinuado (sistema de cron redundante). O
// processamento agora é único: Action Scheduler + cron de servidor
// "wp action-scheduler run". Não carregar para evitar cron concorrente.

// Carrega o importador
require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';

// Carrega o gerenciador de preços
require_once ART_IMAGE_PLUGIN_DIR . 'includes/pricing.php';

// Carrega o gerenciador de preços
require_once ART_IMAGE_PLUGIN_DIR . 'includes/async-handler.php';

// Carrega funções auxiliares de importação
require_once ART_IMAGE_PLUGIN_DIR . 'includes/import-helpers.php';

// Carrega sistema de tracking de sincronização e o inicializa
require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-tracker.php';
add_action('art_image_loaded', 'art_image_init_sync_tracker', 5);

function art_image_init_sync_tracker() {
    global $artimage_sync_tracker;
    $artimage_sync_tracker = new ArtImageSyncTracker();
}

// Carrega os campos personalizados
require_once ART_IMAGE_PLUGIN_DIR . 'includes/product-fields.php';
require_once ART_IMAGE_PLUGIN_DIR . 'includes/category-fields.php';
require_once ART_IMAGE_PLUGIN_DIR . 'includes/post-types.php';

// Carrega a interface administrativa
require_once ART_IMAGE_PLUGIN_DIR . 'admin/admin-ui.php';
require_once ART_IMAGE_PLUGIN_DIR . 'admin/settings.php';

// Inicializa o plugin
do_action('art_image_loaded');

// === Hardening de importação (causa #3: ações da fase de produtos estouravam
// o timeout de 300s do Action Scheduler e eram marcadas como falha) ===
// Dá mais folga ao Action Scheduler antes de considerar uma ação morta (padrão 300s).
// Reduz o trabalho por ação para a fase de produtos terminar bem abaixo do limite.
add_filter('art_image_product_import_batch_size', function() { return 5; });
add_filter('art_image_download_timeout', function() { return 20; });
add_filter('art_image_api_timeout', function() { return 20; });

// Bloqueia DEFINITIVAMENTE o agendamento do evento WP-Cron legado art_image_sync_event.
// Vários pontos do plugin (init, check_and_migrate, timezone-helper) tentavam reagendá-lo,
// gerando um segundo gatilho de sync semanal em conflito com o Action Scheduler.
// O agendamento agora é único: Action Scheduler (artimage_scheduled_sync), processado
// por um cron de servidor "wp action-scheduler run".
add_filter('schedule_event', function($event) {
    if (is_object($event) && isset($event->hook) && $event->hook === 'art_image_sync_event') {
        return false;
    }
    return $event;
});

// Garante a tabela de falhas (idempotente; cobre plugin já ativo em produção)
add_action('init', ['ArtImageFailedProducts', 'maybe_create_table']);

// Captura lotes de produto que morrem (300s/fatal) no Action Scheduler
add_action('action_scheduler_failed_execution', ['ArtImageASManager', 'on_action_failed'], 10, 1);