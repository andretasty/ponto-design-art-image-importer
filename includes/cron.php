<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Funções de WP-Cron para importações automáticas
 *
 * NOTA: Este arquivo é um FALLBACK para quando o Action Scheduler não está disponível.
 * O sistema primário de agendamento é o Action Scheduler (includes/action-scheduler-manager.php).
 * As funções aqui são usadas apenas como backup ou para compatibilidade com instalações
 * que não possuem WooCommerce/Action Scheduler instalado.
 *
 * @see ArtImageASManager para o sistema primário de agendamento
 */

// Adiciona intervalo semanal ao WP-Cron
add_filter('cron_schedules', 'art_image_add_weekly_schedule');
function art_image_add_weekly_schedule($schedules) {
    $schedules['weekly'] = array(
        'interval' => 604800, // 7 dias em segundos
        'display'  => __('Weekly')
    );
    return $schedules;
}

// Hook para o evento diário
add_action('art_image_daily_event', 'art_image_run_daily_import');

/**
 * Função principal que executa a importação diária completa.
 * Modificada para rodar em um loop contínuo no servidor.
 */
function art_image_run_daily_import() {
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/timezone-helper.php';

    ArtImageTimezoneHelper::log_with_timezone("Iniciando rotina de importação automática via cron.");

    $importer = new ArtImageImporter();
    
    // As importações de categorias, subcategorias e artistas são geralmente rápidas
    // e podem ser executadas em um único lote grande.
    ArtImageTimezoneHelper::log_with_timezone("Iniciando importação de categorias...");
    $importer->import_categories(1, 1000); // Tenta importar todas de uma vez

    ArtImageTimezoneHelper::log_with_timezone("Iniciando importação de subcategorias...");
    $importer->import_subcategories(1, 1000); // Tenta importar todas de uma vez
    
    ArtImageTimezoneHelper::log_with_timezone("Iniciando importação de artistas...");
    $importer->import_artists(1, 1000); // Tenta importar todos de uma vez

    ArtImageTimezoneHelper::log_with_timezone("Iniciando importação de produtos em loop...");

    $page = 1;
    $max_pages = 200; // Prevenção de loop infinito (ex: 200 * 5 = 1000 produtos)
    $has_more = true;

    do {
        ArtImageTimezoneHelper::log_with_timezone("Processando lote de produtos, página {$page}...");
        
        // A função import_products_batch prepara a fila na primeira página se necessário
        $result = $importer->import_products_batch($page, 5); // Processa 5 produtos por lote

        if (isset($result['has_more']) && $result['has_more'] === true) {
            $has_more = true;
            $page++;
        } else {
            $has_more = false;
        }

        if ($page > $max_pages) {
            ArtImageTimezoneHelper::log_with_timezone("Atingido o limite máximo de páginas ({$max_pages}). Interrompendo o cron para evitar loop infinito.");
            break;
        }

        // Pequena pausa para não sobrecarregar o servidor
        sleep(2);

    } while ($has_more);

    ArtImageTimezoneHelper::log_with_timezone("Rotina de importação automática via cron finalizada.");
}

/**
 * Função de agendamento flexível para sincronização
 */
function art_image_schedule_sync_event() {
    $schedule_time = get_option('art_image_schedule_time', '02:00');
    $schedule_frequency = get_option('art_image_schedule_frequency', 'weekly');
    $schedule_day = get_option('art_image_schedule_day', 'sunday'); // Para semanal
    
    if (!wp_next_scheduled('art_image_sync_event')) {
        if ($schedule_frequency === 'weekly') {
            $timestamp = ArtImageTimezoneHelper::get_next_weekly_execution_time($schedule_time, $schedule_day)->getTimestamp();
        } else {
            $timestamp = ArtImageTimezoneHelper::get_next_execution_time($schedule_time)->getTimestamp();
        }
        
        wp_schedule_event($timestamp, $schedule_frequency, 'art_image_sync_event');
    }
}

// IMPORTANTE: Hooks de ativação conflitantes foram removidos para evitar problemas
// A migração será gerenciada pelo sync-manager.php
