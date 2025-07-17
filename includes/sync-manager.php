<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador de sincronização completa do site - Versão Refatorada para CRON
 */
class ArtImageSyncManager {
    private $importer;
    private $log_file;

    const SYNC_STEP_OPTION = 'artimage_current_sync_step';
    const SYNC_PAGE_OPTION = 'artimage_current_sync_page';
    const LOCK_OPTION = 'artimage_sync_lock';
    const LOCK_TIMEOUT = 43200; // 12 horas de timeout

    public function __construct() {
        $this->importer = new ArtImageImporter();
        $this->log_file = WP_CONTENT_DIR . '/art-image-sync.log';
        
        add_action('art_image_daily_sync', array($this, 'trigger_sync_start'));
        add_action('art_image_run_sync_step', array($this, 'run_sync_step'));
    }

    /**
     * Verifica se o lock está ativo e válido
     */
    private function is_lock_active() {
        $lock = get_option(self::LOCK_OPTION, false);
        
        if (!$lock) {
            return false;
        }
        
        // Verifica se o lock expirou
        if (is_numeric($lock) && (time() - $lock) > self::LOCK_TIMEOUT) {
            $this->log("Lock expirado detectado (criado há " . (time() - $lock) . " segundos). Removendo...");
            delete_option(self::LOCK_OPTION);
            return false;
        }
        
        return true;
    }

    /**
     * Inicia a sincronização completa, definindo o primeiro passo.
     */
    public function trigger_sync_start() {
        // Evita múltiplas execuções simultâneas
        if ($this->is_lock_active()) {
            $lock_time = get_option(self::LOCK_OPTION, 'desconhecido');
            $this->log("Tentativa de iniciar sincronização enquanto outra já está em andamento. Lock criado em: " . date('Y-m-d H:i:s', $lock_time));
            return;
        }

        $this->log("Sincronização diária iniciada.");
        update_option(self::LOCK_OPTION, time());

        // Limpa qualquer estado anterior
        delete_option(self::SYNC_STEP_OPTION);
        delete_option(self::SYNC_PAGE_OPTION);

        $this->set_sync_step('categories', 1);
        $this->log("Passo inicial definido: categories, página 1");
        
        $this->schedule_next_step();
    }
    
    /**
     * Define o passo e a página atual da sincronização.
     */
    private function set_sync_step($step, $page = 1) {
        update_option(self::SYNC_STEP_OPTION, $step);
        update_option(self::SYNC_PAGE_OPTION, $page);
        $this->log("Passo definido: {$step}, página: {$page}");
    }
    
    /**
     * Agenda a próxima execução do processo de sincronização.
     */
    private function schedule_next_step($delay_in_seconds = 2) {
        $next_time = time() + $delay_in_seconds;
        $result = wp_schedule_single_event($next_time, 'art_image_run_sync_step');
        
        if ($result === false) {
            $this->log("ERRO: Falha ao agendar próximo passo com wp_schedule_single_event. Tentando execução imediata.");
            // Fallback: executa imediatamente se o agendamento falhar
            wp_schedule_single_event(time() + 1, 'art_image_run_sync_step');
        } else {
            $this->log("Próximo passo agendado para: " . date('Y-m-d H:i:s', $next_time) . " (" . $delay_in_seconds . "s)");
        }
        
        // Força verificação de cron
        spawn_cron();
    }

