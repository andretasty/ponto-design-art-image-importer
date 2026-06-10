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

/**
 * Formata datetime MySQL (Y-m-d H:i:s) como dd/mm/yyyy HH:MM.
 */
function art_image_format_datetime($mysql_datetime) {
    if (empty($mysql_datetime)) {
        return '—';
    }
    $ts = strtotime($mysql_datetime);
    if (!$ts) {
        return (string) $mysql_datetime;
    }
    return date('d/m/Y H:i', $ts);
}

/**
 * Painel de status da sincronização (home).
 * A sincronização roda exclusivamente via Action Scheduler + cron de servidor;
 * este painel é somente leitura.
 */
function art_image_render_status_panel() {
    $running = class_exists('ArtImageASManager') && ArtImageASManager::is_sync_running();
    $progress = class_exists('ArtImageASManager') ? ArtImageASManager::get_progress() : [];
    $last_completed = get_option('artimage_as_last_completed');
    $next = class_exists('ArtImageASManager') ? ArtImageASManager::get_next_scheduled_sync() : null;
    $failures = class_exists('ArtImageFailedProducts') ? ArtImageFailedProducts::count_pending() : 0;

    echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-left-color:' . ($running ? '#00a32a' : '#72aee6') . ';padding:12px 16px;margin:16px 0;">';

    if ($running) {
        $phase = isset($progress['current_phase']) ? $progress['current_phase'] : '?';
        $pending = isset($progress['total_pending']) ? (int) $progress['total_pending'] : 0;
        $complete = isset($progress['total_complete']) ? (int) $progress['total_complete'] : 0;
        echo '<p style="margin:0 0 6px;"><strong style="color:#00a32a;">&#9679; Sincronização em andamento</strong>';
        echo ' — fase: <code>' . esc_html($phase) . '</code>';
        echo ' &middot; ' . esc_html((string) $complete) . ' ações concluídas';
        if ($pending > 0) {
            echo ' &middot; ' . esc_html((string) $pending) . ' na fila';
        }
        echo '</p>';
    } else {
        echo '<p style="margin:0 0 6px;"><strong style="color:#2271b1;">&#9679; Nenhuma sincronização em andamento</strong></p>';
    }

    echo '<p style="margin:0;color:#50575e;">';
    echo 'Última concluída: <strong>' . esc_html(art_image_format_datetime($last_completed)) . '</strong>';
    echo ' &middot; Próxima agendada: <strong>' . esc_html(art_image_format_datetime($next)) . '</strong>';
    echo ' &middot; Falhas pendentes: ';
    if ($failures > 0) {
        echo '<a href="?page=art-image&tab=failures"><strong style="color:#d63638;">' . esc_html((string) $failures) . '</strong></a>';
    } else {
        echo '<strong style="color:#00a32a;">0</strong>';
    }
    echo '</p>';

    echo '</div>';
}

function art_image_render_admin_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';

    $failures_count = class_exists('ArtImageFailedProducts') ? ArtImageFailedProducts::count_pending() : 0;
    $failures_badge = $failures_count > 0
        ? ' <span style="display:inline-block;min-width:18px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;vertical-align:top;">' . esc_html((string) $failures_count) . '</span>'
        : '';

    echo '<div class="wrap">';
    echo '<h1>Art Image</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=art-image&tab=settings" class="nav-tab ' . ($active_tab === 'settings' ? 'nav-tab-active' : '') . '">Configurações</a>';
    echo '<a href="?page=art-image&tab=discounts" class="nav-tab ' . ($active_tab === 'discounts' ? 'nav-tab-active' : '') . '">Descontos</a>';
    echo '<a href="?page=art-image&tab=failures" class="nav-tab ' . ($active_tab === 'failures' ? 'nav-tab-active' : '') . '">Falhas' . $failures_badge . '</a>';
    echo '</h2>';

    if ($active_tab === 'settings') {
        art_image_render_status_panel();
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
                echo '<td>' . esc_html(art_image_format_datetime($row->last_failed_at)) . '</td>';
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

    wp_enqueue_style(
        'art-image-admin-style',
        ART_IMAGE_PLUGIN_URL . 'admin/assets/css/admin-style.css',
        [],
        ART_IMAGE_VERSION
    );
});
