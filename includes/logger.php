<?php
/**
 * Sistema centralizado de logging para o plugin Art Image
 *
 * Esta classe unifica todas as funções de logging do plugin,
 * substituindo os diversos métodos anteriores (log_with_timezone,
 * art_image_log_import_error, error_log, etc.)
 *
 * @package ArtImage
 */

if (!defined('ABSPATH')) {
    exit;
}

class ArtImageLogger {

    /**
     * Níveis de log
     */
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    /**
     * Caminho do arquivo de log
     * @var string|null
     */
    private static $log_file = null;

    /**
     * Se o debug está habilitado
     * @var bool|null
     */
    private static $debug_enabled = null;

    /**
     * Obtém o caminho do arquivo de log
     *
     * @return string
     */
    private static function get_log_file() {
        if (self::$log_file === null) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/art-image-import-log.txt';
        }
        return self::$log_file;
    }

    /**
     * Verifica se o modo debug está habilitado
     *
     * @return bool
     */
    private static function is_debug_enabled() {
        if (self::$debug_enabled === null) {
            self::$debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        }
        return self::$debug_enabled;
    }

    /**
     * Obtém o timestamp formatado com timezone do WordPress
     *
     * @return string
     */
    private static function get_timestamp() {
        $timezone_string = get_option('timezone_string');
        $gmt_offset = get_option('gmt_offset');

        try {
            if (!empty($timezone_string)) {
                $timezone = new DateTimeZone($timezone_string);
            } elseif ($gmt_offset !== '') {
                $hours = (int) $gmt_offset;
                $minutes = abs(($gmt_offset - $hours) * 60);
                $offset_string = sprintf('%+03d:%02d', $hours, $minutes);
                $timezone = new DateTimeZone($offset_string);
            } else {
                $timezone = new DateTimeZone('UTC');
            }

            $datetime = new DateTime('now', $timezone);
            return $datetime->format('Y-m-d H:i:s T');
        } catch (Exception $e) {
            return gmdate('Y-m-d H:i:s') . ' UTC';
        }
    }

    /**
     * Formata a mensagem de log
     *
     * @param string $level Nível do log
     * @param string $message Mensagem
     * @param array $context Contexto adicional
     * @return string
     */
    private static function format_message($level, $message, $context = []) {
        $timestamp = self::get_timestamp();
        $formatted = "[{$timestamp}] [{$level}] {$message}";

        if (!empty($context)) {
            $context_str = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $formatted .= " | Context: {$context_str}";
        }

        return $formatted;
    }

    /**
     * Escreve no arquivo de log
     *
     * @param string $message Mensagem formatada
     */
    private static function write_to_file($message) {
        $log_file = self::get_log_file();
        $message .= PHP_EOL;

        // Usa file locking para evitar problemas de concorrência
        $fp = @fopen($log_file, 'a');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $message);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Método principal de logging
     *
     * @param string $message Mensagem a ser logada
     * @param string $level Nível do log (DEBUG, INFO, WARNING, ERROR)
     * @param array $context Dados adicionais de contexto
     */
    public static function log($message, $level = self::LEVEL_INFO, $context = []) {
        // Skip debug messages se WP_DEBUG não estiver habilitado
        if ($level === self::LEVEL_DEBUG && !self::is_debug_enabled()) {
            return;
        }

        $formatted = self::format_message($level, $message, $context);
        self::write_to_file($formatted);

        // Em modo debug, também envia para error_log
        if (self::is_debug_enabled()) {
            error_log("[ArtImage] {$formatted}");
        }
    }

    /**
     * Log de nível DEBUG
     *
     * @param string $message
     * @param array $context
     */
    public static function debug($message, $context = []) {
        self::log($message, self::LEVEL_DEBUG, $context);
    }

    /**
     * Log de nível INFO
     *
     * @param string $message
     * @param array $context
     */
    public static function info($message, $context = []) {
        self::log($message, self::LEVEL_INFO, $context);
    }

    /**
     * Log de nível WARNING
     *
     * @param string $message
     * @param array $context
     */
    public static function warning($message, $context = []) {
        self::log($message, self::LEVEL_WARNING, $context);
    }

    /**
     * Log de nível ERROR
     *
     * @param string $message
     * @param array $context
     */
    public static function error($message, $context = []) {
        self::log($message, self::LEVEL_ERROR, $context);

        // Salva erro em transient para exibir no admin
        self::store_error_for_admin($message, $context);
    }

    /**
     * Armazena erros para exibição no admin
     *
     * @param string $message
     * @param array $context
     */
    private static function store_error_for_admin($message, $context = []) {
        $errors = get_transient('art_image_import_errors') ?: [];
        $errors[] = [
            'time' => current_time('mysql'),
            'message' => $message,
            'context' => $context
        ];

        // Mantém apenas os últimos 50 erros
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }

        set_transient('art_image_import_errors', $errors, DAY_IN_SECONDS);
    }

    /**
     * Log específico para processamento de produtos
     *
     * @param string $sku SKU do produto
     * @param string $action Ação realizada
     * @param array $details Detalhes adicionais
     */
    public static function product($sku, $action, $details = []) {
        $message = "SKU: {$sku} | Ação: {$action}";

        if (!empty($details)) {
            $formatted_details = [];
            foreach ($details as $key => $value) {
                if (is_array($value)) {
                    $formatted_details[] = $key . ': ' . count($value) . ' itens';
                } else {
                    $formatted_details[] = $key . ': ' . $value;
                }
            }
            $message .= ' | ' . implode(' | ', $formatted_details);
        }

        self::info("[PRODUTO] {$message}");
    }

    /**
     * Log específico para Action Scheduler
     *
     * @param string $message
     * @param array $context
     */
    public static function action_scheduler($message, $context = []) {
        self::info("[AS] {$message}", $context);
    }

    /**
     * Log específico para sincronização
     *
     * @param string $message
     * @param array $context
     */
    public static function sync($message, $context = []) {
        self::info("[SYNC] {$message}", $context);
    }

    /**
     * Obtém o caminho do arquivo de log (para uso externo)
     *
     * @return string
     */
    public static function get_log_path() {
        return self::get_log_file();
    }

    /**
     * Limpa o arquivo de log
     *
     * @return bool
     */
    public static function clear_log() {
        $log_file = self::get_log_file();
        return @file_put_contents($log_file, '') !== false;
    }

    /**
     * Obtém as últimas N linhas do log
     *
     * @param int $lines Número de linhas
     * @return array
     */
    public static function get_recent_logs($lines = 100) {
        $log_file = self::get_log_file();

        if (!file_exists($log_file)) {
            return [];
        }

        $content = @file_get_contents($log_file);
        if ($content === false) {
            return [];
        }

        $all_lines = explode(PHP_EOL, trim($content));
        return array_slice($all_lines, -$lines);
    }
}

/**
 * Função wrapper para compatibilidade com código legado
 * Substitui ArtImageTimezoneHelper::log_with_timezone()
 *
 * @param string $message
 */
function art_image_log($message) {
    ArtImageLogger::info($message);
}
