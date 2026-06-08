<?php
/**
 * Sistema de Cron Dedicado para Art Image
 *
 * Este sistema processa a sincronização de forma independente,
 * sem competir com outros plugins na fila do Action Scheduler.
 *
 * Usa o cron do servidor (wget a cada minuto) para processar
 * batches de produtos de forma eficiente.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ArtImageDedicatedCron {

    /**
     * Tamanho do batch de produtos
     */
    const BATCH_SIZE = 20;

    /**
     * Tempo máximo de execução por ciclo (segundos)
     */
    const MAX_EXECUTION_TIME = 50;

    /**
     * Opções para controle de estado
     */
    const STATE_OPTION = 'artimage_dc_state';
    const QUEUE_OPTION = 'artimage_dc_queue';
    const LOCK_OPTION = 'artimage_dc_lock';
    const STATS_OPTION = 'artimage_dc_stats';

    private $importer;
    private $client;
    private $start_time;

    public function __construct() {
        $this->start_time = time();

        // Hook para o WP-Cron (chamado pelo cron do servidor)
        add_action('artimage_dedicated_cron', [$this, 'process']);

        // Garantir que o evento está agendado
        add_action('init', [$this, 'ensure_scheduled']);

        // AJAX handlers para controle manual
        add_action('wp_ajax_artimage_dc_start', [$this, 'ajax_start']);
        add_action('wp_ajax_artimage_dc_stop', [$this, 'ajax_stop']);
        add_action('wp_ajax_artimage_dc_status', [$this, 'ajax_status']);
    }

    /**
     * Garante que o evento de cron está agendado
     */
    public function ensure_scheduled() {
        if (!wp_next_scheduled('artimage_dedicated_cron')) {
            wp_schedule_event(time(), 'every_minute', 'artimage_dedicated_cron');
        }
    }

    /**
     * Registra intervalo de 1 minuto para WP-Cron
     */
    public static function register_interval($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => 'A cada minuto'
        ];
        return $schedules;
    }

    /**
     * Processa o próximo lote de trabalho
     */
    public function process() {
        // Verificar lock (evita execuções simultâneas)
        if ($this->is_locked()) {
            $this->log("Processo já em execução, aguardando...");
            return;
        }

        // Adquirir lock
        $this->acquire_lock();

        try {
            $state = $this->get_state();

            // Se não está rodando, não fazer nada
            if ($state['status'] !== 'running') {
                $this->release_lock();
                return;
            }

            $this->log("=== Ciclo de processamento iniciado ===");
            $this->log("Fase atual: {$state['phase']}");

            // Processar baseado na fase atual
            switch ($state['phase']) {
                case 'categories':
                    $this->process_categories($state);
                    break;

                case 'subcategories':
                    $this->process_subcategories($state);
                    break;

                case 'artists':
                    $this->process_artists($state);
                    break;

                case 'prepare_products':
                    $this->process_prepare_products($state);
                    break;

                case 'products':
                    $this->process_products($state);
                    break;

                case 'complete':
                    $this->complete_sync($state);
                    break;

                default:
                    $this->log("Fase desconhecida: {$state['phase']}");
                    $this->update_state(['status' => 'error', 'error' => 'Fase desconhecida']);
            }

        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage());
            $this->update_state(['status' => 'error', 'error' => $e->getMessage()]);
        }

        $this->release_lock();
    }

    /**
     * Processa categorias
     */
    private function process_categories($state) {
        $this->init_client();

        $categories = $this->client->get_main_categories();
        $this->log("Encontradas " . count($categories) . " categorias");

        $this->init_importer();
        foreach ($categories as $cat) {
            $result = $this->importer->import_single_category($cat);
            if (!$result['success']) {
                $this->log("Erro categoria {$cat['nome']}: " . ($result['error'] ?? 'desconhecido'));
            }
        }

        $this->update_stats('categories', count($categories));
        $this->advance_phase('subcategories');
    }

    /**
     * Processa subcategorias
     */
    private function process_subcategories($state) {
        $this->init_client();
        $this->init_importer();

        // Buscar categorias pai
        $parent_cats = get_terms([
            'taxonomy' => 'product_cat',
            'parent' => 0,
            'hide_empty' => false
        ]);

        $total = 0;
        foreach ($parent_cats as $parent) {
            if ($this->is_time_exceeded()) {
                $this->log("Tempo excedido, continuando no próximo ciclo");
                return;
            }

            $url = "https://artimage.com.br/produtos/{$parent->slug}";
            $subs = $this->client->get_subcategories($url);

            foreach ($subs as $sub) {
                $sub['parent_id'] = $parent->term_id;
                $sub['parent_slug'] = $parent->slug;
                $result = $this->importer->import_single_subcategory($sub);
                $total++;
            }
        }

        $this->update_stats('subcategories', $total);
        $this->log("Processadas {$total} subcategorias");
        $this->advance_phase('artists');
    }

    /**
     * Processa artistas
     */
    private function process_artists($state) {
        $this->init_client();
        $this->init_importer();

        $artists = $this->client->get_artists('https://artimage.com.br/artistas');
        $this->log("Encontrados " . count($artists) . " artistas");

        foreach ($artists as $artist) {
            if ($this->is_time_exceeded()) {
                $this->log("Tempo excedido, continuando no próximo ciclo");
                return;
            }

            $result = $this->importer->import_single_artist($artist);
        }

        $this->update_stats('artists', count($artists));
        $this->advance_phase('prepare_products');
    }

    /**
     * Prepara a fila de produtos
     */
    private function process_prepare_products($state) {
        $this->init_client();

        // Buscar todas as subcategorias
        $subcategories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ]);

        $subcategories = array_filter($subcategories, function($t) {
            return $t->parent != 0;
        });

        $this->log("Coletando produtos de " . count($subcategories) . " subcategorias");

        $queue = [];
        $processed_subs = $state['processed_subs'] ?? [];

        foreach ($subcategories as $sub) {
            // Pular subcategorias já processadas
            if (in_array($sub->term_id, $processed_subs)) {
                continue;
            }

            if ($this->is_time_exceeded()) {
                $this->log("Tempo excedido. Subcategorias processadas: " . count($processed_subs));
                $this->update_state(['processed_subs' => $processed_subs]);
                $this->save_queue($queue);
                return;
            }

            $parent = get_term($sub->parent, 'product_cat');
            $filtro_sub_id = get_term_meta($sub->term_id, 'filtro_sub_id', true);

            if (!$parent || !$filtro_sub_id) {
                $processed_subs[] = $sub->term_id;
                continue;
            }

            $url = "https://artimage.com.br/produtos/{$parent->slug}?filtro-sub={$filtro_sub_id}";
            $products = $this->client->get_products($url);

            foreach ($products as $product) {
                $queue[] = [
                    'product_data' => $product,
                    'subcategory_id' => $sub->term_id,
                    'subcategory_parent_id' => $sub->parent,
                ];
            }

            $this->log("Coletados " . count($products) . " produtos de: {$sub->name}");
            $processed_subs[] = $sub->term_id;
        }

        // Salvar fila
        $this->save_queue($queue);
        $this->update_stats('products_queued', count($queue));
        $this->log("Total de produtos na fila: " . count($queue));

        // Avançar para próxima fase se todas subcategorias foram processadas
        if (count($processed_subs) >= count($subcategories)) {
            $this->update_state(['processed_subs' => []]);
            $this->advance_phase('products');
        } else {
            $this->update_state(['processed_subs' => $processed_subs]);
        }
    }

    /**
     * Processa produtos em batches
     */
    private function process_products($state) {
        $this->init_importer();

        $queue = $this->get_queue();
        $total_queue = count($queue);

        if (empty($queue)) {
            $this->log("Fila de produtos vazia, finalizando");
            $this->advance_phase('complete');
            return;
        }

        $processed_this_cycle = 0;
        $batch = [];

        while (!empty($queue) && !$this->is_time_exceeded()) {
            $batch[] = array_shift($queue);

            // Processar quando batch estiver cheio ou for o último
            if (count($batch) >= self::BATCH_SIZE || empty($queue)) {
                $result = $this->importer->import_products_batch_as($batch);

                $processed_this_cycle += count($batch);
                $this->log("Batch processado: {$result['imported']} novos, {$result['updated']} atualizados, {$result['failed']} falhas");

                $batch = [];
            }
        }

        // Salvar fila atualizada
        $this->save_queue($queue);

        // Atualizar estatísticas
        $stats = get_option(self::STATS_OPTION, []);
        $stats['products_processed'] = ($stats['products_processed'] ?? 0) + $processed_this_cycle;
        update_option(self::STATS_OPTION, $stats);

        $remaining = count($queue);
        $this->log("Ciclo: processados {$processed_this_cycle} | Restantes: {$remaining}");

        // Se fila vazia, avançar
        if (empty($queue)) {
            $this->advance_phase('complete');
        }
    }

    /**
     * Finaliza a sincronização
     */
    private function complete_sync($state) {
        $stats = get_option(self::STATS_OPTION, []);
        $stats['completed_at'] = current_time('mysql');

        $this->log("=== SINCRONIZAÇÃO COMPLETA ===");
        $this->log("Estatísticas: " . json_encode($stats));

        update_option(self::STATS_OPTION, $stats);

        $this->update_state([
            'status' => 'idle',
            'phase' => null,
            'completed_at' => current_time('mysql')
        ]);

        // Limpar fila
        delete_option(self::QUEUE_OPTION);
    }

    /**
     * Inicia uma nova sincronização
     */
    public function start() {
        if ($this->get_state()['status'] === 'running') {
            return ['success' => false, 'message' => 'Já existe uma sincronização em andamento'];
        }

        $this->log("=== INICIANDO SINCRONIZAÇÃO DEDICADA ===");

        // Resetar estado
        $this->update_state([
            'status' => 'running',
            'phase' => 'categories',
            'started_at' => current_time('mysql'),
            'processed_subs' => []
        ]);

        // Resetar estatísticas
        update_option(self::STATS_OPTION, [
            'started_at' => current_time('mysql'),
            'categories' => 0,
            'subcategories' => 0,
            'artists' => 0,
            'products_queued' => 0,
            'products_processed' => 0,
        ]);

        // Limpar fila anterior
        delete_option(self::QUEUE_OPTION);

        // Forçar execução imediata do primeiro ciclo
        $this->process();

        return ['success' => true, 'message' => 'Sincronização iniciada'];
    }

    /**
     * Para a sincronização
     */
    public function stop() {
        $this->log("=== SINCRONIZAÇÃO PARADA MANUALMENTE ===");

        $this->update_state([
            'status' => 'stopped',
            'stopped_at' => current_time('mysql')
        ]);

        return ['success' => true, 'message' => 'Sincronização parada'];
    }

    /**
     * Retorna o status atual
     */
    public function get_status() {
        $state = $this->get_state();
        $stats = get_option(self::STATS_OPTION, []);
        $queue_count = count($this->get_queue());

        return [
            'status' => $state['status'] ?? 'idle',
            'phase' => $state['phase'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'stats' => $stats,
            'queue_remaining' => $queue_count,
            'is_locked' => $this->is_locked(),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function get_state() {
        return get_option(self::STATE_OPTION, [
            'status' => 'idle',
            'phase' => null
        ]);
    }

    private function update_state($data) {
        $state = $this->get_state();
        $state = array_merge($state, $data);
        update_option(self::STATE_OPTION, $state);
    }

    private function advance_phase($next_phase) {
        $this->log("Avançando para fase: {$next_phase}");
        $this->update_state(['phase' => $next_phase]);
    }

    private function update_stats($key, $value) {
        $stats = get_option(self::STATS_OPTION, []);
        $stats[$key] = $value;
        update_option(self::STATS_OPTION, $stats);
    }

    private function get_queue() {
        return get_option(self::QUEUE_OPTION, []);
    }

    private function save_queue($queue) {
        update_option(self::QUEUE_OPTION, $queue, false); // false = não autoload
    }

    private function is_time_exceeded() {
        return (time() - $this->start_time) >= self::MAX_EXECUTION_TIME;
    }

    private function is_locked() {
        $lock = get_transient(self::LOCK_OPTION);
        return $lock !== false;
    }

    private function acquire_lock() {
        set_transient(self::LOCK_OPTION, time(), 300); // 5 minutos de timeout
    }

    private function release_lock() {
        delete_transient(self::LOCK_OPTION);
    }

    private function init_importer() {
        if (!$this->importer) {
            $this->importer = new ArtImageImporter();
        }
    }

    private function init_client() {
        if (!$this->client) {
            $this->client = new ArtImageApiClient();
        }
    }

    private function log($message) {
        ArtImageTimezoneHelper::log_with_timezone("[DC] " . $message);
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    public function ajax_start() {
        check_ajax_referer('art_image_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $result = $this->start();
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_stop() {
        check_ajax_referer('art_image_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $result = $this->stop();
        wp_send_json_success($result);
    }

    public function ajax_status() {
        check_ajax_referer('art_image_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        wp_send_json_success($this->get_status());
    }
}

// Registrar intervalo de cron
add_filter('cron_schedules', ['ArtImageDedicatedCron', 'register_interval']);

// Inicializar
add_action('plugins_loaded', function() {
    global $artimage_dedicated_cron;
    $artimage_dedicated_cron = new ArtImageDedicatedCron();
});
