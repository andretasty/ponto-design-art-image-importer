<?php
/**
 * Carregador principal do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Carrega a API primeiro
require_once ART_IMAGE_PLUGIN_DIR . 'includes/api-client.php';

// Carrega funções auxiliares de fuso horário
require_once ART_IMAGE_PLUGIN_DIR . 'includes/timezone-helper.php';

// Carrega o gerenciador de sincronização
require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';

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