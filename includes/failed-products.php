<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rastreamento de produtos que falharam na importação.
 * Uma linha por SKU (code). Upsert evita corrida entre lotes simultâneos.
 */
class ArtImageFailedProducts
{
    const MAX_ATTEMPTS = 3;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'artimage_failed_products';
    }

    /**
     * Cria a tabela se não existir (idempotente). Chamada no init.
     */
    public static function maybe_create_table(): void
    {
        global $wpdb;
        $table = self::table();

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(191) NOT NULL,
            source_url VARCHAR(255) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL DEFAULT '',
            reason VARCHAR(40) NOT NULL DEFAULT '',
            payload LONGTEXT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            first_failed_at DATETIME NULL,
            last_failed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Registra (ou atualiza) uma falha por SKU.
     */
    public static function record(string $code, string $source_url, string $name, string $reason, array $payload = []): void
    {
        if ($code === '') {
            return;
        }
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql');
        $payload_json = empty($payload) ? null : wp_json_encode($payload);

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (code, source_url, name, reason, payload, attempts, status, first_failed_at, last_failed_at)
                 VALUES (%s, %s, %s, %s, %s, 0, 'pendente', %s, %s)
                 ON DUPLICATE KEY UPDATE
                    source_url = VALUES(source_url),
                    name = VALUES(name),
                    reason = VALUES(reason),
                    payload = COALESCE(VALUES(payload), payload),
                    status = 'pendente',
                    last_failed_at = VALUES(last_failed_at)",
                $code, $source_url, $name, $reason, $payload_json, $now, $now
            )
        );
    }

    /**
     * Remove o registro de falha de um SKU (chamado quando importa com sucesso).
     */
    public static function resolve(string $code): void
    {
        if ($code === '') {
            return;
        }
        global $wpdb;
        $wpdb->delete(self::table(), ['code' => $code], ['%s']);
    }

    /**
     * Lista pendentes para exibição na aba (mais recentes primeiro).
     *
     * @return array<int,object>
     */
    public static function get_pending(int $limit = 500): array
    {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pendente' ORDER BY last_failed_at DESC LIMIT %d",
                $limit
            )
        ) ?: [];
    }

    public static function count_pending(): int
    {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pendente'");
    }

    /**
     * Retorna pendentes elegíveis a retry: payload presente e attempts < limite.
     *
     * @param bool $force Se true, ignora o limite de tentativas (retry manual).
     * @return array<int,object>
     */
    public static function get_retryable(bool $force = false): array
    {
        global $wpdb;
        $table = self::table();
        $sql = "SELECT * FROM {$table} WHERE status = 'pendente' AND payload IS NOT NULL AND payload <> ''";
        if (!$force) {
            $sql .= $wpdb->prepare(" AND attempts < %d", self::MAX_ATTEMPTS);
        }
        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Incrementa o contador de tentativas de um conjunto de SKUs.
     *
     * @param string[] $codes
     */
    public static function increment_attempts(array $codes): void
    {
        if (empty($codes)) {
            return;
        }
        global $wpdb;
        $table = self::table();
        $placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET attempts = attempts + 1 WHERE code IN ({$placeholders})",
                ...$codes
            )
        );
    }
}
