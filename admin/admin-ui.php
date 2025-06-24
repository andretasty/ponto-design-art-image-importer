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
    echo '<a href="?page=art-image&tab=sync_stats" class="nav-tab ' . ($active_tab === 'sync_stats' ? 'nav-tab-active' : '') . '">Estatísticas</a>';
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

    if ($active_tab === 'sync_stats') {
        global $artimage_sync_tracker;
        
        echo '<div class="art-image-admin">';
        echo '<h3>Estatísticas de Sincronização</h3>';
        
        if ($artimage_sync_tracker) {
            $stats = $artimage_sync_tracker->get_sync_stats();
            
            echo '<table class="widefat">';
            echo '<tr><td><strong>Última Sincronização - Início:</strong></td><td>' . ($stats['last_sync_start'] ?: 'Nunca') . '</td></tr>';
            echo '<tr><td><strong>Última Sincronização - Fim:</strong></td><td>' . ($stats['last_sync_end'] ?: 'Nunca') . '</td></tr>';
            
            if (!empty($stats['last_sync_results'])) {
                $results = $stats['last_sync_results'];
                echo '<tr><td><strong>Produtos Removidos:</strong></td><td>' . ($results['products_deleted'] ?? 0) . '</td></tr>';
                echo '<tr><td><strong>Categorias Removidas:</strong></td><td>' . ($results['categories_deleted'] ?? 0) . '</td></tr>';
                echo '<tr><td><strong>Artistas Removidos:</strong></td><td>' . ($results['artists_deleted'] ?? 0) . '</td></tr>';
            }
            
            echo '<tr><td><strong>ID da Sessão Atual:</strong></td><td>' . ($stats['current_sync_id'] ?: 'Nenhuma') . '</td></tr>';
            echo '</table>';
            
            // Configurações de limpeza
            echo '<h4>Configurações Atuais</h4>';
            echo '<table class="widefat">';
            echo '<tr><td><strong>Limpeza Automática:</strong></td><td>' . (get_option('artimage_enable_cleanup', true) ? 'Ativada' : 'Desativada') . '</td></tr>';
            echo '<tr><td><strong>Modo Teste (Dry Run):</strong></td><td>' . (get_option('artimage_cleanup_dry_run', false) ? 'Ativado' : 'Desativado') . '</td></tr>';
            echo '</table>';
            
            // Botão para forçar limpeza manual
            echo '<h4>Ações Manuais</h4>';
            echo '<button class="button" onclick="if(confirm(\'Tem certeza que deseja executar a limpeza manual?\')) { 
                fetch(ajaxurl, {
                    method: \'POST\',
                    headers: { \'Content-Type\': \'application/x-www-form-urlencoded\' },
                    body: new URLSearchParams({ action: \'art_image_manual_cleanup\', _ajax_nonce: \'' . wp_create_nonce('art_image_nonce') . '\' })
                }).then(res => res.json()).then(data => {
                    alert(data.success ? \'Limpeza executada com sucesso!\' : \'Erro: \' + (data.data?.message || \'Erro desconhecido\'));
                    location.reload();
                });
            }">Executar Limpeza Manual</button>';
            
        } else {
            echo '<p>Sistema de tracking não disponível.</p>';
        }
        
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