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

require_once ART_IMAGE_PLUGIN_DIR . 'includes/loader.php';
