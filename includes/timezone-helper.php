<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Funções auxiliares para tratamento de fuso horário e agendamentos
 */

class ArtImageTimezoneHelper {
    
    /**
     * Verifica se o WordPress está configurado para o fuso horário de Brasília
     */
    public static function is_brasilia_timezone() {
        $timezone = wp_timezone_string();
        return in_array($timezone, ['America/Sao_Paulo', 'America/Bahia', 'America/Recife']);
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
     * Obtém o próximo horário de execução semanal
     */
    public static function get_next_weekly_execution_time($hour = '02:00', $day = 'sunday') {
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        
        // Mapear dias da semana
        $day_map = [
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6
        ];
        
        $target_day = $day_map[$day] ?? 0;
        $current_day = (int) $now->format('w');
        
        // Calcular próximo dia da semana
        $days_until_target = ($target_day - $current_day + 7) % 7;
        if ($days_until_target === 0) {
            // É hoje, verificar se já passou do horário
            $target_time = new DateTime('today ' . $hour, $timezone);
            if ($now >= $target_time) {
                $days_until_target = 7; // Próxima semana
            }
        }
        
        $target_date = clone $now;
        $target_date->add(new DateInterval('P' . $days_until_target . 'D'));

        // Parse hour and minute safely
        $time_parts = explode(':', $hour);
        $target_hour = isset($time_parts[0]) ? (int) $time_parts[0] : 2;
        $target_minute = isset($time_parts[1]) ? (int) $time_parts[1] : 0;
        $target_date->setTime($target_hour, $target_minute, 0);

        return $target_date;
    }
    
    /**
     * Verifica se é o horário correto para execução
     */
    public static function is_execution_time($configured_time = '02:00') {
        $current_time = current_time('H:i');
        return $current_time === $configured_time;
    }
    
    /**
     * Obtém informações sobre o fuso horário atual
     */
    public static function get_timezone_info() {
        return [
            'timezone_string' => wp_timezone_string(),
            'current_time' => current_time('Y-m-d H:i:s'),
            'is_brasilia' => self::is_brasilia_timezone(),
            'gmt_offset' => get_option('gmt_offset'),
            'server_time' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Obtém informações sobre próximos agendamentos
     */
    public static function get_scheduled_events_info() {
        $events = [];
        
        // Verifica evento de verificação (a cada 5 minutos)
        $next_check = wp_next_scheduled('art_image_daily_event');
        if ($next_check) {
            $events['daily_check'] = [
                'name' => 'Verificação diária',
                'timestamp' => $next_check,
                'date' => wp_date('Y-m-d H:i:s', $next_check),
                'hook' => 'art_image_daily_event'
            ];
        }
        
        // Verifica evento de sincronização diária (legacy)
        $next_sync = wp_next_scheduled('art_image_daily_sync');
        if ($next_sync) {
            $events['daily_sync'] = [
                'name' => 'Sincronização completa (diária) - LEGACY',
                'timestamp' => $next_sync,
                'date' => wp_date('Y-m-d H:i:s', $next_sync),
                'hook' => 'art_image_daily_sync'
            ];
        }
        
        // Verifica novo evento de sincronização (semanal/diária)
        $next_weekly = wp_next_scheduled('art_image_sync_event');
        if ($next_weekly) {
            $frequency = get_option('art_image_schedule_frequency', 'weekly');
            $events['sync_event'] = [
                'name' => 'Sincronização ' . ($frequency === 'weekly' ? 'semanal' : 'diária'),
                'timestamp' => $next_weekly,
                'date' => wp_date('Y-m-d H:i:s', $next_weekly),
                'hook' => 'art_image_sync_event'
            ];
        }
        
        // Verifica próximo passo de sincronização
        $next_step = wp_next_scheduled('art_image_run_sync_step');
        if ($next_step) {
            $events['sync_step'] = [
                'name' => 'Próximo passo de sincronização',
                'timestamp' => $next_step,
                'date' => wp_date('Y-m-d H:i:s', $next_step),
                'hook' => 'art_image_run_sync_step'
            ];
        }
        
        return $events;
    }
    
    /**
     * Cria um log com informações de fuso horário
     *
     * @deprecated Use ArtImageLogger::info() instead
     * @param string $message
     * @param string|null $log_file Ignorado, mantido para compatibilidade
     */
    public static function log_with_timezone($message, $log_file = null) {
        // Usa o novo sistema centralizado de logging
        if (class_exists('ArtImageLogger')) {
            ArtImageLogger::info($message);
        } else {
            // Fallback caso o logger não esteja carregado ainda
            $timestamp = current_time('Y-m-d H:i:s');
            $timezone_name = wp_timezone_string();
            $log_message = "[{$timestamp} {$timezone_name}] {$message}\n";
            error_log($log_message);
        }
    }
    
    /**
     * Valida se um horário está no formato correto
     */
    public static function validate_time_format($time) {
        return (bool) preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
    }
    
    /**
     * Converte horário para timestamp considerando o fuso horário
     */
    public static function time_to_timestamp($time_string, $date = 'today') {
        $timezone = wp_timezone();
        try {
            $datetime = new DateTime($date . ' ' . $time_string, $timezone);
            return $datetime->getTimestamp();
        } catch (Exception $e) {
            self::log_with_timezone('Erro ao converter horário: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reagenda um evento existente
     */
    public static function reschedule_event($hook, $time, $recurrence = 'daily') {
        // Cancela o evento existente
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        
        // Agenda o novo evento
        $next_exec = self::get_next_execution_time($time);
        $result = wp_schedule_event($next_exec->getTimestamp(), $recurrence, $hook);
        
        if ($result !== false) {
            self::log_with_timezone("Evento {$hook} reagendado para: " . $next_exec->format('Y-m-d H:i:s T'));
            return true;
        } else {
            self::log_with_timezone("ERRO: Falha ao reagendar evento {$hook}");
            return false;
        }
    }
    
    /**
     * Agenda um evento considerando o fuso horário
     */
    public static function schedule_event($hook, $time, $recurrence = 'daily') {
        $next_exec = self::get_next_execution_time($time);
        $result = wp_schedule_event($next_exec->getTimestamp(), $recurrence, $hook);
        
        if ($result !== false) {
            self::log_with_timezone("Evento {$hook} agendado para: " . $next_exec->format('Y-m-d H:i:s T'));
            return true;
        } else {
            self::log_with_timezone("ERRO: Falha ao agendar evento {$hook}");
            return false;
        }
    }
}