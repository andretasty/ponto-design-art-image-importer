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
    echo '<a href="?page=art-image&tab=discounts" class="nav-tab ' . ($active_tab === 'discounts' ? 'nav-tab-active' : '') . '">Descontos</a>';
    echo '<a href="?page=art-image&tab=manual_import" class="nav-tab ' . ($active_tab === 'manual_import' ? 'nav-tab-active' : '') . '">Importação Manual</a>';
    echo '</h2>';

    if ($active_tab === 'settings') {
        echo '<form method="post" action="options.php">';
        settings_fields('art_image_settings_group');
        do_settings_sections('art_image_settings');
        submit_button();
        echo '</form>';
    }

    if ($active_tab === 'discounts') {
        echo '<form method="post" action="options.php">';
        settings_fields('art_image_settings_group');
        do_settings_sections('art_image_discounts');
        submit_button();
        echo '</form>';
    }

    if ($active_tab === 'manual_import') {
        echo '<div class="art-image-admin">';
        echo '<p>Use os botões abaixo para importar dados manualmente. O log será exibido em tempo real.</p>';
        
        echo '<div id="manual-import-section">';
        echo '<div class="import-buttons">';
        echo '<button class="button" data-type="categories"><span class="status-indicator idle"></span>Importar Categorias</button>';
        echo '<button class="button" data-type="subcategories"><span class="status-indicator idle"></span>Importar Subcategorias</button>';
        echo '<button class="button button-primary" data-type="products"><span class="status-indicator idle"></span>Importar Produtos</button>';
        echo '<button class="button" data-type="artists"><span class="status-indicator idle"></span>Importar Artistas</button>';
        echo '</div>';
        
        echo '<div class="import-progress" id="import-progress">';
        echo '<div class="progress-bar">';
        echo '<div class="progress-fill" id="progress-fill">0%</div>';
        echo '</div>';
        echo '<div class="progress-stats">';
        echo '<span id="progress-text">Aguardando início...</span>';
        echo '<span id="progress-time"></span>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="import-actions">';
        echo '<button class="button" id="clear-log">Limpar Log</button>';
        echo '<button class="button" id="cancel-import" style="display:none;">Cancelar Importação</button>';
        echo '</div>';
        
        echo '<pre id="import-log"></pre>';
        echo '</div>'; // End #manual-import-section

        echo '<div id="active-imports-section" style="margin-top: 20px;">';
        echo '<h3>Processos de Importação em Andamento</h3>';
        echo '<div id="active-imports-list">Nenhum processo em andamento no momento.</div>';
        echo '</div>';

        echo '</div>'; // End .art-image-admin
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

    wp_enqueue_style(
        'art-image-admin-style',
        ART_IMAGE_PLUGIN_URL . 'admin/assets/css/admin-style.css',
        [],
        ART_IMAGE_VERSION
    );

    wp_localize_script('art-image-import-logs', 'art_image_ajax', [
        'nonce' => wp_create_nonce('art_image_nonce')
    ]);
});