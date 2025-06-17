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

    if (!$configured_time) return;

    $current_time = current_time('H:i');
    if ($current_time !== $configured_time) return;

    require_once ART_IMAGE_PLUGIN_DIR . 'includes/sync-manager.php';
    art_image_run_sync();
}

/**
 * Reagenda cron ao mudar o horário de execução
 */
function art_image_reschedule_cron($old_value, $new_value) {
    if ($old_value === $new_value) return;

    $timestamp = wp_next_scheduled('art_image_daily_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'art_image_daily_event');
    }

    wp_schedule_event(time(), 'every_five_minutes', 'art_image_daily_event');
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
