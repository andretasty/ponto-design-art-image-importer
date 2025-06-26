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
        
        // Sincroniza categorias
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
        $this->log('Iniciando sincronização de produtos...');
        
        // Limpa qualquer fila anterior para garantir uma nova preparação
        delete_transient('artimage_product_import_queue');
        delete_transient('artimage_product_processed_count');
        delete_transient('artimage_product_import_total');
        
        // Primeiro prepara a fila de produtos
        $current_sub_index = 0;
        $max_subcategories = 200; // Limite de segurança aumentado
        
        $this->log('Preparando fila de produtos...');
        do {
            $result = $this->importer->prepare_product_import_queue_batch($current_sub_index);
            $this->log('Preparando fila - índice ' . $current_sub_index . ', status: ' . ($result['status'] ?? 'unknown'));
            
            if (isset($result['current_sub_index'])) {
                $current_sub_index = $result['current_sub_index'];
            } else {
                $current_sub_index++;
            }
            
            // Proteção contra loop infinito
            if ($current_sub_index > $max_subcategories) {
                $this->log('AVISO: Limite de subcategorias atingido durante preparação');
                break;
            }
            
        } while (isset($result['has_more']) && $result['has_more'] === true && $result['status'] !== 'error' && $result['status'] !== 'cancelled');

        // Verifica se a fila foi preparada
        $queue_size = get_transient('artimage_product_import_total');
        $this->log('Fila preparada com ' . $queue_size . ' produtos');
        
        if (!$queue_size || $queue_size == 0) {
            $this->log('Nenhum produto na fila para processar');
            return;
        }

        // Depois processa os produtos
        $page = 1;
        $batch_size = 5; // O mesmo tamanho de lote usado no loop
        $max_pages = ceil($queue_size / $batch_size) + 10; // Calcula dinamicamente com uma margem de segurança
        
        $this->log('Iniciando processamento de produtos... Limite de páginas calculado: ' . $max_pages);
        do {
            $result = $this->importer->import_products_batch($page, $batch_size);
            
            $status = $result['status'] ?? 'unknown';
            $has_more = isset($result['has_more']) ? $result['has_more'] : false;
            $processed = $result['current_total_processed'] ?? 0;
            $total = $result['total_to_import'] ?? 0;
            
            $this->log("Página {$page}: status={$status}, processados={$processed}/{$total}, has_more=" . ($has_more ? 'true' : 'false'));
            
            // Verifica se deve continuar
            if ($has_more === true && $status === 'processing') {
                $page++;
            } else {
                $this->log("Finalizando loop: status={$status}, has_more=" . ($has_more ? 'true' : 'false'));
                break;
            }
            
            // Proteção contra loop infinito
            if ($page > $max_pages) {
                $this->log('AVISO: Limite de páginas atingido durante processamento');
                break;
            }
            
            // Pequena pausa para não sobrecarregar
            sleep(1);
            
        } while (true);
        
        $this->log('Sincronização de produtos concluída');
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