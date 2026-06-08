<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página de diagnóstico para o importador
 */

// Verifica permissões
if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.'));
}

// Funções auxiliares
function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Coleta informações do sistema
$diagnostics = [
    'PHP' => [
        'version' => PHP_VERSION,
        'max_execution_time' => ini_get('max_execution_time') . ' segundos',
        'memory_limit' => ini_get('memory_limit'),
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'max_input_time' => ini_get('max_input_time') . ' segundos',
    ],
    'WordPress' => [
        'version' => get_bloginfo('version'),
        'memory_limit' => WP_MEMORY_LIMIT,
        'max_memory_limit' => WP_MAX_MEMORY_LIMIT,
        'debug' => WP_DEBUG ? 'Ativado' : 'Desativado',
        'cron' => wp_next_scheduled('art_image_sync_event') ? 'Agendado' : 'Não agendado',
    ],
    'Servidor' => [
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconhecido',
        'php_sapi' => php_sapi_name(),
    ],
    'Importador' => [
        'batch_size' => apply_filters('art_image_product_import_batch_size', 5),
        'timeout_transients' => '12 horas',
    ]
];

// Verifica transients ativos
$transients = [
    'master_lock' => get_transient('artimage_master_import_lock'),
    'product_batch_lock' => get_transient('artimage_product_batch_lock'),
    'product_queue' => get_transient('artimage_product_import_queue'),
    'product_total' => get_transient('artimage_product_import_total'),
    'product_processed' => get_transient('artimage_product_processed_count'),
    'cancel_flag' => get_transient('artimage_cancel_product_import_flag'),
];

// Verifica o lock do sync-manager
$sync_lock = get_option('artimage_sync_lock', false);
$sync_step = get_option('artimage_current_sync_step', 'none');
$sync_page = get_option('artimage_current_sync_page', 0);

// Verifica eventos cron agendados
$sync_scheduled = wp_next_scheduled('art_image_sync_event');
        $legacy_sync_scheduled = wp_next_scheduled('art_image_daily_sync');
$daily_event_scheduled = wp_next_scheduled('art_image_daily_event'); // Legacy
$sync_step_scheduled = wp_next_scheduled('art_image_run_sync_step');

// Conta produtos na fila
$queue_count = 0;
if ($transients['product_queue']) {
    $queue_count = is_array($transients['product_queue']) ? count($transients['product_queue']) : 0;
}

// Verifica últimos logs
$log_file = ART_IMAGE_PLUGIN_DIR . 'logs/import-' . gmdate('Y-m-d') . '.log';
$recent_logs = [];
if (file_exists($log_file)) {
    $logs = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recent_logs = array_slice($logs, -20); // Últimas 20 linhas
}

?>

