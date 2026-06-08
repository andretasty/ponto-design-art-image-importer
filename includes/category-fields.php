<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adiciona campos personalizados às categorias do WooCommerce
 */

/**
 * Obtém o link do vídeo de uma categoria
 * 
 * @param int $category_id ID da categoria
 * @return string Link do vídeo ou string vazia se não houver
 */
function art_image_get_category_video_link($category_id) {
    $video_link = get_term_meta($category_id, 'category_video_link', true);
    return $video_link ? esc_url($video_link) : '';
}

/**
 * Obtém o link do vídeo da primeira categoria de um produto
 * 
 * @param int $product_id ID do produto
 * @return string Link do vídeo ou string vazia se não houver
 */
function art_image_get_product_category_video_link($product_id) {
    $terms = get_the_terms($product_id, 'product_cat');
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $term) {
            $video_link = art_image_get_category_video_link($term->term_id);
            if (!empty($video_link)) {
                return $video_link;
            }
        }
    }
    return '';
}

/**
 * Renderiza campos personalizados na criação de categoria
 * Consolidado: margem, desconto e link de vídeo
 */
function art_image_category_add_fields() {
    ?>
    <div class="form-field">
        <label for="profit_margin"><?php _e('Margem de Lucro (%)', 'ponto-design-art-image-importer'); ?></label>
        <input type="number" name="profit_margin" id="profit_margin" min="0" step="0.01" />
        <p class="description"><?php _e('Margem de lucro específica para esta categoria. Deixe em branco para usar a margem global.', 'ponto-design-art-image-importer'); ?></p>
    </div>
    <div class="form-field">
        <label for="category_discount"><?php _e('Desconto (%)', 'ponto-design-art-image-importer'); ?></label>
        <input type="number" name="category_discount" id="category_discount" min="0" max="100" step="0.01" />
        <p class="description"><?php _e('Desconto percentual para esta categoria. Deixe em branco para usar o desconto geral.', 'ponto-design-art-image-importer'); ?></p>
    </div>
    <div class="form-field">
        <label for="category_video_link"><?php _e('Link do Vídeo', 'ponto-design-art-image-importer'); ?></label>
        <input type="url" name="category_video_link" id="category_video_link" placeholder="https://" />
        <p class="description"><?php _e('Link do vídeo relacionado a esta categoria (YouTube, Vimeo, etc.).', 'ponto-design-art-image-importer'); ?></p>
    </div>
    <?php
}
add_action('product_cat_add_form_fields', 'art_image_category_add_fields');

/**
 * Renderiza campos personalizados na edição de categoria
 * Consolidado: margem, desconto e link de vídeo
 *
 * @param WP_Term $term Objeto do termo
 */
function art_image_category_edit_fields($term) {
    $margin = get_term_meta($term->term_id, 'profit_margin', true);
    $discount = get_term_meta($term->term_id, 'category_discount', true);
    $video_link = get_term_meta($term->term_id, 'category_video_link', true);
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
    <tr class="form-field">
        <th scope="row">
            <label for="category_discount"><?php _e('Desconto (%)', 'ponto-design-art-image-importer'); ?></label>
        </th>
        <td>
            <input type="number" name="category_discount" id="category_discount" value="<?php echo esc_attr($discount); ?>" min="0" max="100" step="0.01" />
            <p class="description"><?php _e('Desconto percentual para esta categoria. Deixe em branco para usar o desconto geral.', 'ponto-design-art-image-importer'); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row">
            <label for="category_video_link"><?php _e('Link do Vídeo', 'ponto-design-art-image-importer'); ?></label>
        </th>
        <td>
            <input type="url" name="category_video_link" id="category_video_link" value="<?php echo esc_attr($video_link); ?>" placeholder="https://" />
            <p class="description"><?php _e('Link do vídeo relacionado a esta categoria (YouTube, Vimeo, etc.).', 'ponto-design-art-image-importer'); ?></p>
        </td>
    </tr>
    <?php
}
add_action('product_cat_edit_form_fields', 'art_image_category_edit_fields');

/**
 * Salva todos os campos personalizados da categoria
 * Consolidado: margem, desconto e link de vídeo
 *
 * @param int $term_id ID do termo
 */
function art_image_save_category_fields($term_id) {
    // Salva margem de lucro
    if (isset($_POST['profit_margin'])) {
        $margin = floatval(sanitize_text_field(wp_unslash($_POST['profit_margin'])));
        update_term_meta($term_id, 'profit_margin', $margin);
    }

    // Salva desconto
    if (isset($_POST['category_discount'])) {
        $discount = floatval(sanitize_text_field(wp_unslash($_POST['category_discount'])));
        update_term_meta($term_id, 'category_discount', $discount);
    }

    // Salva link de vídeo
    if (isset($_POST['category_video_link'])) {
        $video_link = esc_url_raw(wp_unslash($_POST['category_video_link']));
        update_term_meta($term_id, 'category_video_link', $video_link);
    }
}
add_action('created_product_cat', 'art_image_save_category_fields');
add_action('edited_product_cat', 'art_image_save_category_fields');

