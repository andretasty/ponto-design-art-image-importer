<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manipuladores AJAX para importações manuais
 */

// Hook para conectar todos os handlers AJAX
add_action('wp_ajax_art_image_manual_import', 'art_image_handle_manual_import');
add_action('wp_ajax_art_image_batch_import', 'art_image_handle_batch_import');
add_action('wp_ajax_art_image_cancel_import', 'art_image_handle_cancel_import');

/**
 * Handler principal para importações simples
 */
function art_image_handle_manual_import() {
    check_ajax_referer('art_image_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    $type = sanitize_text_field($_POST['type'] ?? '');

    if (!in_array($type, ['categories', 'subcategories', 'artists'])) {
        wp_send_json_error(['message' => 'Tipo de importação inválido.']);
    }

    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();

    switch ($type) {
        case 'categories':
            $result = $importer->import_categories();
            wp_send_json_success($result);
            break;
            
        case 'subcategories':
            $result = $importer->import_subcategories();
            wp_send_json_success($result);
            break;
            
        case 'artists':
            $result = $importer->import_artists();
            wp_send_json_success($result);
            break;
    }
}

/**
 * Handler para importação em lotes (produtos)
 */
function art_image_handle_batch_import() {
    check_ajax_referer('art_image_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    $type = sanitize_text_field($_POST['type'] ?? '');
    $page = absint($_POST['page'] ?? 1);

    if ($type !== 'products') {
        wp_send_json_error(['message' => 'Tipo de importação em lote inválido.']);
    }

    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    $importer = new ArtImageImporter();

    $batch_size = apply_filters('art_image_product_import_batch_size', 5);
    $result = $importer->import_products_batch($page, $batch_size);
    
    wp_send_json_success($result);
}

/**
 * Handler para cancelar importação
 */
function art_image_handle_cancel_import() {
    check_ajax_referer('art_image_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permissão negada.']);
    }

    // Define flag de cancelamento
    set_transient('artimage_cancel_import_flag', 1, 5 * MINUTE_IN_SECONDS);
    
    wp_send_json_success(['message' => 'Solicitação de cancelamento processada.']);
}