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