    /**
     * Executa o processo de sincronização em um loop contínuo com controle de tempo.
     */
    public function run_sync_step() {
        // Verifica se há um lock ativo antes de executar
        if (!$this->is_lock_active()) {
            $this->log("Nenhum lock ativo encontrado ao executar run_sync_step. Abortando.");
            $this->clear_pending_events();
            return;
        }

        // Atualiza o lock para estender o tempo de execução
        update_option(self::LOCK_OPTION, time());
        $this->log("Lock de sincronização atualizado para evitar expiração.");

        $this->log("=== EXECUTANDO PASSO DE SINCRONIZAÇÃO ===");
        
        $step = get_option(self::SYNC_STEP_OPTION, 'done');
        $page = (int) get_option(self::SYNC_PAGE_OPTION, 1);
    
        if ($step === 'done') {
            $this->log("Sincronização já concluída. Finalizando.");
            $this->complete_sync();
            return;
        }
        
        $this->log("Executando passo: '{$step}', página: {$page}");

        try {
            switch ($step) {
                case 'categories':
                    $result = $this->importer->import_categories($page, 1000);
                    $this->handle_step_result($result, 'subcategories', 'Categorias');
                    break;
                case 'subcategories':
                    $result = $this->importer->import_subcategories($page, 1000);
                    $this->handle_step_result($result, 'artists', 'Subcategorias');
                    break;
                case 'artists':
                    $result = $this->importer->import_artists($page, 1000);
                    $this->handle_step_result($result, 'prepare_products', 'Artistas');
                    break;
                case 'prepare_products':
                    $result = $this->importer->prepare_product_import_queue_batch($page);
                    $this->handle_product_prep_result($result);
                    break;
                case 'products':
                    art_image_log_product_processing('SYNC-MANAGER', 'CASE_PRODUCTS_START');
                    $batch_size = apply_filters('art_image_product_import_batch_size', 5);
                    $result = $this->importer->import_products_batch($page, $batch_size);
                    art_image_log_product_processing('SYNC-MANAGER', 'CASE_PRODUCTS_END', ['has_more' => $result['has_more'] ?? 'unknown']);
                    $this->handle_step_result($result, 'done', 'Produtos');
                    break;
                default:
                    $this->log("Passo desconhecido '{$step}'. Finalizando.");
                    $this->set_sync_step('done');
                    break;
            }
        } catch (Exception $e) {
            $this->log("ERRO CRÍTICO durante o passo '{$step}': " . $e->getMessage());
            $this->complete_sync(true); // Finaliza com erro
            return; // Encerra a execução
        }

        // Após executar o passo, verifica se a sincronização deve continuar ou parar
        $current_step_after_run = get_option(self::SYNC_STEP_OPTION, 'done');
        if ($current_step_after_run === 'done') {
            $this->log("Processo de sincronização concluído. Finalizando.");
            $this->complete_sync();
        } else {
            $this->log("Passo concluído. Agendando a continuação em 5 segundos.");
            $this->schedule_next_step(5);
        }
    }
    
    /**
     * Manipula o resultado de um passo de importação padrão.
     */
    private function handle_step_result($result, $next_step_name, $log_name) {
        $this->log("Resultado de {$log_name}: " . json_encode($result));
        
        $current_step = get_option(self::SYNC_STEP_OPTION);
        $current_page = (int) get_option(self::SYNC_PAGE_OPTION, 1);

            if (!empty($result['has_more']) && $result['status'] === 'processing') {
            $this->set_sync_step($current_step, $current_page + 1);
            } else {
            $this->log("Finalizada a importação de {$log_name}. Próximo passo: {$next_step_name}");
            // A preparação de produtos deve começar do índice 0, os outros passos da página 1.
            $next_page = ($next_step_name === 'prepare_products') ? 0 : 1;
            $this->set_sync_step($next_step_name, $next_page);
        }
    }

    /**
     * Manipula o resultado do passo de preparação de produtos.
     */
    private function handle_product_prep_result($result) {
        $this->log("Resultado da preparação de produtos: " . json_encode($result));

        if (!empty($result['has_more']) && $result['status'] === 'preparing') {
            $this->set_sync_step('prepare_products', $result['current_sub_index']);
        } else {
            $queue_size = get_transient('artimage_product_import_total');
            $this->log("Preparação da fila de produtos concluída. Total: {$queue_size} produtos. Próximo passo: products");
            $this->set_sync_step('products', 1);
        }
    }
    
    /**
     * Agenda o evento principal se não existir.
     */
    public static function schedule_daily_sync() {
        if (!wp_next_scheduled('art_image_daily_sync')) {
            $schedule_time = get_option('art_image_schedule_time', '02:00');
            $timestamp = ArtImageTimezoneHelper::get_next_execution_time($schedule_time)->getTimestamp();
            
            wp_schedule_event($timestamp, 'daily', 'art_image_daily_sync');
            error_log('Sincronização diária agendada para: ' . date('Y-m-d H:i:s', $timestamp));
        }
    }

    /**
     * Método para forçar execução imediata (para debug)
     */
    public function force_immediate_execution() {
        $this->log("=== EXECUÇÃO FORÇADA INICIADA ===");
        
        // Verifica se há um processo em andamento
        $current_step = get_option(self::SYNC_STEP_OPTION, 'none');
        $current_page = get_option(self::SYNC_PAGE_OPTION, 1);
        
        $this->log("Estado atual: step='{$current_step}', page={$current_page}");
        
        if ($current_step === 'none' || $current_step === 'done') {
            $this->log("Nenhum processo em andamento. Iniciando novo processo...");
            $this->trigger_sync_start();
        } else {
            $this->log("Processo em andamento detectado. Continuando do passo atual...");
            $this->run_sync_step();
        }
    }
    
