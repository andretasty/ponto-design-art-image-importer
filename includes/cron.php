<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron Job: executa importação diária no horário configurado
 */

add_action('init', 'art_image_schedule_cron');
add_action('art_image_daily_event', 'art_image_run_scheduled_import');
add_action('update_option_art_image_schedule_time', 'art_image_reschedule_cron', 10, 2);

/**
 * Agenda um evento a cada 5 minutos para checagem
 */
function art_image_schedule_cron() {
    if (!wp_next_scheduled('art_image_daily_event')) {
        wp_schedule_event(time(), 'every_five_minutes', 'art_image_daily_event');
    }
}

/**
 * Executa a importação se a hora atual bater com a configurada
 */
function art_image_run_scheduled_import() {
    $configured_time = get_option('art_image_schedule_time', '02:00');

    if (!$configured_time) {
        art_image_log_cron('Horário não configurado, pulando execução');
        return;
    }

    $current_time = current_time('H:i');
    $timezone_name = wp_timezone_string();
    
    // Log para debug
    art_image_log_cron("Verificando horário: atual={$current_time}, configurado={$configured_time}, timezone={$timezone_name}");
    
    if ($current_time !== $configured_time) {
        return;
    }

    art_image_log_cron('Iniciando importação agendada...');
    
    require_once ART_IMAGE_PLUGIN_DIR . 'includes/importer.php';
    
    try {
        art_image_import_categories();
        art_image_import_subcategories();
        art_image_import_products();
        art_image_import_artists();
        art_image_log_cron('Importação agendada concluída com sucesso');
    } catch (Exception $e) {
        art_image_log_cron('Erro na importação agendada: ' . $e->getMessage());
    }
}

/**
 * Função auxiliar para logging do cron
 */
function art_image_log_cron($message) {
    $timestamp = current_time('Y-m-d H:i:s');
    $timezone_name = wp_timezone_string();
    $log_file = WP_CONTENT_DIR . '/art-image-cron.log';
    $log_message = "[{$timestamp} {$timezone_name}] CRON: {$message}\n";
    error_log($log_message, 3, $log_file);
}

/**
 * Reagenda cron ao mudar o horário de execução
 */
function art_image_reschedule_cron($old_value, $new_value) {
    if ($old_value === $new_value) return;

    art_image_log_cron("Reagendando cron: horário alterado de {$old_value} para {$new_value}");

    $timestamp = wp_next_scheduled('art_image_daily_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'art_image_daily_event');
        art_image_log_cron('Evento anterior cancelado');
    }

    wp_schedule_event(time(), 'every_five_minutes', 'art_image_daily_event');
    art_image_log_cron('Novo evento de verificação agendado');
}

/**
 * Adiciona intervalo customizado de 5 minutos (para checagem)
 */
add_filter('cron_schedules', function ($schedules) {
    $schedules['every_five_minutes'] = [
        'interval' => 300,
        'display' => __('A cada 5 minutos')
    ];
    return $schedules;
});
