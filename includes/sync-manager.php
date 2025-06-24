<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador de sincronização completa do site
 */
class ArtImageSyncManager {
    private $importer;
    private $log_file;

    public function __construct() {
        $this->importer = new ArtImageImporter();
        $this->log_file = WP_CONTENT_DIR . '/art-image-sync.log';
        
        // Registra o evento de sincronização
        add_action('init', array($this, 'schedule_sync'));
        add_action('art_image_daily_sync', array($this, 'run_sync'));
    }

    /**
     * Agenda a sincronização diária
     */
    public function schedule_sync() {
        if (!wp_next_scheduled('art_image_daily_sync')) {
            // Calcula o próximo horário de execução considerando o fuso horário do WordPress
            $timezone = wp_timezone();
            $now = new DateTime('now', $timezone);
            $next_run = new DateTime('tomorrow 02:00:00', $timezone);
            
            // Se já passou das 02:00 hoje, agenda para amanhã
            if ($now->format('H:i') >= '02:00') {
                $next_run = new DateTime('tomorrow 02:00:00', $timezone);
            } else {
                // Se ainda não passou das 02:00, agenda para hoje
                $next_run = new DateTime('today 02:00:00', $timezone);
            }
            
            // Converte para timestamp UTC para o WordPress
            $timestamp = $next_run->getTimestamp();
            
            // Agenda para rodar todos os dias às 02:00 (horário de Brasília)
            wp_schedule_event($timestamp, 'daily', 'art_image_daily_sync');
            
            // Log para debug
            $this->log('Sincronização agendada para: ' . $next_run->format('Y-m-d H:i:s T'));
        }
    }

    /**
     * Executa a sincronização completa
     */
    public function run_sync() {
        $this->log('Iniciando sincronização...');
        
        // Sincroniza categorias principais
        $this->sync_categories();
        
        // Sincroniza subcategorias
        $this->sync_subcategories();
        
        // Sincroniza artistas
        $this->sync_artists();
        
        // Sincroniza produtos
        $this->sync_products();
        
        $this->log('Sincronização concluída.');
    }

    /**
     * Sincroniza categorias principais
     */
    private function sync_categories() {
        $page = 1;
        do {
            $result = $this->importer->import_categories($page);
            $this->log('Processando categorias - página ' . $page . ': ' . json_encode($result));
            $page++;
        } while ($result['has_more'] && $result['status'] !== 'error');
    }

    /**
     * Sincroniza subcategorias
     */
    private function sync_subcategories() {
        $page = 1;
        do {
            $result = $this->importer->import_subcategories($page);
            $this->log('Processando subcategorias - página ' . $page . ': ' . json_encode($result));
            $page++;
        } while ($result['has_more'] && $result['status'] !== 'error');
    }

    /**
     * Sincroniza artistas
     */
    private function sync_artists() {
        $page = 1;
        do {
            $result = $this->importer->import_artists($page);
            $this->log('Processando artistas - página ' . $page . ': ' . json_encode($result));
            $page++;
        } while ($result['has_more'] && $result['status'] !== 'error');
    }

    /**
     * Sincroniza produtos
     */
    private function sync_products() {
        // Primeiro prepara a fila de produtos
        $current_sub_index = 0;
        do {
            $result = $this->importer->prepare_product_import_queue_batch($current_sub_index);
            $this->log('Preparando fila de produtos - subcategoria ' . $current_sub_index . ': ' . json_encode($result));
            $current_sub_index++;
        } while ($result['has_more'] && $result['status'] !== 'error');

        // Depois processa os produtos
        $page = 1;
        do {
            $result = $this->importer->import_products_batch($page);
            $this->log('Processando produtos - página ' . $page . ': ' . json_encode($result));
            $page++;
        } while ($result['has_more'] && $result['status'] !== 'error');
    }

    /**
     * Registra mensagem no log
     */
    private function log($message) {
        $timestamp = current_time('Y-m-d H:i:s');
        $timezone_name = wp_timezone_string();
        $log_message = "[{$timestamp} {$timezone_name}] {$message}\n";
        error_log($log_message, 3, $this->log_file);
    }

    /**
     * Obtém o próximo horário de execução no fuso horário correto
     */
    public static function get_next_execution_time($hour = '02:00') {
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        $target_time = new DateTime('today ' . $hour, $timezone);
        
        // Se já passou do horário hoje, agenda para amanhã
        if ($now >= $target_time) {
            $target_time = new DateTime('tomorrow ' . $hour, $timezone);
        }
        
        return $target_time;
    }

    /**
     * Verifica se é o horário correto para execução
     */
    public static function is_execution_time($configured_time = '02:00') {
        $current_time = current_time('H:i');
        return $current_time === $configured_time;
    }

    /**
     * Obtém informações sobre o próximo agendamento
     */
    public function get_next_sync_info() {
        $next_scheduled = wp_next_scheduled('art_image_daily_sync');
        if (!$next_scheduled) {
            return ['status' => 'not_scheduled', 'message' => 'Sincronização não agendada'];
        }
        
        $timezone = wp_timezone();
        $next_date = new DateTime('@' . $next_scheduled);
        $next_date->setTimezone($timezone);
        
        return [
            'status' => 'scheduled',
            'next_run' => $next_date->format('Y-m-d H:i:s'),
            'timezone' => wp_timezone_string(),
            'timestamp' => $next_scheduled
        ];
    }
}

// Inicializa o gerenciador de sincronização
function art_image_init_sync_manager() {
    new ArtImageSyncManager();
}
add_action('plugins_loaded', 'art_image_init_sync_manager');

// Função para executar a sincronização manualmente
function art_image_run_sync() {
    $manager = new ArtImageSyncManager();
    return $manager->run_sync();
} 