    /**
     * Testa se o WP-Cron está funcionando
     */
    public function test_wp_cron() {
        $test_hook = 'art_image_cron_test';
        $test_time = time() + 30; // 30 segundos no futuro
        
        // Remove qualquer teste anterior
        wp_clear_scheduled_hook($test_hook);
        
        // Agenda teste
        $result = wp_schedule_single_event($test_time, $test_hook);
        
        if ($result === false) {
            $this->log("ERRO: wp_schedule_single_event falhou no teste");
            return false;
        }
        
        // Verifica se foi agendado
        $scheduled = wp_next_scheduled($test_hook);
        if (!$scheduled) {
            $this->log("ERRO: Evento de teste não foi agendado corretamente");
            return false;
        }
        
        $this->log("Teste de WP-Cron agendado para: " . date('Y-m-d H:i:s', $scheduled));
        
        // Limpa o teste
        wp_unschedule_event($scheduled, $test_hook);
        
        return true;
    }

    /**
     * Completa a sincronização e limpa os recursos
     */
    private function complete_sync($error = false) {
        if ($error) {
            $this->log("Sincronização finalizada com ERRO.");
        } else {
            $this->log("Processo finalizado com sucesso.");
        }
        
        delete_option(self::LOCK_OPTION);
        delete_option(self::SYNC_STEP_OPTION);
        delete_option(self::SYNC_PAGE_OPTION);
        
        // Limpa qualquer evento pendente
        $this->clear_pending_events();
        
        $this->log("Lock e estado de sincronização limpos.");
        
        // Garante que o próximo evento diário está agendado
        if (!$error) {
            self::schedule_daily_sync();
            $next_scheduled = wp_next_scheduled('art_image_daily_sync');
            if ($next_scheduled) {
                $this->log("Próxima sincronização agendada para: " . date('Y-m-d H:i:s', $next_scheduled));
            }
        }
    }

    /**
     * Limpa eventos pendentes de sincronização
     */
    private function clear_pending_events() {
        $timestamp = wp_next_scheduled('art_image_run_sync_step');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'art_image_run_sync_step');
            $timestamp = wp_next_scheduled('art_image_run_sync_step');
        }
    }

    /**
     * Registra mensagem no log.
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $message_with_timestamp = "[{$timestamp}] {$message}";
        error_log($message_with_timestamp . "\n", 3, $this->log_file);
        
        // Também registra no log de importação regular
        ArtImageTimezoneHelper::log_with_timezone($message);
    }

    /**
     * Limpa lock e estado (para uso administrativo)
     */
    public function force_clear_lock() {
        $this->log("=== LIMPEZA FORÇADA DE LOCK ===");
        $this->complete_sync();
        return true;
    }

    /**
     * Remove todos os eventos legacy e garante configuração correta
     */
    public static function cleanup_legacy_events() {
        $log_message = "=== LIMPEZA DE EVENTOS LEGACY ===\n";
        
        // Remove eventos do sistema antigo (cron.php)
        $timestamp = wp_next_scheduled('art_image_daily_event');
        $count = 0;
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'art_image_daily_event');
            $count++;
            $timestamp = wp_next_scheduled('art_image_daily_event');
        }
        if ($count > 0) {
            $log_message .= "Removidos {$count} eventos 'art_image_daily_event'\n";
        }
        
        // Remove qualquer hook registrado para o evento legacy
        remove_all_actions('art_image_daily_event');
        
        // Garante que o evento correto está agendado
        if (!wp_next_scheduled('art_image_daily_sync')) {
            self::schedule_daily_sync();
            $log_message .= "Evento 'art_image_daily_sync' reagendado corretamente\n";
        } else {
            $log_message .= "Evento 'art_image_daily_sync' já está agendado\n";
        }
        
        ArtImageTimezoneHelper::log_with_timezone($log_message);
        
        return $log_message;
    }
}

// Inicializa o gerenciador
add_action('plugins_loaded', function() {
    new ArtImageSyncManager();
});

// Adiciona o agendamento na ativação do plugin ou ao verificar
add_action('init', ['ArtImageSyncManager', 'schedule_daily_sync']);

// Função para executar a sincronização manualmente
function art_image_run_sync() {
    $manager = new ArtImageSyncManager();
    return $manager->run_sync();
} 