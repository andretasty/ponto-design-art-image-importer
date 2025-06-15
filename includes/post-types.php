<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registro de Custom Post Types
 */

add_action('init', function () {
    register_post_type('artists', [
        'labels' => [
            'name'               => 'Artistas',
            'singular_name'      => 'Artista',
            'menu_name'          => 'Artistas',
            'add_new'            => 'Adicionar Novo',
            'add_new_item'       => 'Adicionar Novo Artista',
            'edit_item'          => 'Editar Artista',
            'new_item'           => 'Novo Artista',
            'view_item'          => 'Ver Artista',
            'search_items'       => 'Buscar Artistas',
            'not_found'          => 'Nenhum artista encontrado',
            'not_found_in_trash' => 'Nenhum artista encontrado na lixeira',
        ],
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'query_var'           => true,
        'rewrite'             => ['slug' => 'artista'],
        'capability_type'     => 'post',
        'has_archive'         => true,
        'hierarchical'        => false,
        'menu_position'       => 25,
        'menu_icon'           => 'dashicons-admin-users',
        'supports'            => ['title', 'editor', 'thumbnail'],
        'show_in_rest'        => true, // Para Gutenberg
    ]);
});