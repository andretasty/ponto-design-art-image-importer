<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Carrega arquivos essenciais do plugin
 */

// Admin interface
require_once ART_IMAGE_PLUGIN_DIR . 'admin/settings.php';
require_once ART_IMAGE_PLUGIN_DIR . 'admin/admin-ui.php';

// Importadores e utilidades
require_once ART_IMAGE_PLUGIN_DIR . 'includes/api-client.php';
require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
require_once ART_IMAGE_PLUGIN_DIR . 'includes/async-handler.php';

// Cron e agendamentos
require_once ART_IMAGE_PLUGIN_DIR . 'includes/cron.php';

add_action('init', function () {
    do_action('art_image_plugin_loaded');
});
