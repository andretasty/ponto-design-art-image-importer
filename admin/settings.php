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
            echo "<input type='time' name='art_image_schedule_time' value='$value' class='regular-text' />";
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
            $checked = checked(get_option('art_image_enable_discount', '0'), '1', false);
            echo "<input type='checkbox' name='art_image_enable_discount' value='1' $checked /> ";
            echo "<span>Ativar descontos gerais ou por categoria</span>";
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

    // Nova seção para configurações de sincronização
    add_settings_section(
        'art_image_sync_section',
        __('Configurações de Sincronização', 'art-image'),
        function() {
            echo '<p>Configure o comportamento da sincronização automática de dados.</p>';
        },
        'art_image_settings'
    );

    register_setting('art_image_settings_group', 'artimage_enable_cleanup');
    register_setting('art_image_settings_group', 'artimage_cleanup_dry_run');

    add_settings_field(
        'artimage_enable_cleanup',
        __('Ativar Limpeza Automática', 'art-image'),
        function () {
            $checked = checked(get_option('artimage_enable_cleanup', '1'), '1', false);
            echo "<input type='checkbox' name='artimage_enable_cleanup' value='1' $checked /> ";
            echo "<span>Remover automaticamente itens que não estão mais na fonte externa</span>";
            echo "<p class='description'>Se ativado, produtos, categorias e artistas que não forem encontrados durante a importação serão removidos do site.</p>";
        },
        'art_image_settings',
        'art_image_sync_section',
        ['label_for' => 'artimage_enable_cleanup']
    );

    add_settings_field(
        'artimage_cleanup_dry_run',
        __('Modo de Teste (Dry Run)', 'art-image'),
        function () {
            $checked = checked(get_option('artimage_cleanup_dry_run', '0'), '1', false);
            echo "<input type='checkbox' name='artimage_cleanup_dry_run' value='1' $checked /> ";
            echo "<span>Apenas simular a remoção (não remover realmente)</span>";
            echo "<p class='description'>Se ativado, a limpeza será apenas simulada e registrada nos logs, sem remover os itens.</p>";
        },
        'art_image_settings',
        'art_image_sync_section',
        ['label_for' => 'artimage_cleanup_dry_run']
    );
});
