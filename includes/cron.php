<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Funções relacionadas ao WP-Cron para importações automáticas
 */

// Adiciona um intervalo de 5 minutos para testes
add_filter('cron_schedules', 'art_image_add_every_five_minutes');
function art_image_add_every_five_minutes($schedules) {
    $schedules['every_five_minutes'] = array(
        'interval' => 300,
        'display'  => __('Every 5 Minutes')
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
 * Função chamada na ativação do plugin para agendar o evento
 */
function art_image_schedule_daily_event() {
    $schedule_time = get_option('art_image_schedule_time', '02:00');
    if (!wp_next_scheduled('art_image_daily_event')) {
        ArtImageTimezoneHelper::schedule_event('art_image_daily_event', $schedule_time, 'daily');
    }
}
register_activation_hook(ART_IMAGE_PLUGIN_FILE, 'art_image_schedule_daily_event');

/**
 * Função chamada na desativação do plugin para limpar o agendamento
 */
function art_image_unschedule_daily_event() {
    wp_clear_scheduled_hook('art_image_daily_event');
}
register_deactivation_hook(ART_IMAGE_PLUGIN_FILE, 'art_image_unschedule_daily_event');
