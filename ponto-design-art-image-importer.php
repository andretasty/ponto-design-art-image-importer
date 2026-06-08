<?php
/**
 * Plugin Name: Art Image
 * Plugin URI: https://unitedweb.com.br/
 * Description: Importador automático e manual de categorias, produtos e artistas de uma fonte externa, com agendamento e logs em tempo real.
 * Version: 1.0.0
 * Author: André Schmidt
 * Author URI: https://unitedweb.com.br
 * License: GPL2
 * Text Domain: art-image
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Segurança: impede acesso direto ao arquivo
}

define('ART_IMAGE_VERSION', '1.0.0');
define('ART_IMAGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ART_IMAGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ART_IMAGE_PLUGIN_FILE', __FILE__);

/**
 * Configurações padrão do agendamento
 * Podem ser sobrescritas definindo constantes no wp-config.php
 */
if (!defined('ART_IMAGE_SCHEDULE_FREQUENCY')) {
    define('ART_IMAGE_SCHEDULE_FREQUENCY', 'weekly');
}
if (!defined('ART_IMAGE_SCHEDULE_DAY')) {
    define('ART_IMAGE_SCHEDULE_DAY', 'saturday');
}
if (!defined('ART_IMAGE_SCHEDULE_TIME')) {
    define('ART_IMAGE_SCHEDULE_TIME', '23:00');
}
if (!defined('ART_IMAGE_ENABLE_DISCOUNT')) {
    define('ART_IMAGE_ENABLE_DISCOUNT', true);
}

/**
 * Filtros para usar valores do wp-config.php quando definidos
 *
 * Para usar credenciais do wp-config.php, adicione ao seu wp-config.php:
 * define('ART_IMAGE_EMAIL', 'seu-email@exemplo.com');
 * define('ART_IMAGE_PASSWORD', 'sua-senha-aqui');
 */
if (defined('ART_IMAGE_EMAIL')) {
    add_filter('pre_option_art_image_email', function() { return ART_IMAGE_EMAIL; });
}
if (defined('ART_IMAGE_PASSWORD')) {
    add_filter('pre_option_art_image_password', function() { return ART_IMAGE_PASSWORD; });
}

// Filtros para configurações de agendamento (sempre aplicados)
add_filter('pre_option_art_image_schedule_frequency', function() { return ART_IMAGE_SCHEDULE_FREQUENCY; });
add_filter('pre_option_art_image_schedule_day', function() { return ART_IMAGE_SCHEDULE_DAY; });
add_filter('pre_option_art_image_schedule_time', function() { return ART_IMAGE_SCHEDULE_TIME; });
add_filter('pre_option_art_image_enable_discount', function() { return ART_IMAGE_ENABLE_DISCOUNT ? '1' : '0'; });

require_once ART_IMAGE_PLUGIN_DIR . 'includes/loader.php';

/**
 * Otimizações do Action Scheduler para melhor performance
 * Aumenta a capacidade de processamento de actions por ciclo
 */
add_filter('action_scheduler_queue_runner_batch_size', function($batch_size) {
    return 50; // Padrão é 25, aumentamos para 50
});

add_filter('action_scheduler_queue_runner_time_limit', function($time_limit) {
    return 120; // Padrão é 30 segundos, aumentamos para 2 minutos
});

// Permite mais batches concorrentes (cuidado com uso de recursos)
add_filter('action_scheduler_queue_runner_concurrent_batches', function($concurrent) {
    return 3; // Padrão é 1, aumentamos para 3
});

// Desabilita o limite de claims (permite processar mais actions)
add_filter('action_scheduler_claim_actions_limit', function($limit) {
    return 50; // Padrão é 20
});
