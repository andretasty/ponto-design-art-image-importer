<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adiciona campos personalizados aos produtos do WooCommerce
 */

// Adiciona campos personalizados na aba "Dados do Produto"
add_action('woocommerce_product_options_general_product_data', function() {
    global $woocommerce, $post;

    echo '<div class="options_group">';
    
    // Campo de Tamanho
    woocommerce_wp_text_input([
        'id' => '_size',
        'label' => __('Tamanho', 'ponto-design-art-image-importer'),
        'placeholder' => __('Ex: 50x70cm', 'ponto-design-art-image-importer'),
        'desc_tip' => true,
        'description' => __('Tamanho da obra de arte', 'ponto-design-art-image-importer')
    ]);

    // Campo de Técnica
    woocommerce_wp_text_input([
        'id' => '_technique',
        'label' => __('Técnica', 'ponto-design-art-image-importer'),
        'placeholder' => __('Ex: Óleo sobre tela', 'ponto-design-art-image-importer'),
        'desc_tip' => true,
        'description' => __('Técnica utilizada na obra', 'ponto-design-art-image-importer')
    ]);

    // Campo de Moldura
    woocommerce_wp_text_input([
        'id' => '_frame',
        'label' => __('Moldura', 'ponto-design-art-image-importer'),
        'placeholder' => __('Ex: Madeira natural', 'ponto-design-art-image-importer'),
        'desc_tip' => true,
        'description' => __('Detalhes da moldura', 'ponto-design-art-image-importer')
    ]);

    echo '</div>';
});

// Salva os campos personalizados
add_action('woocommerce_process_product_meta', function($post_id) {
    $fields = ['_size', '_technique', '_frame'];
    
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }
    }
});

// Adiciona os campos na exibição do produto
add_action('woocommerce_single_product_summary', function() {
    global $product;
    
    $size = get_post_meta($product->get_id(), '_size', true);
    $technique = get_post_meta($product->get_id(), '_technique', true);
    $frame = get_post_meta($product->get_id(), '_frame', true);
    
    if ($size || $technique || $frame) {
        echo '<div class="artwork-details">';
        if ($size) {
            echo '<p class="artwork-size"><strong>' . __('Tamanho:', 'ponto-design-art-image-importer') . '</strong> ' . esc_html($size) . '</p>';
        }
        if ($technique) {
            echo '<p class="artwork-technique"><strong>' . __('Técnica:', 'ponto-design-art-image-importer') . '</strong> ' . esc_html($technique) . '</p>';
        }
        if ($frame) {
            echo '<p class="artwork-frame"><strong>' . __('Moldura:', 'ponto-design-art-image-importer') . '</strong> ' . esc_html($frame) . '</p>';
        }
        echo '</div>';
    }
}, 25); 