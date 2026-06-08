<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador de sincronização completa do site - Versão Action Scheduler
 *
 * Esta versão usa Action Scheduler do WooCommerce para armazenar
 * os jobs em tabelas do banco de dados, garantindo persistência
 * e eliminando problemas com transients.
 */
class ArtImageSyncManager {
    private $importer;
    private $log_file;

    // Options para compatibilidade com sistema antigo
    const SYNC_STEP_OPTION = 'artimage_current_sync_step';
    const SYNC_PAGE_OPTION = 'artimage_current_sync_page';
    const LOCK_OPTION = 'artimage_sync_lock';
    const LOCK_TIMEOUT = 43200; // 12 horas de timeout

    public function __construct() {
        $this->importer = new ArtImageImporter();
        $this->log_file = WP_CONTENT_DIR . '/art-image-sync.log';

        // Hooks do sistema antigo (mantidos para compatibilidade)
        add_action('art_image_sync_event', array($this, 'trigger_sync_start'));
        add_action('art_image_run_sync_step', array($this, 'run_sync_step'));

        // Hooks do Action Scheduler (novo sistema)
        add_action('artimage_scheduled_sync', array($this, 'as_trigger_sync_start'));
        add_action('artimage_start_import_phase', array($this, 'as_start_phase'), 10, 2);
        add_action('artimage_complete_phase', array($this, 'as_check_phase_completion'), 10, 2);
        add_action('artimage_import_category', array($this, 'as_import_category'));
        add_action('artimage_import_subcategory', array($this, 'as_import_subcategory'));
        add_action('artimage_import_artist', array($this, 'as_import_artist'));
        add_action('artimage_import_product', array($this, 'as_import_product'));
        add_action('artimage_import_products_batch', array($this, 'as_import_products_batch')); // Novo: batches
        add_action('artimage_prepare_products_subcategory', array($this, 'as_prepare_products_subcategory'), 10, 2);
    }

    // =========================================================================
    // ACTION SCHEDULER - Novo sistema robusto
    // =========================================================================

    /**
     * Inicia sincronização via Action Scheduler
     */
    public function as_trigger_sync_start() {
        $this->log("[AS] === SINCRONIZAÇÃO INICIADA VIA ACTION SCHEDULER ===");

        // Verificar se já há uma sessão em andamento
        if (ArtImageASManager::is_sync_running()) {
            $this->log("[AS] Já existe uma sincronização em andamento. Abortando.");
            return;
        }

        // Limpar transients de fases anteriores (evita problemas de duplicação)
        $phases = ['categories', 'subcategories', 'artists', 'prepare_products', 'products'];
        foreach ($phases as $phase) {
            delete_transient("artimage_as_phase_{$phase}_started");
            delete_transient("artimage_as_phase_{$phase}_completed");
        }

        // Criar nova sessão
        $session_id = ArtImageASManager::start_session();

        // Iniciar sessão de tracking para limpeza de órfãos
        global $artimage_sync_tracker;
        if (isset($artimage_sync_tracker) && $artimage_sync_tracker) {
            $artimage_sync_tracker->start_sync_session();
            $this->log("[AS] Sessão de tracking iniciada.");
        }

        // Iniciar primeira fase: categorias
        ArtImageASManager::schedule_phase_start('categories', $session_id);
    }

