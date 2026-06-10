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
    register_setting('art_image_settings_group', 'art_image_profit_margin');
    register_setting('art_image_settings_group', 'art_image_enable_discount');
    register_setting('art_image_settings_group', 'art_image_discount_percent');
    register_setting('art_image_settings_group', 'art_image_category_discounts');
    
    // Novas configurações para sincronização semanal
    register_setting('art_image_settings_group', 'art_image_schedule_frequency', [
        'sanitize_callback' => 'art_image_sanitize_frequency',
        'default' => 'weekly'
    ]);
    register_setting('art_image_settings_group', 'art_image_schedule_day', [
        'sanitize_callback' => 'art_image_sanitize_day',
        'default' => 'sunday'
    ]);

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
            $value = esc_attr(defined('ART_IMAGE_EMAIL') ? ART_IMAGE_EMAIL : get_option('art_image_email', ''));
            echo "<input type='email' value='$value' class='regular-text' readonly disabled />";
            echo "<p class='description' style='color: #666;'>Valor fixo definido no código</p>";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    add_settings_field(
        'art_image_password',
        __('Senha', 'art-image'),
        function () {
            echo "<input type='password' value='********' class='regular-text' readonly disabled />";
            echo "<p class='description' style='color: #666;'>Valor fixo definido no código</p>";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    add_settings_field(
        'art_image_schedule_frequency',
        __('Frequência de Sincronização', 'art-image'),
        function () {
            $value = defined('ART_IMAGE_SCHEDULE_FREQUENCY') ? ART_IMAGE_SCHEDULE_FREQUENCY : get_option('art_image_schedule_frequency', 'weekly');
            $label = $value === 'weekly' ? 'Semanal' : 'Diária';
            echo "<input type='text' value='{$label}' class='regular-text' readonly disabled />";
            echo "<p class='description' style='color: #666;'>Valor fixo definido no código</p>";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    add_settings_field(
        'art_image_schedule_day',
        __('Dia da Semana', 'art-image'),
        function () {
            $value = defined('ART_IMAGE_SCHEDULE_DAY') ? ART_IMAGE_SCHEDULE_DAY : get_option('art_image_schedule_day', 'sunday');
            $days = [
                'sunday' => 'Domingo',
                'monday' => 'Segunda-feira',
                'tuesday' => 'Terça-feira',
                'wednesday' => 'Quarta-feira',
                'thursday' => 'Quinta-feira',
                'friday' => 'Sexta-feira',
                'saturday' => 'Sábado'
            ];
            $label = $days[$value] ?? $value;
            echo "<input type='text' value='{$label}' class='regular-text' readonly disabled />";
            echo "<p class='description' style='color: #666;'>Valor fixo definido no código</p>";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    add_settings_field(
        'art_image_schedule_time',
        __('Horário de Sincronização', 'art-image'),
        function () {
            $value = esc_attr(defined('ART_IMAGE_SCHEDULE_TIME') ? ART_IMAGE_SCHEDULE_TIME : get_option('art_image_schedule_time', '02:00'));
            echo "<input type='text' value='$value' class='regular-text' readonly disabled />";
            echo "<p class='description' style='color: #666;'>Valor fixo definido no código (fuso horário do WordPress)</p>";
        },
        'art_image_settings',
        'art_image_main_section'
    );

    // Nova seção para configurações de preço
    add_settings_section(
        'art_image_pricing_section',
        __('Configurações de Preço', 'art-image'),
        '__return_false',
        'art_image_settings'
    );

    add_settings_field(
        'art_image_profit_margin',
        __('Margem de Lucro (%)', 'art-image'),
        function () {
            $value = esc_attr(get_option('art_image_profit_margin', '0'));
            echo "<input type='number' name='art_image_profit_margin' value='$value' class='small-text' min='0' step='0.01' />";
            echo "<p class='description'>" . __('Adicione a porcentagem de margem de lucro que será aplicada em todos os produtos.', 'art-image') . "</p>";
        },
        'art_image_settings',
        'art_image_pricing_section'
    );

    // Nova seção para configurações de desconto
    add_settings_section(
        'art_image_discounts_section',
        __('Configurações de Desconto', 'art-image'),
        '__return_false',
        'art_image_discounts'
    );

    add_settings_field(
        'art_image_enable_discount',
        __('Ativar descontos?', 'art-image'),
        function () {
            $enabled = defined('ART_IMAGE_ENABLE_DISCOUNT') ? ART_IMAGE_ENABLE_DISCOUNT : (get_option('art_image_enable_discount', '0') === '1');
            $status = $enabled ? 'Ativado' : 'Desativado';
            echo "<input type='text' value='{$status}' class='regular-text' readonly disabled />";
            echo "<p class='description' style='color: #666;'>Valor fixo definido no código</p>";
        },
        'art_image_discounts',
        'art_image_discounts_section',
        ['label_for' => 'art_image_enable_discount', 'class' => 'art-image-discount-field', 'data-setting' => 'art_image_enable_discount']
    );

    add_settings_field(
        'art_image_discount_percent',
        __('Desconto Geral (%)', 'art-image'),
        function () {
            $value = esc_attr(get_option('art_image_discount_percent', '0'));
            echo "<input type='number' name='art_image_discount_percent' value='$value' class='small-text' min='0' max='100' step='0.01' />";
            echo "<p class='description'>Desconto percentual aplicado a todos os produtos, exceto os que tiverem desconto por categoria.</p>";
        },
        'art_image_discounts',
        'art_image_discounts_section',
        ['label_for' => 'art_image_discount_percent', 'class' => 'art-image-discount-field', 'data-setting' => 'art_image_discount_percent']
    );

    // Limpeza automática de órfãos removida da interface: funcionalidade
    // desativada por decisão de produto (o critério atual poderia apagar
    // produtos válidos quando um import falha).
});

/**
 * Funções de sanitização para as novas configurações
 */
function art_image_sanitize_frequency($value) {
    $allowed = ['daily', 'weekly'];
    return in_array($value, $allowed) ? $value : 'weekly';
}

function art_image_sanitize_day($value) {
    $allowed = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    return in_array($value, $allowed) ? $value : 'sunday';
}
