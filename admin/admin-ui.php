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
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';

    echo '<div class="wrap">';
    echo '<h1>Art Image</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=art-image&tab=settings" class="nav-tab ' . ($active_tab === 'settings' ? 'nav-tab-active' : '') . '">Configurações</a>';
    echo '<a href="?page=art-image&tab=discounts" class="nav-tab ' . ($active_tab === 'discounts' ? 'nav-tab-active' : '') . '">Descontos</a>';
    echo '<a href="?page=art-image&tab=manual_import" class="nav-tab ' . ($active_tab === 'manual_import' ? 'nav-tab-active' : '') . '">Importação Manual</a>';
    echo '<a href="?page=art-image&tab=diagnostics" class="nav-tab ' . ($active_tab === 'diagnostics' ? 'nav-tab-active' : '') . '">Diagnóstico</a>';
    echo '<a href="?page=art-image&tab=failures" class="nav-tab ' . ($active_tab === 'failures' ? 'nav-tab-active' : '') . '">Falhas</a>';
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

        // === SEÇÃO IMPORTAÇÃO MANUAL (AJAX) ===
        echo '<h3><span class="dashicons dashicons-upload" style="margin-right: 5px;"></span>Importação Manual</h3>';
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

    if ($active_tab === 'diagnostics') {
        require_once ART_IMAGE_PLUGIN_DIR . 'admin/diagnostics.php';
    }

    if ($active_tab === 'failures') {
        $pendentes = ArtImageFailedProducts::get_pending();
        $total = count($pendentes);
        echo '<div class="art-image-admin">';
        echo '<h3>Produtos com falha</h3>';
        echo '<p>' . esc_html($total) . ' produto(s) com falha pendente. O retry automático (até 3x) roda no fim de cada importação.</p>';
        echo '<p><button class="button button-primary" id="artimage-retry-failed">Tentar todos agora</button> <span id="artimage-retry-status"></span></p>';

        if ($total === 0) {
            echo '<p><em>Nenhuma falha registrada.</em></p>';
        } else {
            echo '<table class="wp-list-table widefat striped"><thead><tr>';
            echo '<th>SKU</th><th>Produto</th><th>Link na artimage</th><th>Motivo</th><th>Tentativas</th><th>Última falha</th>';
            echo '</tr></thead><tbody>';
            foreach ($pendentes as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->code) . '</td>';
                echo '<td>' . esc_html($row->name) . '</td>';
                echo '<td>';
                if (!empty($row->source_url)) {
                    echo '<a href="' . esc_url($row->source_url) . '" target="_blank" rel="noopener">abrir</a>';
                } else {
                    echo '—';
                }
                echo '</td>';
                echo '<td>' . esc_html($row->reason) . '</td>';
                echo '<td>' . esc_html((string)$row->attempts) . '</td>';
                echo '<td>' . esc_html((string)$row->last_failed_at) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        $nonce = wp_create_nonce('art_image_nonce');
        $ajax = admin_url('admin-ajax.php');
        echo "<script>
        (function(){
            var b=document.getElementById('artimage-retry-failed');
            if(!b)return;
            b.addEventListener('click',function(){
                b.disabled=true;
                document.getElementById('artimage-retry-status').textContent='Re-enfileirando...';
                var body='action=art_image_retry_failed_products&_ajax_nonce=" . esc_js($nonce) . "';
                fetch('" . esc_js($ajax) . "',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
                  .then(function(r){return r.json();})
                  .then(function(j){document.getElementById('artimage-retry-status').textContent=(j&&j.data&&j.data.message)||'OK'; setTimeout(function(){location.reload();},1500);})
                  .catch(function(){document.getElementById('artimage-retry-status').textContent='Erro'; b.disabled=false;});
            });
        })();
        </script>";
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