    /**
     * Inicia uma fase de importação
     */
    public function as_start_phase($phase, $session_id) {
        // Verificar se esta fase já foi iniciada (evita duplicação)
        $current_phase = get_option('artimage_as_current_phase');
        $phase_started_key = "artimage_as_phase_{$phase}_started";

        if (get_transient($phase_started_key)) {
            $this->log("[AS] Fase {$phase} já foi iniciada, ignorando duplicata");
            return;
        }

        // Marcar fase como iniciada (expira em 1 hora)
        set_transient($phase_started_key, true, HOUR_IN_SECONDS);

        $this->log("[AS] Iniciando fase: {$phase}");
        ArtImageASManager::set_current_phase($phase);

        $client = new ArtImageApiClient();

        switch ($phase) {
            case 'categories':
                $items = $client->get_main_categories();
                $this->log("[AS] Encontradas " . count($items) . " categorias para importar");

                foreach ($items as $item) {
                    ArtImageASManager::schedule_entity_import('category', $item);
                }

                update_option('artimage_as_total_categories', count($items));
                break;

            case 'subcategories':
                // Buscar subcategorias de cada categoria pai
                $parent_cats = get_terms([
                    'taxonomy' => 'product_cat',
                    'parent' => 0,
                    'hide_empty' => false
                ]);

                $total_subs = 0;
                foreach ($parent_cats as $parent) {
                    $url = "https://artimage.com.br/produtos/{$parent->slug}";
                    $subs = $client->get_subcategories($url);

                    foreach ($subs as $sub) {
                        $sub['parent_id'] = $parent->term_id;
                        $sub['parent_slug'] = $parent->slug;
                        ArtImageASManager::schedule_entity_import('subcategory', $sub);
                        $total_subs++;
                    }
                }

                $this->log("[AS] Agendadas {$total_subs} subcategorias para importação");
                update_option('artimage_as_total_subcategories', $total_subs);
                break;

            case 'artists':
                $artists = $client->get_artists('https://artimage.com.br/artistas');
                $this->log("[AS] Encontrados " . count($artists) . " artistas para importar");

                foreach ($artists as $artist) {
                    ArtImageASManager::schedule_entity_import('artist', $artist);
                }

                update_option('artimage_as_total_artists', count($artists));
                break;

            case 'prepare_products':
                // Para cada subcategoria, agendar coleta de produtos
                $subs = get_terms([
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false
                ]);

                $subs = array_filter($subs, function($t) {
                    return $t->parent != 0;
                });

                $this->log("[AS] Agendando coleta de produtos de " . count($subs) . " subcategorias");

                $delay = 0;
                foreach ($subs as $sub) {
                    ArtImageASManager::schedule_product_collection($sub->term_id, $session_id, $delay);
                    $delay += 3; // 3 segundos entre cada subcategoria
                }

                update_option('artimage_as_total_subcategories_to_scan', count($subs));
                break;

            case 'products':
                // Fase de produtos é iniciada automaticamente após prepare_products
                // Não precisa fazer nada aqui, os produtos já foram agendados
                $this->log("[AS] Fase de produtos - aguardando processamento das actions agendadas");
                break;
        }

        // Agendar verificação de conclusão da fase
        $check_delay = ($phase === 'prepare_products' || $phase === 'products') ? 60 : 30;
        ArtImageASManager::schedule_phase_check($phase, $session_id, $check_delay);
    }

    /**
     * Verifica se uma fase foi completada
     */
    public function as_check_phase_completion($phase, $session_id) {
        // Mapear fase para tipo de entidade
        $entity_map = [
            'categories' => 'category',
            'subcategories' => 'subcategory',
            'artists' => 'artist',
            'prepare_products' => null, // Verificação especial
            'products' => null, // Verificação especial (batches)
        ];

        $entity_type = $entity_map[$phase] ?? null;

        // Verificação especial para prepare_products
        if ($phase === 'prepare_products') {
            if (!ArtImageASManager::is_product_preparation_complete()) {
                $this->log("[AS] Preparação de produtos ainda em andamento, verificando novamente em 60s");
                ArtImageASManager::schedule_phase_check($phase, $session_id, 60);
                return;
            }
        // Verificação especial para products (inclui batches)
        } elseif ($phase === 'products') {
            if (!ArtImageASManager::is_products_phase_complete()) {
                $this->log("[AS] Fase produtos (batches) ainda em progresso, verificando novamente em 30s");
                ArtImageASManager::schedule_phase_check($phase, $session_id, 30);
                return;
            }
        } elseif ($entity_type && !ArtImageASManager::is_phase_complete($entity_type)) {
            $this->log("[AS] Fase {$phase} ainda em progresso, verificando novamente em 30s");
            ArtImageASManager::schedule_phase_check($phase, $session_id, 30);
            return;
        }

        // Verificar se já avançamos desta fase (evita duplicação)
        $phase_completed_key = "artimage_as_phase_{$phase}_completed";
        if (get_transient($phase_completed_key)) {
            $this->log("[AS] Fase {$phase} já foi completada anteriormente, ignorando duplicata");
            return;
        }

        // Marcar fase como completada
        set_transient($phase_completed_key, true, HOUR_IN_SECONDS);

        $this->log("[AS] Fase {$phase} completa!");

        // Determinar próxima fase
        $phases = ['categories', 'subcategories', 'artists', 'prepare_products', 'products'];
        $current_index = array_search($phase, $phases);

        if ($current_index === false) {
            $this->log("[AS] Fase desconhecida: {$phase}");
            return;
        }

        // Se é a última fase (products), finalizar sincronização
        if ($phase === 'products') {
            $this->as_complete_sync($session_id);
            return;
        }

        // Avançar para próxima fase
        $next_phase = $phases[$current_index + 1];
        $this->log("[AS] Avançando para fase: {$next_phase}");

        ArtImageASManager::schedule_phase_start($next_phase, $session_id);
    }

