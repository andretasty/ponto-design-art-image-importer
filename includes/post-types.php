<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registro de Custom Post Types
 */

add_action('init', function () {
    // Register Custom Taxonomy for Artists
    $labels = [
        'name'              => _x('Artistas', 'taxonomy general name', 'ponto-design-art-image-importer'),
        'singular_name'     => _x('Artista', 'taxonomy singular name', 'ponto-design-art-image-importer'),
        'search_items'      => __('Buscar Artistas', 'ponto-design-art-image-importer'),
        'all_items'         => __('Todos os Artistas', 'ponto-design-art-image-importer'),
        'parent_item'       => __('Artista Pai', 'ponto-design-art-image-importer'),
        'parent_item_colon' => __('Artista Pai:', 'ponto-design-art-image-importer'),
        'edit_item'         => __('Editar Artista', 'ponto-design-art-image-importer'),
        'update_item'       => __('Atualizar Artista', 'ponto-design-art-image-importer'),
        'add_new_item'      => __('Adicionar Novo Artista', 'ponto-design-art-image-importer'),
        'new_item_name'     => __('Nome do Novo Artista', 'ponto-design-art-image-importer'),
        'menu_name'         => __('Artistas', 'ponto-design-art-image-importer'),
    ];

    $args = [
        'hierarchical'      => false, // Set to true if you want a category-like structure
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => ['slug' => 'artista'], // URL slug for the artist taxonomy
        'show_in_rest'      => true, // Enable for Gutenberg and REST API
    ];

    register_taxonomy('artist', ['product'], $args); // Associate with 'product' post type
});