<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciamento de preços com margem de lucro e desconto por categoria
 */

// Filtros para preços regulares (base + margem, sem desconto)
add_filter('woocommerce_product_get_regular_price', 'art_image_apply_regular_price', 10, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'art_image_apply_regular_price', 10, 2);

// Filtros para preços promocionais (base + margem - desconto categoria)
add_filter('woocommerce_product_get_sale_price', 'art_image_apply_sale_price', 10, 2);
add_filter('woocommerce_product_variation_get_sale_price', 'art_image_apply_sale_price', 10, 2);

// Filtros para preço final
add_filter('woocommerce_product_get_price', 'art_image_apply_final_price', 10, 2);
add_filter('woocommerce_product_variation_get_price', 'art_image_apply_final_price', 10, 2);

/**
 * Aplica margem ao preço regular (sem desconto da categoria)
 */
function art_image_apply_regular_price($price, $product) {
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

    // Pega o preço base original
    $base_price = art_image_get_base_price($product);
    if ($base_price <= 0) {
        return $price;
    }

    // Aplica apenas a margem
    $margin = art_image_get_margin($product);
    $price_with_margin = $base_price * (1 + ($margin / 100));
    
    return round($price_with_margin, 2);
}

/**
 * Aplica margem + desconto da categoria ao preço promocional
 */
function art_image_apply_sale_price($price, $product) {
    // Não aplica no backend
    if (is_admin() && !wp_doing_ajax()) {
        return $price;
    }

    // Não aplica em requisições AJAX do backend
    if (wp_doing_ajax() && isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'woocommerce') === 0) {
        return $price;
    }

    // Verifica se desconto está habilitado globalmente
    $enable_discount = get_option('art_image_enable_discount', '0');
    if ($enable_discount !== '1') {
        return ''; // Sem desconto global, sem preço promocional
    }

    // Verifica se há desconto configurado para este produto
    $discount = art_image_get_discount($product);
    if ($discount <= 0) {
        return ''; // Sem desconto da categoria, sem preço promocional
    }

    // Pega o preço base original
    $base_price = art_image_get_base_price($product);
    if ($base_price <= 0) {
        return '';
    }

    // Aplica margem
    $margin = art_image_get_margin($product);
    $price_with_margin = $base_price * (1 + ($margin / 100));
    
    // Aplica desconto da categoria
    $price_with_discount = $price_with_margin * (1 - ($discount / 100));
    
    return round($price_with_discount, 2);
}

/**
 * Define o preço final (promocional se existir, senão regular)
 */
function art_image_apply_final_price($price, $product) {
    // Não aplica no backend
    if (is_admin() && !wp_doing_ajax()) {
        return $price;
    }

    // Pega o preço base original
    $base_price = art_image_get_base_price($product);
    if ($base_price <= 0) {
        return $price;
    }

    // Aplica margem para ter o preço regular
    $margin = art_image_get_margin($product);
    $regular_price = $base_price * (1 + ($margin / 100));
    $regular_price = round($regular_price, 2);

    // Verifica se desconto está habilitado globalmente
    $enable_discount = get_option('art_image_enable_discount', '0');
    if ($enable_discount !== '1') {
        return $regular_price; // Desconto desabilitado globalmente, usa preço regular
    }

    // Verifica se há desconto configurado para este produto
    $discount = art_image_get_discount($product);
    if ($discount <= 0) {
        return $regular_price; // Sem desconto da categoria, usa preço regular
    }

    // Aplica desconto da categoria
    $sale_price = $regular_price * (1 - ($discount / 100));
    return round($sale_price, 2);
}

/**
 * Obtém o preço base do produto (preço cadastrado, sem margem e sem desconto)
 */
function art_image_get_base_price($product) {
    // Pega o preço diretamente do banco, sem filtros
    if ($product->is_type('variation')) {
        $base_price = get_post_meta($product->get_id(), '_regular_price', true);
        if (empty($base_price)) {
            $base_price = get_post_meta($product->get_id(), '_price', true);
        }
    } else {
        $base_price = get_post_meta($product->get_id(), '_regular_price', true);
        if (empty($base_price)) {
            $base_price = get_post_meta($product->get_id(), '_price', true);
        }
    }
    
    return art_image_clean_brazilian_price($base_price);
}

/**
 * Limpa e converte preços brasileiros para float
 */
function art_image_clean_brazilian_price($price) {
    // Se já é um número, retorna
    if (is_numeric($price)) {
        return floatval($price);
    }
    
    // Se não é string, tenta converter
    if (!is_string($price)) {
        return floatval($price);
    }
    
    // Remove espaços
    $price = trim($price);
    
    // Se está vazio, retorna 0
    if (empty($price)) {
        return 0;
    }
    
    // Remove símbolos de moeda
    $price = preg_replace('/[R$\s]/', '', $price);
    
    // Detecta formato brasileiro vs formato americano
    if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$/', $price)) {
        // Formato brasileiro com separador de milhares: 1.234,56
        $price = str_replace('.', '', $price); // Remove separadores de milhares
        $price = str_replace(',', '.', $price); // Converte decimal
    } elseif (preg_match('/^\d+,\d{1,2}$/', $price)) {
        // Formato brasileiro sem separador de milhares: 1234,56
        $price = str_replace(',', '.', $price);
    } elseif (preg_match('/^\d{1,3}(,\d{3})*\.\d{1,2}$/', $price)) {
        // Formato americano com separador de milhares: 1,234.56
        $price = str_replace(',', '', $price); // Remove separadores de milhares
    }
    
    return floatval($price);
}

/**
 * Obtém a margem de lucro (categoria > global)
 */
function art_image_get_margin($product) {
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
    
    return $margin;
}

/**
 * Obtém o desconto da categoria (somente categoria, não global)
 */
function art_image_get_discount($product) {
    $terms = get_the_terms($product->get_id(), 'product_cat');
    
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $term) {
            $cat_discount = get_term_meta($term->term_id, 'category_discount', true);
            // Verifica se existe o meta e se é maior que 0
            if ($cat_discount !== '' && $cat_discount !== null) {
                $discount_value = floatval($cat_discount);
                if ($discount_value > 0) {
                    return $discount_value;
                }
            }
        }
    }
    
    return 0; // Retorna 0 se não encontrou desconto válido
}