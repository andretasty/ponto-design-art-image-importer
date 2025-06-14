<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registro de configurações do plugin
 */

add_action('admin_init', function () {
    register_setting('art_image_settings_group', 'art_image_email');
    register_setting('art_image_settings_group', 'art_image_password');
    register_setting('art_image_settings_group', 'art_image_schedule_time');

    add_settings_section(
        'art_image_main_section',
        __('Configurações de Sincronização', 'art-image'),
        '__return_false',
        'art_image_settings'
    );

    add_settings_field(
        'art_image_email',
        __('E-mail de login', 'art-image'),
        function () {
            $value = esc_attr(get_option('art_image_email', ''));
            echo "<input type='email' name='art_image_email' value='$value' class='regular-text' />";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    add_settings_field(
        'art_image_password',
        __('Senha', 'art-image'),
        function () {
            $value = esc_attr(get_option('art_image_password', ''));
            echo "<input type='password' name='art_image_password' value='$value' class='regular-text' />";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    add_settings_field(
        'art_image_schedule_time',
        __('Horário diário para importar (HH:MM)', 'art-image'),
        function () {
            $value = esc_attr(get_option('art_image_schedule_time', '02:00'));
            echo "<input type='time' name='art_image_schedule_time' value='$value' class='small-text' />";
        },
        'art_image_settings',
        'art_image_main_section'
    );
});