<div class="wrap">
    <h1>Diagnóstico do Importador de Imagens</h1>
    
    <div class="notice notice-info">
        <p>Esta página ajuda a identificar problemas com o processo de importação.</p>
    </div>

    <h2>Informações do Sistema</h2>
    <table class="widefat striped">
        <tbody>
            <?php foreach ($diagnostics as $section => $items): ?>
                <tr>
                    <th colspan="2" style="background: #f0f0f0;"><strong><?php echo esc_html($section); ?></strong></th>
                </tr>
                <?php foreach ($items as $key => $value): ?>
                <tr>
                    <td style="width: 300px;"><?php echo esc_html(str_replace('_', ' ', ucfirst($key))); ?></td>
                    <td><?php echo esc_html($value); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Estado da Importação</h2>
    <table class="widefat striped">
        <tbody>
            <tr>
                <th colspan="2" style="background: #f0f0f0;"><strong>Sync Manager (Sistema Principal)</strong></th>
            </tr>
            <tr>
                <td style="width: 300px;">Lock do Sync Manager</td>
                <td><?php 
                    if ($sync_lock) {
                        $lock_age = time() - $sync_lock;
                        $lock_date = date('Y-m-d H:i:s', $sync_lock);
                        $color = $lock_age > 3600 ? 'orange' : 'green';
                        echo '<span style="color: ' . $color . ';">✓ Ativo desde ' . esc_html($lock_date) . ' (' . round($lock_age/60) . ' min atrás)</span>';
                    } else {
                        echo '<span style="color: gray;">✗ Inativo</span>';
                    }
                ?></td>
            </tr>
            <tr>
                <td>Passo atual</td>
                <td><?php echo $sync_step !== 'none' ? '<strong>' . esc_html($sync_step) . '</strong> (página ' . esc_html($sync_page) . ')' : '<span style="color: gray;">Nenhum</span>'; ?></td>
            </tr>
            <tr>
                <td>Próximo sync agendado (novo sistema)</td>
                <td><?php 
                    if ($sync_scheduled) {
                        $frequency = get_option('art_image_schedule_frequency', 'weekly');
                        echo '<span style="color: green;">✓ ' . date('Y-m-d H:i:s', $sync_scheduled) . ' (' . $frequency . ')</span>';
                    } else {
                        echo '<span style="color: red;">✗ Não agendado</span>';
                    }
                ?></td>
            </tr>
            <tr>
                <td>Sync diário legacy</td>
                <td><?php echo $legacy_sync_scheduled ? '<span style="color: orange;">⚠️ ' . date('Y-m-d H:i:s', $legacy_sync_scheduled) . ' - DEVE SER REMOVIDO</span>' : '<span style="color: green;">✓ Não existe</span>'; ?></td>
            </tr>
            <tr>
                <td>Próximo passo agendado</td>
                <td><?php echo $sync_step_scheduled ? date('Y-m-d H:i:s', $sync_step_scheduled) : '<span style="color: gray;">✗ Nenhum</span>'; ?></td>
            </tr>
            <tr>
                <td>Evento legacy (art_image_daily_event)</td>
                <td><?php echo $daily_event_scheduled ? '<span style="color: orange;">⚠️ ' . date('Y-m-d H:i:s', $daily_event_scheduled) . ' - DEVE SER REMOVIDO</span>' : '<span style="color: green;">✓ Não existe</span>'; ?></td>
            </tr>
            <tr>
                <th colspan="2" style="background: #f0f0f0;"><strong>Importação Manual</strong></th>
            </tr>
            <tr>
                <td>Processo ativo (Master Lock)</td>
                <td><?php echo $transients['master_lock'] ? '<span style="color: green;">✓ ' . esc_html($transients['master_lock']) . '</span>' : '<span style="color: gray;">✗ Nenhum</span>'; ?></td>
            </tr>
            <tr>
                <td>Lock de produtos</td>
                <td><?php echo $transients['product_batch_lock'] ? '<span style="color: green;">✓ Ativo</span>' : '<span style="color: gray;">✗ Inativo</span>'; ?></td>
            </tr>
            <tr>
                <td>Produtos na fila</td>
                <td><?php echo $queue_count > 0 ? '<span style="color: blue;">' . $queue_count . ' produtos</span>' : '<span style="color: gray;">Fila vazia</span>'; ?></td>
            </tr>
            <tr>
                <td>Total para importar</td>
                <td><?php echo $transients['product_total'] ?: '0'; ?></td>
            </tr>
            <tr>
                <td>Produtos processados</td>
                <td><?php echo $transients['product_processed'] ?: '0'; ?></td>
            </tr>
            <tr>
                <td>Cancelamento solicitado</td>
                <td><?php echo $transients['cancel_flag'] ? '<span style="color: red;">✓ Sim</span>' : '<span style="color: gray;">✗ Não</span>'; ?></td>
            </tr>
        </tbody>
    </table>

    <h2>Action Scheduler</h2>
    <?php
    $as_available = ArtImageASManager::is_available();
    $using_as = get_option('artimage_using_action_scheduler', false);
    ?>
    <table class="widefat striped">
        <tbody>
            <tr>
                <th colspan="2" style="background: #f0f0f0;"><strong>Status do Action Scheduler</strong></th>
            </tr>
            <tr>
                <td style="width: 300px;">Action Scheduler Disponível</td>
                <td><?php echo $as_available ? '<span style="color: green;">✓ Sim (WooCommerce instalado)</span>' : '<span style="color: red;">✗ Não (WooCommerce não encontrado)</span>'; ?></td>
            </tr>
            <tr>
                <td>Usando Action Scheduler</td>
                <td><?php echo $using_as ? '<span style="color: green;">✓ Sim</span>' : '<span style="color: orange;">✗ Não (usando WP-Cron)</span>'; ?></td>
            </tr>
            <?php if ($as_available): ?>
            <?php
            $progress = ArtImageASManager::get_progress();
            $next_sync = ArtImageASManager::get_next_scheduled_sync();
            ?>
            <tr>
                <td>Sessão Atual</td>
                <td><?php
                    if (!empty($progress['session_id'])) {
                        echo '<span style="color: blue;">🔄 ' . esc_html($progress['session_id']) . '</span>';
                    } else {
                        echo '<span style="color: gray;">Nenhuma sincronização em andamento</span>';
                    }
                ?></td>
            </tr>
            <tr>
                <td>Fase Atual</td>
                <td><?php echo !empty($progress['current_phase']) ? '<strong>' . esc_html($progress['current_phase']) . '</strong>' : '-'; ?></td>
            </tr>
            <tr>
                <td>Próxima Sincronização (AS)</td>
                <td><?php
                    if ($next_sync) {
                        echo '<span style="color: green;">✓ ' . esc_html($next_sync) . '</span>';
                    } else {
                        echo '<span style="color: orange;">✗ Não agendada</span>';
                    }
                ?></td>
            </tr>
            <?php if (!empty($progress['phases'])): ?>
            <tr>
                <th colspan="2" style="background: #f0f0f0;"><strong>Estatísticas das Actions</strong></th>
            </tr>
            <?php foreach ($progress['phases'] as $phase_name => $stats): ?>
            <tr>
                <td><?php echo ucfirst($phase_name); ?></td>
                <td>
                    <?php if ($stats['pending'] > 0): ?><span style="color: blue;">⏳ <?php echo $stats['pending']; ?> pendentes</span> <?php endif; ?>
                    <?php if ($stats['running'] > 0): ?><span style="color: orange;">🔄 <?php echo $stats['running']; ?> executando</span> <?php endif; ?>
                    <?php if ($stats['complete'] > 0): ?><span style="color: green;">✓ <?php echo $stats['complete']; ?> completas</span> <?php endif; ?>
                    <?php if ($stats['failed'] > 0): ?><span style="color: red;">✗ <?php echo $stats['failed']; ?> falhas</span> <?php endif; ?>
                    <?php if ($stats['pending'] == 0 && $stats['running'] == 0 && $stats['complete'] == 0 && $stats['failed'] == 0): ?>
                    <span style="color: gray;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($as_available): ?>
    <div class="card" style="margin-top: 15px;">
        <h3>Ações do Action Scheduler</h3>
        <p>
            <button type="button" class="button button-primary" id="as-start-sync">
                Iniciar Sincronização (AS)
            </button>
            <button type="button" class="button button-secondary" id="as-cancel">
                Cancelar Todas Actions
            </button>
            <button type="button" class="button button-secondary" id="as-retry-failed">
                Retentar Falhas
            </button>
            <button type="button" class="button button-secondary" id="as-cleanup">
                Limpar Antigas
            </button>
        </p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=action-scheduler'); ?>" class="button" target="_blank">
                Ver Ações Agendadas (Action Scheduler)
            </a>
        </p>
        <div id="as-result" style="margin-top: 10px;"></div>
    </div>
    <?php endif; ?>

    <h2>Cron Dedicado (Recomendado)</h2>
    <?php
    global $artimage_dedicated_cron;
    $dc_status = $artimage_dedicated_cron ? $artimage_dedicated_cron->get_status() : null;
    ?>
    <div class="card" style="border-left: 4px solid #0073aa; background: #f0f7fc;">
        <h3 style="margin-top: 0;">Sistema de Cron Dedicado</h3>
        <p><strong>Este sistema processa a sincronização de forma independente, sem competir com outros plugins na fila do Action Scheduler.</strong></p>

        <?php if ($dc_status): ?>
        <table class="widefat striped" style="margin: 10px 0;">
            <tbody>
                <tr>
                    <td style="width: 200px;"><strong>Status</strong></td>
                    <td>
                        <?php
                        switch ($dc_status['status']) {
                            case 'running':
                                echo '<span style="color: green; font-weight: bold;">🔄 Executando</span>';
                                break;
                            case 'stopped':
                                echo '<span style="color: orange;">⏹ Parado</span>';
                                break;
                            case 'error':
                                echo '<span style="color: red;">❌ Erro</span>';
                                break;
                            default:
                                echo '<span style="color: gray;">⏸ Parado</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php if ($dc_status['phase']): ?>
                <tr>
                    <td><strong>Fase Atual</strong></td>
                    <td><span style="color: blue;"><?php echo ucfirst(esc_html($dc_status['phase'])); ?></span></td>
                </tr>
                <?php endif; ?>
                <?php if ($dc_status['started_at']): ?>
                <tr>
                    <td><strong>Iniciado em</strong></td>
                    <td><?php echo esc_html($dc_status['started_at']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($dc_status['stats'])): ?>
                <tr>
                    <td><strong>Categorias</strong></td>
                    <td><?php echo (int)($dc_status['stats']['categories'] ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Subcategorias</strong></td>
                    <td><?php echo (int)($dc_status['stats']['subcategories'] ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Artistas</strong></td>
                    <td><?php echo (int)($dc_status['stats']['artists'] ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Produtos na Fila</strong></td>
                    <td><?php echo (int)($dc_status['stats']['products_queued'] ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Produtos Processados</strong></td>
                    <td>
                        <?php
                        $processed = (int)($dc_status['stats']['products_processed'] ?? 0);
                        $queued = (int)($dc_status['stats']['products_queued'] ?? 0);
                        echo $processed;
                        if ($queued > 0) {
                            $percent = round(($processed / $queued) * 100, 1);
                            echo " / {$queued} ({$percent}%)";
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Restantes na Fila</strong></td>
                    <td><?php echo (int)$dc_status['queue_remaining']; ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary button-hero" id="dc-start" <?php echo ($dc_status && $dc_status['status'] === 'running') ? 'disabled' : ''; ?>>
                🚀 Iniciar Sincronização Dedicada
            </button>
            <button type="button" class="button button-secondary" id="dc-stop" <?php echo (!$dc_status || $dc_status['status'] !== 'running') ? 'disabled' : ''; ?>>
                ⏹ Parar
            </button>
            <button type="button" class="button button-secondary" id="dc-refresh">
                🔄 Atualizar Status
            </button>
        </p>
        <div id="dc-result" style="margin-top: 10px;"></div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Cron Dedicado - Iniciar
        $('#dc-start').click(function() {
            var btn = $(this);
            btn.prop('disabled', true).text('Iniciando...');
            $('#dc-result').html('<div class="notice notice-info inline"><p>Iniciando sincronização...</p></div>');

            $.post(ajaxurl, {
                action: 'artimage_dc_start',
                _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    $('#dc-result').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    location.reload();
                } else {
                    $('#dc-result').html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                    btn.prop('disabled', false).text('🚀 Iniciar Sincronização Dedicada');
                }
            }).fail(function() {
                $('#dc-result').html('<div class="notice notice-error inline"><p>Erro de conexão</p></div>');
                btn.prop('disabled', false).text('🚀 Iniciar Sincronização Dedicada');
            });
        });

        // Cron Dedicado - Parar
        $('#dc-stop').click(function() {
            if (!confirm('Deseja parar a sincronização?')) return;

            $.post(ajaxurl, {
                action: 'artimage_dc_stop',
                _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    $('#dc-result').html('<div class="notice notice-warning inline"><p>' + response.data.message + '</p></div>');
                    location.reload();
                }
            });
        });

        // Cron Dedicado - Atualizar
        $('#dc-refresh').click(function() {
            location.reload();
        });

        // Auto-refresh se estiver rodando
        <?php if ($dc_status && $dc_status['status'] === 'running'): ?>
        setInterval(function() {
            location.reload();
        }, 30000); // Atualiza a cada 30 segundos
        <?php endif; ?>
    });
    </script>

    <h2>Recomendações</h2>
    <div class="card">
        <h3>Configurações PHP</h3>
        <?php
        $warnings = [];
        
        // Verifica tempo de execução
        $max_exec = (int) ini_get('max_execution_time');
        if ($max_exec > 0 && $max_exec < 300) {
            $warnings[] = "⚠️ <strong>max_execution_time</strong> está muito baixo ({$max_exec}s). Recomendado: 300s ou 0 (ilimitado).";
        }
        
        // Verifica limite de memória
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
        if ($memory_bytes < 256 * 1024 * 1024) {
            $warnings[] = "⚠️ <strong>memory_limit</strong> está baixo ({$memory_limit}). Recomendado: 256M ou mais.";
        }
        
        // Verifica batch size
        $batch_size = apply_filters('art_image_product_import_batch_size', 5);
        if ($batch_size > 10) {
            $warnings[] = "⚠️ <strong>Batch size</strong> está alto ({$batch_size}). Para importações grandes, use 5 ou menos.";
        }
        
        if (empty($warnings)) {
            echo '<p style="color: green;">✓ Todas as configurações parecem adequadas.</p>';
        } else {
            echo '<ul>';
            foreach ($warnings as $warning) {
                echo '<li>' . $warning . '</li>';
            }
            echo '</ul>';
        }
        ?>
    </div>

    <h2>Logs Recentes</h2>
    <div class="card" style="background: #f5f5f5; padding: 10px; max-height: 400px; overflow-y: auto;">
        <?php if (!empty($recent_logs)): ?>
            <pre style="margin: 0; white-space: pre-wrap;"><?php 
                foreach ($recent_logs as $log) {
                    echo esc_html($log) . "\n";
                }
            ?></pre>
        <?php else: ?>
            <p>Nenhum log encontrado para hoje.</p>
        <?php endif; ?>
    </div>

    <h2>Ações de Manutenção</h2>
    <div class="card">
        <p>Use estas ações para resolver problemas com importações travadas:</p>
        
        <button type="button" class="button button-secondary" id="clear-locks">
            Limpar Travas (Locks)
        </button>
        
        <button type="button" class="button button-secondary" id="clear-queue">
            Limpar Fila de Produtos
        </button>
        
        <button type="button" class="button button-primary" id="reset-all">
            Reset Completo
        </button>
        
        <button type="button" class="button button-secondary" id="cleanup-legacy">
            Limpar Eventos Legacy
        </button>
        
        <br><br>
        
        <h3>Ações de Debug do CRON</h3>
        
        <button type="button" class="button button-secondary" id="test-cron">
            Testar WP-Cron
        </button>
        
        <button type="button" class="button button-primary" id="force-sync">
            Forçar Execução da Sincronização
        </button>
        
        <div id="maintenance-result" style="margin-top: 10px;"></div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Limpar locks
    $('#clear-locks').on('click', function() {
        if (!confirm('Isso irá limpar todas as travas de importação. Continuar?')) return;
        
        $.post(ajaxurl, {
            action: 'art_image_clear_locks',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            $('#maintenance-result').html('<div class="notice notice-success"><p>Travas limpas com sucesso!</p></div>');
            setTimeout(() => location.reload(), 2000);
        });
    });
    
    // Limpar fila
    $('#clear-queue').on('click', function() {
        if (!confirm('Isso irá limpar a fila de produtos. Continuar?')) return;
        
        $.post(ajaxurl, {
            action: 'art_image_clear_queue',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            $('#maintenance-result').html('<div class="notice notice-success"><p>Fila limpa com sucesso!</p></div>');
            setTimeout(() => location.reload(), 2000);
        });
    });
    
    // Reset completo
    $('#reset-all').on('click', function() {
        if (!confirm('Isso irá resetar TODA a importação. Continuar?')) return;
        
        $.post(ajaxurl, {
            action: 'art_image_reset_all',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            $('#maintenance-result').html('<div class="notice notice-success"><p>Reset completo realizado!</p></div>');
            setTimeout(() => location.reload(), 2000);
        });
    });
    
    // Testar WP-Cron
    $('#test-cron').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Testando...');
        
        $.post(ajaxurl, {
            action: 'art_image_test_cron',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#maintenance-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                $('#maintenance-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Testar WP-Cron');
        });
    });
    
    // Forçar sincronização
    $('#force-sync').on('click', function() {
        if (!confirm('Isso irá forçar a execução imediata da sincronização. Continuar?')) return;
        
        var $button = $(this);
        $button.prop('disabled', true).text('Executando...');
        
        $.post(ajaxurl, {
            action: 'art_image_force_sync',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#maintenance-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                $('#maintenance-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
            setTimeout(() => location.reload(), 3000);
        }).always(function() {
            $button.prop('disabled', false).text('Forçar Execução da Sincronização');
        });
    });
    
    // Limpar eventos legacy
    $('#cleanup-legacy').on('click', function() {
        if (!confirm('Isso irá remover todos os eventos legacy do sistema antigo. Continuar?')) return;

        $.post(ajaxurl, {
            action: 'art_image_cleanup_legacy',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#maintenance-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p><pre>' + (response.data.details || '') + '</pre></div>');
            } else {
                $('#maintenance-result').html('<div class="notice notice-error"><p>' + (response.data.message || 'Erro ao limpar eventos') + '</p></div>');
            }
            setTimeout(() => location.reload(), 3000);
        });
    });

    // =========================================================================
    // ACTION SCHEDULER
    // =========================================================================

    // Iniciar sincronização via Action Scheduler
    $('#as-start-sync').on('click', function() {
        if (!confirm('Isso irá iniciar uma sincronização completa via Action Scheduler. Continuar?')) return;

        var $button = $(this);
        $button.prop('disabled', true).text('Iniciando...');

        $.post(ajaxurl, {
            action: 'art_image_as_start_sync',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#as-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                $('#as-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
            setTimeout(() => location.reload(), 2000);
        }).always(function() {
            $button.prop('disabled', false).text('Iniciar Sincronização (AS)');
        });
    });

    // Cancelar todas as actions
    $('#as-cancel').on('click', function() {
        if (!confirm('Isso irá cancelar TODAS as actions pendentes do Action Scheduler. Continuar?')) return;

        var $button = $(this);
        $button.prop('disabled', true).text('Cancelando...');

        $.post(ajaxurl, {
            action: 'art_image_as_cancel',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#as-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                $('#as-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
            setTimeout(() => location.reload(), 2000);
        }).always(function() {
            $button.prop('disabled', false).text('Cancelar Todas Actions');
        });
    });

    // Retentar actions falhas
    $('#as-retry-failed').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Retentando...');

        $.post(ajaxurl, {
            action: 'art_image_as_retry_failed',
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#as-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                $('#as-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
            setTimeout(() => location.reload(), 2000);
        }).always(function() {
            $button.prop('disabled', false).text('Retentar Falhas');
        });
    });

    // Limpar actions antigas
    $('#as-cleanup').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Limpando...');

        $.post(ajaxurl, {
            action: 'art_image_as_cleanup',
            days: 7,
            _ajax_nonce: '<?php echo wp_create_nonce('art_image_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                $('#as-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
            } else {
                $('#as-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Limpar Antigas');
        });
    });
});
</script>