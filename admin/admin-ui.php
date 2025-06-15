<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface administrativa do plugin
 */

add_action('admin_menu', function () {
    add_menu_page(
        'Art Image',
        'Art Image',
        'manage_options',
        'art-image',
        'art_image_render_admin_page',
        'dashicons-art',
        56
    );
});

function art_image_render_admin_page() {
    $active_tab = $_GET['tab'] ?? 'settings';

    echo '<div class="wrap">';
    echo '<h1>Art Image</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=art-image&tab=settings" class="nav-tab ' . ($active_tab === 'settings' ? 'nav-tab-active' : '') . '">Configurações</a>';
    echo '<a href="?page=art-image&tab=manual_import" class="nav-tab ' . ($active_tab === 'manual_import' ? 'nav-tab-active' : '') . '">Importação Manual</a>';
    echo '</h2>';

    if ($active_tab === 'settings') {
        echo '<form method="post" action="options.php">';
        settings_fields('art_image_settings_group');
        do_settings_sections('art_image_settings');
        submit_button();
        echo '</form>';
    }

    if ($active_tab === 'manual_import') {
        echo '<p>Botões para importar manualmente cada item, com log em tempo real.</p>';
        echo '<div id="manual-import-section">';
        echo '<button class="button" data-type="categories">Importar Categorias</button> ';
        echo '<button class="button" data-type="products">Importar Produtos</button> ';
        echo '<button class="button" data-type="artists">Importar Artistas</button>';
        echo '<pre id="import-log" style="background: #111; color: #0f0; padding: 10px; margin-top: 15px; height: 300px; overflow: auto;"></pre>';
        echo '</div>';
    }

    echo '</div>';
}

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_art-image') return;

    wp_enqueue_script(
        'art-image-import-logs',
        ART_IMAGE_PLUGIN_URL . 'admin/assets/js/import-logs.js',
        [],
        ART_IMAGE_VERSION,
        true
    );

    wp_localize_script('art-image-import-logs', 'art_image_ajax', [
        'nonce' => wp_create_nonce('art_image_nonce')
    ]);
});