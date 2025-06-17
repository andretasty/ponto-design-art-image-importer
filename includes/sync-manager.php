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
            // Agenda para rodar todos os dias às 02:00
            wp_schedule_event(strtotime('tomorrow 02:00:00'), 'daily', 'art_image_daily_sync');
        }
    }

    /**
     * Executa a sincronização completa
     */
    public function run_sync() {
        $this->log('Iniciando sincronização...');
        
        // Sincroniza categorias
        $this->sync_categories();
        
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
        $log_message = "[{$timestamp}] {$message}\n";
        error_log($log_message, 3, $this->log_file);
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