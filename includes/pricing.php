<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciamento de preços com margem de lucro
 */

// Filtro para modificar o preço exibido apenas no frontend
add_filter('woocommerce_product_get_price', 'art_image_apply_profit_margin', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'art_image_apply_profit_margin', 10, 2);
add_filter('woocommerce_product_variation_get_price', 'art_image_apply_profit_margin', 10, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'art_image_apply_profit_margin', 10, 2);

/**
 * Aplica a margem de lucro ao preço do produto
 */
function art_image_apply_profit_margin($price, $product) {
    // Não aplica margem no backend
    if (is_admin() && !wp_doing_ajax()) {
        return $price;
    }

    // Não aplica margem em requisições AJAX do backend
    if (wp_doing_ajax() && isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'woocommerce') === 0) {
        return $price;
    }

    if (empty($price)) {
        return $price;
    }

    // Garante que o preço é float (corrige caso venha como string com vírgula)
    if (is_string($price)) {
        $price = str_replace(['R$', '.', ','], ['', '', '.'], $price);
    }
    $price = floatval($price);
    if ($price <= 0) {
        return $price;
    }

    // Aplica margem (categoria > global)
    $margin = null;
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $term) {
            $category_margin = get_term_meta($term->term_id, 'profit_margin', true);
            if ($category_margin !== '' && $category_margin !== null) {
                $margin = floatval($category_margin);
                break;
            }
        }
    }
    if ($margin === null) {
        $margin = floatval(get_option('art_image_profit_margin', 0));
    }
    $price_with_margin = $price * (1 + ($margin / 100));
    // Aplica desconto se ativado
    $enable_discount = get_option('art_image_enable_discount', '0');
    if ($enable_discount === '1') {
        $discount = null;
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $cat_discount = get_term_meta($term->term_id, 'category_discount', true);
                if ($cat_discount !== '' && $cat_discount !== null) {
                    $discount = floatval($cat_discount);
                    break;
                }
            }
        }
        if ($discount === null) {
            $discount = floatval(get_option('art_image_discount_percent', 0));
        }
        if ($discount > 0) {
            $price_with_margin = $price_with_margin * (1 - ($discount / 100));
        }
    }
    return round($price_with_margin, 2);
}