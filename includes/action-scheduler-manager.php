<?php
/**
 * Gerenciador do Action Scheduler para Art Image
 *
 * Esta classe gerencia todas as interações com o Action Scheduler do WooCommerce
 * para importações robustas e persistentes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ArtImageASManager {

    /**
     * Grupo de actions no Action Scheduler
     */
    const GROUP = 'artimage';

    /**
     * Prefixo para hooks
     */
    const HOOK_PREFIX = 'artimage_';

    /**
     * Tempo padrão entre verificações de fase (segundos)
     */
    const PHASE_CHECK_INTERVAL = 30;

    /**
     * Quantidade de produtos por batch (otimização de performance)
     * Em vez de 1 action por produto, 1 action processa BATCH_SIZE produtos
     */
    const PRODUCT_BATCH_SIZE = 20;

    /**
     * Tempo entre cada batch de produtos (segundos)
     * Delay menor porque cada batch já processa múltiplos produtos
     */
    const PRODUCT_BATCH_DELAY = 2;

    /**
     * Verifica se o Action Scheduler está disponível
     */
    public static function is_available(): bool {
        return function_exists('as_schedule_single_action') &&
               function_exists('as_enqueue_async_action') &&
               class_exists('ActionScheduler_Store');
    }

    /**
     * Agenda sincronização recorrente (semanal ou diária)
     */
    public static function schedule_recurring_sync(): void {
        if (!self::is_available()) {
            ArtImageTimezoneHelper::log_with_timezone('[AS] Action Scheduler não disponível');
            return;
        }

        // Limpar agendamento anterior
        as_unschedule_all_actions(self::HOOK_PREFIX . 'scheduled_sync', [], self::GROUP);

        $frequency = get_option('art_image_schedule_frequency', 'weekly');
        $schedule_time = get_option('art_image_schedule_time', '02:00');
        $schedule_day = get_option('art_image_schedule_day', 'sunday');

        // Calcular próxima execução usando o helper existente
        if ($frequency === 'weekly') {
            $next_run = ArtImageTimezoneHelper::get_next_weekly_execution_time($schedule_time, $schedule_day);
        } else {
            $next_run = ArtImageTimezoneHelper::get_next_execution_time($schedule_time);
        }

        $interval = ($frequency === 'weekly') ? WEEK_IN_SECONDS : DAY_IN_SECONDS;

        // Converter DateTime para timestamp
        $next_run_timestamp = $next_run->getTimestamp();

        as_schedule_recurring_action(
            $next_run_timestamp,
            $interval,
            self::HOOK_PREFIX . 'scheduled_sync',
            [],
            self::GROUP
        );

        $next_run_formatted = wp_date('Y-m-d H:i:s', $next_run_timestamp);
        ArtImageTimezoneHelper::log_with_timezone("[AS] Sincronização {$frequency} agendada para: {$next_run_formatted}");
    }

    /**
     * Agenda importação de uma entidade individual
     */
    public static function schedule_entity_import(string $type, array $data, int $priority = 10): int {
        if (!self::is_available()) {
            return 0;
        }

        return as_enqueue_async_action(
            self::HOOK_PREFIX . 'import_' . $type,
            ['data' => $data],
            self::GROUP,
            false,
            $priority
        );
    }

    /**
     * Agenda múltiplas entidades com delay entre elas
     */
    public static function schedule_batch(string $type, array $items, int $base_delay = 0): int {
        if (!self::is_available()) {
            return 0;
        }

        $scheduled = 0;
        $timestamp = time() + $base_delay;
        $delay_per_item = ($type === 'product') ? self::PRODUCT_ACTION_DELAY : 0;

        foreach ($items as $index => $item) {
            $action_time = $timestamp + ($index * $delay_per_item);

            as_schedule_single_action(
                $action_time,
                self::HOOK_PREFIX . 'import_' . $type,
                ['data' => $item],
                self::GROUP
            );

            $scheduled++;
        }

        return $scheduled;
    }

    /**
     * Agenda produtos em batches (otimizado para grande volume)
     * Em vez de 1 action por produto, agrupa em batches de PRODUCT_BATCH_SIZE
     *
     * @param array $products Lista de produtos para importar
     * @return int Número de batches agendados
     */
    public static function schedule_product_batches(array $products): int {
        if (!self::is_available() || empty($products)) {
            return 0;
        }

        // Dividir produtos em batches
        $batches = array_chunk($products, self::PRODUCT_BATCH_SIZE);
        $scheduled = 0;
        $timestamp = time();

        foreach ($batches as $index => $batch) {
            $action_time = $timestamp + ($index * self::PRODUCT_BATCH_DELAY);

            as_schedule_single_action(
                $action_time,
                self::HOOK_PREFIX . 'import_products_batch',
                ['products' => $batch],
                self::GROUP
            );

            $scheduled++;
        }

        $total_products = count($products);
        ArtImageTimezoneHelper::log_with_timezone(
            "[AS] Agendados {$scheduled} batches para {$total_products} produtos (batch size: " . self::PRODUCT_BATCH_SIZE . ")"
        );

        return $scheduled;
    }

    /**
     * Agenda início de uma fase de importação
     */
    public static function schedule_phase_start(string $phase, string $session_id, int $delay = 0): void {
        if (!self::is_available()) {
            return;
        }

        ArtImageTimezoneHelper::log_with_timezone("[AS] Agendando início da fase: {$phase} (delay: {$delay}s)");

        if ($delay > 0) {
            as_schedule_single_action(
                time() + $delay,
                self::HOOK_PREFIX . 'start_import_phase',
                [
                    'phase' => $phase,
                    'session_id' => $session_id
                ],
                self::GROUP
            );
        } else {
            // Sem delay: usar async para execução imediata
            as_enqueue_async_action(
                self::HOOK_PREFIX . 'start_import_phase',
                [
                    'phase' => $phase,
                    'session_id' => $session_id
                ],
                self::GROUP
            );
        }
    }

    /**
     * Agenda verificação de conclusão de uma fase
     */
    public static function schedule_phase_check(string $phase, string $session_id, int $delay = null): void {
        if (!self::is_available()) {
            return;
        }

        $delay = $delay ?? self::PHASE_CHECK_INTERVAL;

        as_schedule_single_action(
            time() + $delay,
            self::HOOK_PREFIX . 'complete_phase',
            [
                'phase' => $phase,
                'session_id' => $session_id
            ],
            self::GROUP
        );
    }

    /**
     * Agenda coleta de produtos de uma subcategoria
     */
    public static function schedule_product_collection(int $subcategory_id, string $session_id, int $delay = 0): void {
        if (!self::is_available()) {
            return;
        }

        as_schedule_single_action(
            time() + $delay,
            self::HOOK_PREFIX . 'prepare_products_subcategory',
            [
                'subcategory_id' => $subcategory_id,
                'session_id' => $session_id
            ],
            self::GROUP
        );
    }

    /**
     * Verifica se uma fase está completa (sem actions pendentes ou em execução)
     */
    public static function is_phase_complete(string $entity_type): bool {
        if (!self::is_available()) {
            return true;
        }

        $hook = self::HOOK_PREFIX . 'import_' . $entity_type;

        // Verificar pendentes
        $pending = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);

        if (!empty($pending)) {
            return false;
        }

        // Verificar em execução
        $running = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);

        return empty($running);
    }

    /**
     * Verifica se a fase de produtos está completa (individual + batches)
     */
    public static function is_products_phase_complete(): bool {
        if (!self::is_available()) {
            return true;
        }

        // Verificar produtos individuais (legacy)
        $hook_single = self::HOOK_PREFIX . 'import_product';
        $pending_single = as_get_scheduled_actions([
            'hook' => $hook_single,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);
        $running_single = as_get_scheduled_actions([
            'hook' => $hook_single,
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);

        // Verificar batches de produtos (novo)
        $hook_batch = self::HOOK_PREFIX . 'import_products_batch';
        $pending_batch = as_get_scheduled_actions([
            'hook' => $hook_batch,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);
        $running_batch = as_get_scheduled_actions([
            'hook' => $hook_batch,
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);

        return empty($pending_single) && empty($running_single) &&
               empty($pending_batch) && empty($running_batch);
    }

    /**
     * Verifica se a preparação de produtos está completa
     */
    public static function is_product_preparation_complete(): bool {
        if (!self::is_available()) {
            return true;
        }

        $hook = self::HOOK_PREFIX . 'prepare_products_subcategory';

        $pending = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);

        $running = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => 1,
            'group' => self::GROUP,
        ]);

        return empty($pending) && empty($running);
    }

    /**
     * Conta actions por hook e status
     */
    public static function count_actions(string $hook, string $status): int {
        if (!self::is_available()) {
            return 0;
        }

        $actions = as_get_scheduled_actions([
            'hook' => $hook,
            'status' => $status,
            'per_page' => -1,
            'group' => self::GROUP,
        ]);

        return count($actions);
    }

    /**
     * Obtém progresso atual de todas as fases
     */
    public static function get_progress(): array {
        if (!self::is_available()) {
            return ['status' => 'unavailable', 'message' => 'Action Scheduler não disponível'];
        }

        $session_id = get_option('artimage_as_session_id');

        if (!$session_id) {
            return [
                'status' => 'idle',
                'session_id' => null,
                'current_phase' => null,
                'total_pending' => 0,
                'total_running' => 0,
                'total_complete' => 0,
                'total_failed' => 0,
                'phases' => []
            ];
        }

        $current_phase = get_option('artimage_as_current_phase', 'initializing');

        // Mapeamento de fases para o JS (usa plural)
        $phase_mapping = [
            'categories' => 'category',
            'subcategories' => 'subcategory',
            'artists' => 'artist',
            'products' => 'product'
        ];

        $stats = [];
        $total_pending = 0;
        $total_running = 0;
        $total_complete = 0;
        $total_failed = 0;

        // Estatísticas por fase
        foreach ($phase_mapping as $display_name => $hook_name) {
            $hook = self::HOOK_PREFIX . 'import_' . $hook_name;

            $pending = self::count_actions($hook, ActionScheduler_Store::STATUS_PENDING);
            $running = self::count_actions($hook, ActionScheduler_Store::STATUS_RUNNING);
            $complete = self::count_actions($hook, ActionScheduler_Store::STATUS_COMPLETE);
            $failed = self::count_actions($hook, ActionScheduler_Store::STATUS_FAILED);

            // Para produtos, incluir também os batches
            if ($hook_name === 'product') {
                $batch_hook = self::HOOK_PREFIX . 'import_products_batch';
                $pending += self::count_actions($batch_hook, ActionScheduler_Store::STATUS_PENDING);
                $running += self::count_actions($batch_hook, ActionScheduler_Store::STATUS_RUNNING);
                $complete += self::count_actions($batch_hook, ActionScheduler_Store::STATUS_COMPLETE);
                $failed += self::count_actions($batch_hook, ActionScheduler_Store::STATUS_FAILED);
            }

            $stats[$display_name] = [
                'pending' => $pending,
                'running' => $running,
                'complete' => $complete,
                'failed' => $failed,
            ];

            $total_pending += $pending;
            $total_running += $running;
            $total_complete += $complete;
            $total_failed += $failed;
        }

        // Preparação de produtos
        $prep_hook = self::HOOK_PREFIX . 'prepare_products_subcategory';
        $prep_pending = self::count_actions($prep_hook, ActionScheduler_Store::STATUS_PENDING);
        $prep_running = self::count_actions($prep_hook, ActionScheduler_Store::STATUS_RUNNING);
        $prep_complete = self::count_actions($prep_hook, ActionScheduler_Store::STATUS_COMPLETE);
        $prep_failed = self::count_actions($prep_hook, ActionScheduler_Store::STATUS_FAILED);

        $stats['prepare_products'] = [
            'pending' => $prep_pending,
            'running' => $prep_running,
            'complete' => $prep_complete,
            'failed' => $prep_failed,
        ];

        $total_pending += $prep_pending;
        $total_running += $prep_running;
        $total_complete += $prep_complete;
        $total_failed += $prep_failed;

        return [
            'status' => ($total_pending > 0 || $total_running > 0) ? 'running' : 'idle',
            'session_id' => $session_id,
            'current_phase' => $current_phase,
            'started_at' => get_option('artimage_as_started_at'),
            'total_pending' => $total_pending,
            'total_running' => $total_running,
            'total_complete' => $total_complete,
            'total_failed' => $total_failed,
            'phases' => $stats,
        ];
    }

    /**
     * Verifica se há uma sincronização em andamento
     */
    public static function is_sync_running(): bool {
        $session_id = get_option('artimage_as_session_id');
        return !empty($session_id);
    }

    /**
     * Cancela todas as actions pendentes do grupo artimage
     */
    public static function cancel_all(): int {
        if (!self::is_available()) {
            return 0;
        }

        $hooks = [
            self::HOOK_PREFIX . 'scheduled_sync',
            self::HOOK_PREFIX . 'start_import_phase',
            self::HOOK_PREFIX . 'complete_phase',
            self::HOOK_PREFIX . 'import_category',
            self::HOOK_PREFIX . 'import_subcategory',
            self::HOOK_PREFIX . 'import_artist',
            self::HOOK_PREFIX . 'import_product',
            self::HOOK_PREFIX . 'import_products_batch', // Novo: batches de produtos
            self::HOOK_PREFIX . 'prepare_products_subcategory',
        ];

        $cancelled = 0;

        foreach ($hooks as $hook) {
            $cancelled += as_unschedule_all_actions($hook, [], self::GROUP);
        }

        // Limpar estado
        delete_option('artimage_as_session_id');
        delete_option('artimage_as_current_phase');
        delete_option('artimage_as_started_at');

        ArtImageTimezoneHelper::log_with_timezone("[AS] Canceladas {$cancelled} actions");

        return $cancelled;
    }

    /**
     * Retenta todas as actions falhas
     */
    public static function retry_failed(): int {
        if (!self::is_available()) {
            return 0;
        }

        $failed_actions = as_get_scheduled_actions([
            'status' => ActionScheduler_Store::STATUS_FAILED,
            'group' => self::GROUP,
            'per_page' => -1,
        ]);

        $retried = 0;
        $store = ActionScheduler_Store::instance();

        foreach ($failed_actions as $action) {
            try {
                // Re-agendar a action
                as_schedule_single_action(
                    time(),
                    $action->get_hook(),
                    $action->get_args(),
                    self::GROUP
                );

                // Deletar a action falha
                $store->delete_action($action->get_id());

                $retried++;
            } catch (Exception $e) {
                ArtImageTimezoneHelper::log_with_timezone("[AS] Erro ao retentar action: " . $e->getMessage());
            }
        }

        ArtImageTimezoneHelper::log_with_timezone("[AS] Retentadas {$retried} actions falhas");

        return $retried;
    }

    /**
     * Obtém actions falhas para diagnóstico
     */
    public static function get_failed_actions(int $limit = 20): array {
        if (!self::is_available()) {
            return [];
        }

        $failed = as_get_scheduled_actions([
            'status' => ActionScheduler_Store::STATUS_FAILED,
            'group' => self::GROUP,
            'per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $result = [];

        foreach ($failed as $action) {
            $logs = ActionScheduler_Logger::instance()->get_logs($action->get_id());
            $last_log = !empty($logs) ? end($logs)->message : 'Sem log';

            $result[] = [
                'id' => $action->get_id(),
                'hook' => $action->get_hook(),
                'args' => $action->get_args(),
                'scheduled_date' => $action->get_schedule()->get_date()->format('Y-m-d H:i:s'),
                'error' => $last_log,
            ];
        }

        return $result;
    }

    /**
     * Limpa actions antigas completadas
     */
    public static function cleanup_completed(int $days_old = 7): int {
        if (!self::is_available()) {
            return 0;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'actionscheduler_actions';
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'complete' AND scheduled_date_gmt < %s AND `group_id` IN (
                SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s
            )",
            $cutoff,
            self::GROUP
        ));

        return $deleted ?: 0;
    }

    /**
     * Verifica próxima sincronização agendada
     */
    public static function get_next_scheduled_sync(): ?string {
        if (!self::is_available()) {
            return null;
        }

        $next = as_next_scheduled_action(self::HOOK_PREFIX . 'scheduled_sync', [], self::GROUP);

        if ($next) {
            return wp_date('Y-m-d H:i:s', $next);
        }

        return null;
    }

    /**
     * Inicia uma nova sessão de sincronização
     */
    public static function start_session(): string {
        $session_id = 'as_' . time() . '_' . wp_generate_password(8, false, false);

        update_option('artimage_as_session_id', $session_id);
        update_option('artimage_as_started_at', current_time('mysql'));
        update_option('artimage_as_current_phase', 'starting');

        ArtImageTimezoneHelper::log_with_timezone("[AS] Nova sessão iniciada: {$session_id}");

        return $session_id;
    }

    /**
     * Força processamento imediato das actions pendentes
     * Útil para sync manual onde queremos que comece imediatamente
     */
    public static function run_pending_actions(int $max_actions = 10): int {
        if (!self::is_available()) {
            return 0;
        }

        try {
            // Método 1: Usar o runner do Action Scheduler
            if (class_exists('ActionScheduler_QueueRunner')) {
                $runner = ActionScheduler_QueueRunner::instance();
                $processed = $runner->run();
                ArtImageTimezoneHelper::log_with_timezone("[AS] Processadas {$processed} actions via runner");
                return $processed;
            }

            // Método 2: Alternativo via função
            if (function_exists('as_process_queue')) {
                $processed = as_process_queue($max_actions);
                ArtImageTimezoneHelper::log_with_timezone("[AS] Processadas {$processed} actions via as_process_queue");
                return $processed;
            }

            return 0;
        } catch (Exception $e) {
            ArtImageTimezoneHelper::log_with_timezone("[AS] Erro ao processar actions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Finaliza a sessão atual
     */
    public static function end_session(): void {
        $session_id = get_option('artimage_as_session_id');

        delete_option('artimage_as_session_id');
        delete_option('artimage_as_current_phase');
        update_option('artimage_as_last_completed', current_time('mysql'));

        ArtImageTimezoneHelper::log_with_timezone("[AS] Sessão finalizada: {$session_id}");
    }

    /**
     * Atualiza a fase atual
     */
    public static function set_current_phase(string $phase): void {
        update_option('artimage_as_current_phase', $phase);
        ArtImageTimezoneHelper::log_with_timezone("[AS] Fase atualizada: {$phase}");
    }
}
