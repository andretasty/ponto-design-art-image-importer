<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manipulador AJAX para importações manuais
 */

add_action('wp_ajax_art_image_manual_import', function () {
    check_ajax_referer('art_image_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    $type = sanitize_text_field($_POST['type'] ?? '');

    if (!in_array($type, ['categories', 'products', 'artists'])) {
        wp_send_json_error(['message' => 'Tipo de importação inválido.']);
    }

    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';

    switch ($type) {
        case 'categories':
            $message = art_image_import_categories();
            break;
        case 'products':
            $message = art_image_import_products();
            break;
        case 'artists':
            $message = art_image_import_artists();
            break;
    }

    wp_send_json_success(['message' => $message]);
});