    /**
     * Importa uma categoria individual via Action Scheduler
     */
    public function as_import_category($data) {
        $result = $this->importer->import_single_category($data);

        if (!$result['success']) {
            $this->log("[AS] Erro ao importar categoria: " . ($result['error'] ?? 'desconhecido'));
        }
    }

    /**
     * Importa uma subcategoria individual via Action Scheduler
     */
    public function as_import_subcategory($data) {
        $result = $this->importer->import_single_subcategory($data);

        if (!$result['success']) {
            $this->log("[AS] Erro ao importar subcategoria: " . ($result['error'] ?? 'desconhecido'));
        }
    }

    /**
     * Importa um artista individual via Action Scheduler
     */
    public function as_import_artist($data) {
        $result = $this->importer->import_single_artist($data);

        if (!$result['success']) {
            $this->log("[AS] Erro ao importar artista: " . ($result['error'] ?? 'desconhecido'));
        }
    }

    /**
     * Importa um produto individual via Action Scheduler
     */
    public function as_import_product($data) {
        $result = $this->importer->import_single_product($data);

        if (!$result['success']) {
            $sku = $result['sku'] ?? 'unknown';
            $this->log("[AS] Erro ao importar produto {$sku}: " . ($result['error'] ?? 'desconhecido'));
        }

        // Log periódico de progresso
        $processed = (int) get_option('artimage_as_products_processed', 0) + 1;
        update_option('artimage_as_products_processed', $processed);

        if ($processed % 100 === 0) {
            $total = get_option('artimage_as_total_products', 0);
            $this->log("[AS] Produtos processados: {$processed}/{$total}");
        }
    }

    /**
     * Coleta produtos de uma subcategoria e agenda importação em BATCHES
     * Otimizado: agrupa produtos em batches para processar múltiplos por action
     */
    public function as_prepare_products_subcategory($subcategory_id, $session_id) {
        $result = $this->importer->collect_products_from_subcategory($subcategory_id);

        if (!$result['success']) {
            $this->log("[AS] Erro ao coletar produtos da subcategoria {$subcategory_id}: " . ($result['error'] ?? 'desconhecido'));
            return;
        }

        $products = $result['products'];
        $count = count($products);

        if ($count === 0) {
            $this->log("[AS] Nenhum produto encontrado em: " . ($result['subcategory_name'] ?? $subcategory_id));
            return;
        }

        // Agendar produtos em BATCHES (muito mais eficiente)
        $batches_scheduled = ArtImageASManager::schedule_product_batches($products);

        // Atualizar total de produtos
        $current_total = (int) get_option('artimage_as_total_products', 0);
        update_option('artimage_as_total_products', $current_total + $count);

        $this->log("[AS] Agendados {$count} produtos ({$batches_scheduled} batches) de: " . ($result['subcategory_name'] ?? $subcategory_id));
    }