/**
 * Converte URL de vídeo para formato embed
 */
function art_image_convert_video_url_to_embed($video_link) {
    $embed_url = $video_link;
    
    // YouTube - formato watch
    if (strpos($video_link, 'youtube.com/watch') !== false) {
        parse_str(parse_url($video_link, PHP_URL_QUERY), $params);
        if (isset($params['v'])) {
            $video_id = $params['v'];
            $embed_url = 'https://www.youtube.com/embed/' . $video_id;
        }
    }
    // YouTube - formato curto
    elseif (strpos($video_link, 'youtu.be/') !== false) {
        $video_id = basename(parse_url($video_link, PHP_URL_PATH));
        $embed_url = 'https://www.youtube.com/embed/' . $video_id;
    }
    // Vimeo
    elseif (strpos($video_link, 'vimeo.com/') !== false) {
        $video_id = basename(parse_url($video_link, PHP_URL_PATH));
        $embed_url = 'https://player.vimeo.com/video/' . $video_id;
    }
    
    return $embed_url;
}

/**
 * Adiciona aba "Vídeo" nas abas de dados do produto
 */
add_filter('woocommerce_product_tabs', function($tabs) {
    global $product;
    
    if (!$product) {
        return $tabs;
    }
    
    // Obtém o link de vídeo da categoria do produto
    $video_link = art_image_get_product_category_video_link($product->get_id());
    
    // Só adiciona a aba se houver vídeo
    if (!empty($video_link)) {
        $tabs['video_categoria'] = array(
            'title'    => __('Vídeo', 'ponto-design-art-image-importer'),
            'priority' => 25, // Após descrição (20) e antes de informações adicionais (30)
            'callback' => 'art_image_product_video_tab_content'
        );
    }
    
    return $tabs;
});

/**
 * Conteúdo da aba de vídeo
 */
function art_image_product_video_tab_content() {
    global $product;
    
    if (!$product) {
        return;
    }
    
    $video_link = art_image_get_product_category_video_link($product->get_id());
    
    if (empty($video_link)) {
        echo '<p>' . __('Nenhum vídeo disponível para esta categoria.', 'ponto-design-art-image-importer') . '</p>';
        return;
    }
    
    $embed_url = art_image_convert_video_url_to_embed($video_link);
    
    echo '<div class="produto-video-categoria-tab">';
    
    // Se conseguiu converter para embed, mostra o iframe
    if ($embed_url !== $video_link) {
        echo '<div class="video-embed-container">';
        echo '<iframe src="' . esc_url($embed_url) . '" frameborder="0" allowfullscreen></iframe>';
        echo '</div>';
    } else {
        // Senão, mostra um link para o vídeo
        echo '<div class="video-link-container">';
        echo '<p>' . __('Assista ao vídeo relacionado a esta categoria:', 'ponto-design-art-image-importer') . '</p>';
        echo '<a href="' . esc_url($video_link) . '" target="_blank" class="btn-video-link">';
        echo '🎥 ' . __('Assistir Vídeo', 'ponto-design-art-image-importer');
        echo '</a>';
        echo '</div>';
    }
    
    echo '</div>';
}

/**
 * CSS para a aba de vídeo da categoria
 */
add_action('wp_head', function() {
    echo '<style>
    .produto-video-categoria-tab {
        padding: 20px 0;
    }
    
    .video-embed-container {
        position: relative;
        width: 100%;
        height: 0;
        padding-bottom: 56.25%; /* Aspect ratio 16:9 */
        margin-bottom: 20px;
    }
    
    .video-embed-container iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border: none;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }
    
    .video-link-container {
        text-align: center;
        padding: 40px 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 2px dashed #dee2e6;
    }
    
    .video-link-container p {
        margin-bottom: 20px;
        color: #6c757d;
        font-size: 16px;
    }
    
    .btn-video-link {
        display: inline-block;
        padding: 12px 30px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        text-decoration: none;
        border-radius: 25px;
        font-weight: 600;
        font-size: 16px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .btn-video-link:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .btn-video-link:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
    }
    
    /* Responsividade */
    @media (max-width: 768px) {
        .produto-video-categoria-tab {
            padding: 15px 0;
        }
        
        .video-link-container {
            padding: 30px 15px;
        }
        
        .btn-video-link {
            padding: 10px 25px;
            font-size: 14px;
        }
    }
    
    @media (max-width: 480px) {
        .video-link-container {
            padding: 20px 10px;
        }
        
        .btn-video-link {
            padding: 8px 20px;
            font-size: 13px;
        }
    }
    </style>';
});