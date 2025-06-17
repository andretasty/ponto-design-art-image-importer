<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adiciona campos personalizados às categorias do WooCommerce
 */

// Adiciona campo de margem na edição de categoria
add_action('product_cat_add_form_fields', function() {
    ?>
    <div class="form-field">
        <label for="profit_margin"><?php _e('Margem de Lucro (%)', 'ponto-design-art-image-importer'); ?></label>
        <input type="number" name="profit_margin" id="profit_margin" min="0" step="0.01" />
        <p class="description"><?php _e('Margem de lucro específica para esta categoria. Deixe em branco para usar a margem global.', 'ponto-design-art-image-importer'); ?></p>
    </div>
    <?php
});

// Adiciona campo de margem na edição de categoria existente
add_action('product_cat_edit_form_fields', function($term) {
    $margin = get_term_meta($term->term_id, 'profit_margin', true);
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="profit_margin"><?php _e('Margem de Lucro (%)', 'ponto-design-art-image-importer'); ?></label>
        </th>
        <td>
            <input type="number" name="profit_margin" id="profit_margin" value="<?php echo esc_attr($margin); ?>" min="0" step="0.01" />
            <p class="description"><?php _e('Margem de lucro específica para esta categoria. Deixe em branco para usar a margem global.', 'ponto-design-art-image-importer'); ?></p>
        </td>
    </tr>
    <?php
});

// Salva o campo de margem
add_action('created_product_cat', function($term_id) {
    if (isset($_POST['profit_margin'])) {
        $margin = floatval($_POST['profit_margin']);
        update_term_meta($term_id, 'profit_margin', $margin);
    }
});

add_action('edited_product_cat', function($term_id) {
    if (isset($_POST['profit_margin'])) {
        $margin = floatval($_POST['profit_margin']);
        update_term_meta($term_id, 'profit_margin', $margin);
    }
});

// Adiciona campo de desconto na edição de categoria
add_action('product_cat_add_form_fields', function() {
    ?>
    <div class="form-field">
        <label for="category_discount"><?php _e('Desconto (%)', 'ponto-design-art-image-importer'); ?></label>
        <input type="number" name="category_discount" id="category_discount" min="0" max="100" step="0.01" />
        <p class="description"><?php _e('Desconto percentual para esta categoria. Deixe em branco para usar o desconto geral.', 'ponto-design-art-image-importer'); ?></p>
    </div>
    <?php
});

add_action('product_cat_edit_form_fields', function($term) {
    $discount = get_term_meta($term->term_id, 'category_discount', true);
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="category_discount"><?php _e('Desconto (%)', 'ponto-design-art-image-importer'); ?></label>
        </th>
        <td>
            <input type="number" name="category_discount" id="category_discount" value="<?php echo esc_attr($discount); ?>" min="0" max="100" step="0.01" />
            <p class="description"><?php _e('Desconto percentual para esta categoria. Deixe em branco para usar o desconto geral.', 'ponto-design-art-image-importer'); ?></p>
        </td>
    </tr>
    <?php
});

// Salva o campo de desconto
add_action('created_product_cat', function($term_id) {
    if (isset($_POST['category_discount'])) {
        $discount = floatval($_POST['category_discount']);
        update_term_meta($term_id, 'category_discount', $discount);
    }
});

add_action('edited_product_cat', function($term_id) {
    if (isset($_POST['category_discount'])) {
        $discount = floatval($_POST['category_discount']);
        update_term_meta($term_id, 'category_discount', $discount);
    }
}); 