    /**
     * Importa um BATCH de produtos via Action Scheduler (otimizado)
     * Processa múltiplos produtos em uma única action
     */
    public function as_import_products_batch($products) {
        $batch_size = count($products);
        $result = $this->importer->import_products_batch_as($products);

        // Log resumido do batch
        $imported = $result['imported'] ?? 0;
        $updated = $result['updated'] ?? 0;
        $failed = $result['failed'] ?? 0;

        // Atualizar contador de processados
        $processed = (int) get_option('artimage_as_products_processed', 0) + $batch_size;
        update_option('artimage_as_products_processed', $processed);

        // Log a cada 5 batches ou se houver erros
        if ($processed % (ArtImageASManager::PRODUCT_BATCH_SIZE * 5) < $batch_size || $failed > 0) {
            $total = get_option('artimage_as_total_products', 0);
            $this->log("[AS] Batch processado: {$imported} novos, {$updated} atualizados, {$failed} falhas | Total: {$processed}/{$total}");
        }

        // Log de erros específicos
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->log("[AS] Erro produto {$error['sku']}: {$error['error']}");
            }
        }
    }

    /**
     * Completa a sincronização via Action Scheduler
     */
    private function as_complete_sync($session_id) {
        $this->log("[AS] === SINCRONIZAÇÃO {$session_id} COMPLETA ===");

        // Finalizar sessão de tracking e executar limpeza
        global $artimage_sync_tracker;
        if (isset($artimage_sync_tracker) && $artimage_sync_tracker) {
            $cleanup_enabled = get_option('artimage_enable_cleanup', true);
            $dry_run = get_option('artimage_cleanup_dry_run', false);

            $this->log("[AS] Finalizando tracking (cleanup: " . ($cleanup_enabled ? 'sim' : 'não') . ")");

            $cleanup_results = $artimage_sync_tracker->finish_sync_session([
                'cleanup_enabled' => $cleanup_enabled,
                'dry_run' => $dry_run
            ]);

            if ($cleanup_enabled && !$dry_run) {
                $this->log("[AS] Limpeza: {$cleanup_results['products_deleted']} produtos, " .
                    "{$cleanup_results['categories_deleted']} categorias, " .
                    "{$cleanup_results['artists_deleted']} artistas removidos");
            }
        }

        // Estatísticas finais
        $stats = [
            'categories' => get_option('artimage_as_total_categories', 0),
            'subcategories' => get_option('artimage_as_total_subcategories', 0),
            'artists' => get_option('artimage_as_total_artists', 0),
            'products' => get_option('artimage_as_total_products', 0),
            'products_processed' => get_option('artimage_as_products_processed', 0),
        ];

        $this->log("[AS] Estatísticas: " . json_encode($stats));

        // Limpar contadores
        delete_option('artimage_as_total_categories');
        delete_option('artimage_as_total_subcategories');
        delete_option('artimage_as_total_artists');
        delete_option('artimage_as_total_products');
        delete_option('artimage_as_products_processed');
        delete_option('artimage_as_total_subcategories_to_scan');

        // Finalizar sessão
        ArtImageASManager::end_session();

        // Limpar actions completadas antigas
        ArtImageASManager::cleanup_completed(7);

        // Verificar próxima sincronização
        $next = ArtImageASManager::get_next_scheduled_sync();
        if ($next) {
            $this->log("[AS] Próxima sincronização agendada para: {$next}");
        } else {
            $this->log("[AS] AVISO: Próxima sincronização não está agendada. Reagendando...");
            ArtImageASManager::schedule_recurring_sync();
        }
    }

    // =========================================================================
    // SISTEMA ANTIGO - Mantido para compatibilidade com importação manual
    // =========================================================================

    /**
     * Verifica se o lock está ativo e válido
     */
    private function is_lock_active() {
        $lock = get_option(self::LOCK_OPTION, false);

        if (!$lock) {
            return false;
        }

        if (is_numeric($lock) && (time() - $lock) > self::LOCK_TIMEOUT) {
            $this->log("Lock expirado detectado. Removendo...");
            delete_option(self::LOCK_OPTION);
            return false;
        }

        return true;
    }

    /**
     * Inicia a sincronização via WP-Cron (sistema antigo)
     */
    public function trigger_sync_start() {
        // Redireciona para o Action Scheduler se disponível
        if (ArtImageASManager::is_available()) {
            $this->log("Redirecionando sincronização para Action Scheduler...");
            $this->as_trigger_sync_start();
            return;
        }

        // Fallback para sistema antigo
        $this->log("Action Scheduler não disponível. Usando sistema antigo.");

        if ($this->is_lock_active()) {
            $this->log("Sincronização já em andamento.");
            return;
        }

        $this->log("Sincronização iniciada (sistema antigo).");
        update_option(self::LOCK_OPTION, time());

        global $artimage_sync_tracker;
        if (isset($artimage_sync_tracker) && $artimage_sync_tracker) {
            $artimage_sync_tracker->start_sync_session();
        }

        delete_option(self::SYNC_STEP_OPTION);
        delete_option(self::SYNC_PAGE_OPTION);

        $this->set_sync_step('categories', 1);
        $this->schedule_next_step();
    }

    private function set_sync_step($step, $page = 1) {
        update_option(self::SYNC_STEP_OPTION, $step);
        update_option(self::SYNC_PAGE_OPTION, $page);
        $this->log("Passo definido: {$step}, página: {$page}");
    }

    private function schedule_next_step($delay_in_seconds = 2) {
        $next_time = time() + $delay_in_seconds;
        wp_schedule_single_event($next_time, 'art_image_run_sync_step');
        spawn_cron();
    }

    public function run_sync_step($execute_all_steps = false) {
        if (!$this->is_lock_active()) {
            $this->clear_pending_events();
            return;
        }

        update_option(self::LOCK_OPTION, time());

        $step = get_option(self::SYNC_STEP_OPTION, 'done');
        $page = (int) get_option(self::SYNC_PAGE_OPTION, 1);

        if ($step === 'done') {
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
                    $batch_size = apply_filters('art_image_product_import_batch_size', 5);
                    $result = $this->importer->import_products_batch($page, $batch_size);
                    $this->handle_step_result($result, 'done', 'Produtos');
                    break;
                default:
                    $this->set_sync_step('done');
                    break;
            }
        } catch (Exception $e) {
            $this->log("ERRO CRÍTICO: " . $e->getMessage());
            $this->complete_sync(true);
            return;
        }

        $current_step = get_option(self::SYNC_STEP_OPTION, 'done');
        if ($current_step === 'done') {
            $this->complete_sync();
        } else {
            if ($execute_all_steps) {
                $this->run_sync_step(true);
            } else {
                $this->schedule_next_step(5);
            }
        }
    }

    private function handle_step_result($result, $next_step_name, $log_name) {
        $this->log("Resultado de {$log_name}: " . json_encode($result));

        $current_step = get_option(self::SYNC_STEP_OPTION);
        $current_page = (int) get_option(self::SYNC_PAGE_OPTION, 1);

        if (!empty($result['has_more']) && $result['status'] === 'processing') {
            $this->set_sync_step($current_step, $current_page + 1);
        } else {
            $this->log("Finalizada a importação de {$log_name}. Próximo passo: {$next_step_name}");
            $next_page = ($next_step_name === 'prepare_products') ? 0 : 1;
            $this->set_sync_step($next_step_name, $next_page);
        }
    }

    private function handle_product_prep_result($result) {
        $this->log("Resultado da preparação de produtos: " . json_encode($result));

        if (!empty($result['has_more']) && $result['status'] === 'preparing') {
            $this->set_sync_step('prepare_products', $result['current_sub_index']);
        } else {
            $queue_size = get_transient('artimage_product_import_total');
            $this->log("Preparação concluída. Total: {$queue_size} produtos.");
            $this->set_sync_step('products', 1);
        }
    }

    /**
     * Agenda evento de sincronização (com suporte a Action Scheduler)
     */
    public static function schedule_sync_event() {
        // Usar Action Scheduler se disponível
        if (ArtImageASManager::is_available()) {
            ArtImageASManager::schedule_recurring_sync();
            return true;
        }

        // Fallback para WP-Cron
        if (!self::is_wp_cron_working()) {
            ArtImageTimezoneHelper::log_with_timezone('AVISO: WP-Cron pode não estar funcionando');
            return false;
        }

        $frequency = get_option('art_image_schedule_frequency', 'weekly');
        $hook_name = 'art_image_sync_event';

        wp_clear_scheduled_hook($hook_name);

        $schedule_time = get_option('art_image_schedule_time', '02:00');

        if (!ArtImageTimezoneHelper::validate_time_format($schedule_time)) {
            $schedule_time = '02:00';
        }

        try {
            if ($frequency === 'weekly') {
                $schedule_day = get_option('art_image_schedule_day', 'sunday');
                $timestamp = ArtImageTimezoneHelper::get_next_weekly_execution_time($schedule_time, $schedule_day)->getTimestamp();
            } else {
                $timestamp = ArtImageTimezoneHelper::get_next_execution_time($schedule_time)->getTimestamp();
            }

            $result = wp_schedule_event($timestamp, $frequency, $hook_name);

            if ($result === false) {
                return false;
            }

            ArtImageTimezoneHelper::log_with_timezone("Sincronização {$frequency} agendada para: " . date('Y-m-d H:i:s', $timestamp));
            return true;

        } catch (Exception $e) {
            ArtImageTimezoneHelper::log_with_timezone('ERRO ao agendar: ' . $e->getMessage());
            return false;
        }
    }

    private static function is_wp_cron_working() {
        $test_hook = 'art_image_cron_test_' . time();
        $test_time = time() + 60;

        $result = wp_schedule_single_event($test_time, $test_hook);
        if ($result === false) {
            return false;
        }

        $scheduled = wp_next_scheduled($test_hook);
        wp_unschedule_event($scheduled, $test_hook);

        return $scheduled !== false;
    }

    /**
     * Execução forçada (para testes e debug)
     */
    public function force_immediate_execution() {
        // Usar Action Scheduler se disponível
        if (ArtImageASManager::is_available()) {
            $this->log("=== EXECUÇÃO FORÇADA VIA ACTION SCHEDULER ===");

            // Cancelar qualquer sincronização em andamento
            if (ArtImageASManager::is_sync_running()) {
                $this->log("Cancelando sincronização anterior...");
                ArtImageASManager::cancel_all();
            }

            // Iniciar nova sincronização
            $this->as_trigger_sync_start();
            $this->log("Execução forçada via admin");
            return;
        }

        // Fallback para sistema antigo
        $this->log("=== EXECUÇÃO FORÇADA (SISTEMA ANTIGO) ===");

        $current_step = get_option(self::SYNC_STEP_OPTION, 'none');

        if ($current_step === 'none' || $current_step === 'done') {
            if ($this->is_lock_active()) {
                delete_option(self::LOCK_OPTION);
            }

            update_option(self::LOCK_OPTION, time());

            global $artimage_sync_tracker;
            if (isset($artimage_sync_tracker) && $artimage_sync_tracker) {
                $artimage_sync_tracker->start_sync_session();
            }

            delete_option(self::SYNC_STEP_OPTION);
            delete_option(self::SYNC_PAGE_OPTION);

            $this->set_sync_step('categories', 1);
            $this->run_sync_step(true);
        } else {
            $this->run_sync_step(true);
        }
    }

    public function test_wp_cron() {
        $test_hook = 'art_image_cron_test';
        $test_time = time() + 30;

        wp_clear_scheduled_hook($test_hook);

        $result = wp_schedule_single_event($test_time, $test_hook);

        if ($result === false) {
            return false;
        }

        $scheduled = wp_next_scheduled($test_hook);
        if (!$scheduled) {
            return false;
        }

        wp_unschedule_event($scheduled, $test_hook);

        return true;
    }

    private function complete_sync($error = false) {
        if ($error) {
            $this->log("Sincronização finalizada com ERRO.");
        } else {
            $this->log("Processo finalizado com sucesso.");

            global $artimage_sync_tracker;
            if (isset($artimage_sync_tracker) && $artimage_sync_tracker) {
                $cleanup_enabled = get_option('artimage_enable_cleanup', true);
                $dry_run = get_option('artimage_cleanup_dry_run', false);

                $cleanup_results = $artimage_sync_tracker->finish_sync_session([
                    'cleanup_enabled' => $cleanup_enabled,
                    'dry_run' => $dry_run
                ]);

                if ($cleanup_enabled) {
                    $this->log("Limpeza: {$cleanup_results['products_deleted']} produtos, {$cleanup_results['categories_deleted']} categorias");
                }
            }
        }

        delete_option(self::LOCK_OPTION);
        delete_option(self::SYNC_STEP_OPTION);
        delete_option(self::SYNC_PAGE_OPTION);

        $this->clear_pending_events();

        if (!$error) {
            $next_scheduled = wp_next_scheduled('art_image_sync_event');
            if (!$next_scheduled) {
                self::schedule_sync_event();
            }

            $next_scheduled = wp_next_scheduled('art_image_sync_event');
            if ($next_scheduled) {
                $this->log("Próxima sincronização: " . date('Y-m-d H:i:s', $next_scheduled));
            }
        }
    }

    private function clear_pending_events() {
        $timestamp = wp_next_scheduled('art_image_run_sync_step');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'art_image_run_sync_step');
            $timestamp = wp_next_scheduled('art_image_run_sync_step');
        }
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $message_with_timestamp = "[{$timestamp}] {$message}";
        error_log($message_with_timestamp . "\n", 3, $this->log_file);
        ArtImageTimezoneHelper::log_with_timezone($message);
    }

    public function force_clear_lock() {
        $this->log("=== LIMPEZA FORÇADA DE LOCK ===");

        // Limpar Action Scheduler também
        if (ArtImageASManager::is_available()) {
            ArtImageASManager::cancel_all();
        }

        $this->complete_sync();
        return true;
    }

    public static function cleanup_all_legacy_events() {
        wp_clear_scheduled_hook('art_image_daily_event');
        remove_all_actions('art_image_daily_event');

        wp_clear_scheduled_hook('art_image_daily_sync');
        remove_all_actions('art_image_daily_sync');

        remove_action('plugins_loaded', 'art_image_init_sync_manager');

        ArtImageTimezoneHelper::log_with_timezone('Eventos legacy limpos com sucesso');
    }

    public static function check_and_migrate() {
        $current_version = get_option('art_image_plugin_version', '0.0.0');
        $plugin_version = defined('ART_IMAGE_VERSION') ? ART_IMAGE_VERSION : '2.0.0';

        if (version_compare($current_version, $plugin_version, '<')) {
            self::cleanup_all_legacy_events();
            self::migrate_to_action_scheduler();
            update_option('art_image_plugin_version', $plugin_version);
        }
    }

    /**
     * Migra para o sistema Action Scheduler
     */
    public static function migrate_to_action_scheduler() {
        ArtImageTimezoneHelper::log_with_timezone('=== MIGRANDO PARA ACTION SCHEDULER ===');

        // Limpar transients antigos
        $transients_to_clear = [
            'artimage_master_import_lock',
            'artimage_product_import_queue',
            'artimage_product_import_total',
            'artimage_product_processed_count',
            'artimage_product_batch_lock',
            'artimage_product_subs_list',
            'artimage_category_import_queue',
            'artimage_subcategory_import_queue',
            'artimage_artist_import_queue',
        ];

        foreach ($transients_to_clear as $transient) {
            delete_transient($transient);
        }

        // Limpar WP-Cron antigo
        wp_clear_scheduled_hook('art_image_sync_event');
        wp_clear_scheduled_hook('art_image_run_sync_step');

        // Limpar options de estado
        delete_option('artimage_sync_lock');
        delete_option('artimage_current_sync_step');
        delete_option('artimage_current_sync_page');

        // Agendar via Action Scheduler
        if (ArtImageASManager::is_available()) {
            ArtImageASManager::schedule_recurring_sync();
            ArtImageTimezoneHelper::log_with_timezone('Migração para Action Scheduler concluída');
            update_option('artimage_using_action_scheduler', true);
        } else {
            // Fallback para WP-Cron
            self::schedule_sync_event();
            ArtImageTimezoneHelper::log_with_timezone('Action Scheduler não disponível, usando WP-Cron');
            update_option('artimage_using_action_scheduler', false);
        }
    }
}

// Inicialização
add_action('plugins_loaded', function() {
    new ArtImageSyncManager();
    ArtImageSyncManager::check_and_migrate();
});

add_action('init', function() {
    // Garantir que o evento está agendado
    if (ArtImageASManager::is_available()) {
        $next = ArtImageASManager::get_next_scheduled_sync();
        if (!$next) {
            ArtImageASManager::schedule_recurring_sync();
        }
    } else {
        if (!wp_next_scheduled('art_image_sync_event')) {
            ArtImageSyncManager::schedule_sync_event();
        }
    }
});

// Função para executar a sincronização manualmente
function art_image_run_sync() {
    $manager = new ArtImageSyncManager();
    $manager->force_immediate_execution();
}
