# Retry de Produtos com Falha + Aba de Falhas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rastrear produtos que falham na importação, re-tentá-los automaticamente (até 3x, em lote de 1) após a fase de produtos, e expor uma aba "Falhas" no admin com link para a artimage e botão de retry manual.

**Architecture:** Uma classe `ArtImageFailedProducts` encapsula uma tabela própria (`{prefix}artimage_failed_products`, 1 linha por SKU). Pontos de captura nos dois caminhos de import + no hook de falha do Action Scheduler + nos erros agregados por lote. Uma fase nova `retry_products` re-enfileira os pendentes em lotes de 1 e repete até 3 rodadas. Aba admin lê os pendentes.

**Tech Stack:** PHP 8.2, WordPress, WooCommerce, Action Scheduler, `$wpdb`, WP-CLI (verificação no servidor).

**Spec:** `docs/superpowers/specs/2026-06-09-retry-falhas-produtos-design.md`

**Convenção de verificação:** este projeto não tem PHPUnit. Verificação = (a) `php -l` (lint local), (b) `wp eval`/`wp eval-file` no servidor de produção via cPanel/WP-CLI para checagens funcionais, (c) checagem manual no admin. Onde indicado "wp eval", roda-se no servidor.

**Deploy:** cada arquivo alterado vai para o repo (git) **e** para o código publicado (via cPanel File Manager / `save_file_content`). Após cada arquivo, rodar `php -l` no arquivo publicado.

---

## File Structure

- **Create** `includes/failed-products.php` — classe `ArtImageFailedProducts`: tabela + CRUD + seleção de retryables. Responsabilidade única: persistência e consulta de falhas.
- **Modify** `includes/loader.php` — `require_once` do novo arquivo; cria a tabela no init; registra o hook `action_scheduler_failed_execution`.
- **Modify** `includes/importer.php` — capturas (`timeout_detalhe`, `falha_imagem`) e `resolve()` nos dois caminhos: `import_single_product()` (AS/retry) e `import_products_batch()` (manual).
- **Modify** `includes/sync-manager.php` — captura `exception` (erros agregados por lote); fase `retry_products` (start + completion + advance).
- **Modify** `includes/action-scheduler-manager.php` — handler do hook de falha (captura `lote_300s`); `schedule_retry_batches()` (lote 1).
- **Modify** `admin/admin-ui.php` — aba "Falhas".
- **Modify** `includes/async-handler.php` — handler AJAX `art_image_retry_failed_products`.

---

## Task 1: Classe ArtImageFailedProducts + tabela

**Files:**
- Create: `includes/failed-products.php`
- Modify: `includes/loader.php`

- [ ] **Step 1: Criar `includes/failed-products.php` com a classe completa**

```php
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

        // Só roda o dbDelta se a tabela não existir (evita custo em toda request)
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
     *
     * @param string $code       SKU do produto
     * @param string $source_url Link do produto na artimage
     * @param string $name       Nome do produto
     * @param string $reason     lote_300s | exception | timeout_detalhe | falha_imagem
     * @param array  $payload    Dados de listagem ($data) para reimportar (opcional)
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

        // Upsert por code: INSERT, e em duplicata atualiza reason/last_failed_at/payload.
        // Mantém payload antigo se o novo vier vazio (COALESCE).
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
     * Retorna pendentes elegíveis a retry automático: payload presente e attempts < limite.
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
```

- [ ] **Step 2: Lint do novo arquivo**

Run: `php -l includes/failed-products.php`
Expected: `No syntax errors detected in includes/failed-products.php`

- [ ] **Step 3: Carregar a classe e criar a tabela no `includes/loader.php`**

Em `includes/loader.php`, logo após a linha `require_once ART_IMAGE_PLUGIN_DIR . 'includes/logger.php';`, adicionar:

```php
// Rastreamento de falhas de produto
require_once ART_IMAGE_PLUGIN_DIR . 'includes/failed-products.php';
```

E ao final do arquivo (depois do bloco de filtros de hardening já existente), adicionar:

```php
// Garante a tabela de falhas (idempotente; cobre plugin já ativo em produção)
add_action('init', ['ArtImageFailedProducts', 'maybe_create_table']);
```

- [ ] **Step 4: Lint do loader**

Run: `php -l includes/loader.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add includes/failed-products.php includes/loader.php
git commit -m "feat: classe ArtImageFailedProducts + tabela de falhas"
```

- [ ] **Step 6: Deploy + verificação funcional no servidor**

Publicar `includes/failed-products.php` e `includes/loader.php` no servidor (cPanel). Depois, no servidor (WP-CLI):

Run: `wp eval 'ArtImageFailedProducts::maybe_create_table(); global $wpdb; echo $wpdb->get_var("SHOW TABLES LIKE \"".ArtImageFailedProducts::table()."\"");'`
Expected: imprime o nome da tabela (`wpcz_artimage_failed_products`).

Run (teste de upsert/resolve):
`wp eval 'ArtImageFailedProducts::record("TESTE1","http://x/1","Nome","timeout_detalhe",["product_data"=>["code"=>"TESTE1"]]); echo ArtImageFailedProducts::count_pending(); ArtImageFailedProducts::resolve("TESTE1"); echo "|".ArtImageFailedProducts::count_pending();'`
Expected: imprime `N|N-1` (conta sobe com o record e volta com o resolve).

---

## Task 2: Capturas + resolve no caminho compartilhado `import_single_product` (AS/retry)

**Files:**
- Modify: `includes/importer.php` (método `import_single_product`, ~1410-1546)

- [ ] **Step 1: Capturar `timeout_detalhe` após buscar detalhes**

Em `import_single_product`, localizar (linha ~1424-1428):

```php
            // Buscar detalhes completos da API
            $details = [];
            if (!empty($p_data['link'])) {
                $details = $this->client->get_product_details($p_data['link']);
            }
```

Substituir por:

```php
            // Buscar detalhes completos da API
            $details = [];
            if (!empty($p_data['link'])) {
                $details = $this->client->get_product_details($p_data['link']);
                // Captura: detalhe não veio (timeout/erro) => produto entra degradado
                if (empty($details)) {
                    ArtImageFailedProducts::record(
                        $sku,
                        (string)($p_data['link'] ?? ''),
                        (string)($p_data['title'] ?? ''),
                        'timeout_detalhe',
                        $data
                    );
                }
            }
```

- [ ] **Step 2: Capturar `falha_imagem` na imagem principal**

Localizar (linha ~1496-1505) o bloco da imagem principal e substituir o trecho:

```php
                    if (art_image_is_valid_image_url($main_image_url)) {
                        $thumb_id = $this->artimage_download_and_attach_image($main_image_url, $new_prod_id);
                        if ($thumb_id) {
                            set_post_thumbnail($new_prod_id, $thumb_id);
                            $images_processed++;
                        }
                    }
```

por:

```php
                    if (art_image_is_valid_image_url($main_image_url)) {
                        $thumb_id = $this->artimage_download_and_attach_image($main_image_url, $new_prod_id);
                        if ($thumb_id) {
                            set_post_thumbnail($new_prod_id, $thumb_id);
                            $images_processed++;
                        } else {
                            ArtImageFailedProducts::record(
                                $sku,
                                (string)($p_data['link'] ?? ''),
                                (string)($p_data['title'] ?? ''),
                                'falha_imagem',
                                $data
                            );
                        }
                    }
```

- [ ] **Step 3: `resolve()` no sucesso**

Localizar o `return` de sucesso (linha ~1535-1541):

```php
            return [
                'success' => true,
                'product_id' => $new_prod_id,
                'sku' => $sku,
                'action' => $action,
                'images_processed' => $images_processed
            ];
```

Inserir **antes** do `return`:

```php
            // Importou com sucesso: remove qualquer registro de falha deste SKU
            ArtImageFailedProducts::resolve($sku);

```

- [ ] **Step 4: Lint**

Run: `php -l includes/importer.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add includes/importer.php
git commit -m "feat: captura timeout_detalhe/falha_imagem + resolve em import_single_product"
```

- [ ] **Step 6: Deploy** — publicar `includes/importer.php`; rodar `php -l` no publicado (via probe shell): esperado sem erros.

---

## Task 3: Captura nos mesmos pontos no caminho manual `import_products_batch`

**Files:**
- Modify: `includes/importer.php` (método `import_products_batch`, ~587-826)

- [ ] **Step 1: Capturar `timeout_detalhe` (manual)**

Localizar (linha ~650):

```php
                $details = !empty($p_data['link']) ? $this->client->get_product_details($p_data['link']) : [];
```

Substituir por:

```php
                $details = !empty($p_data['link']) ? $this->client->get_product_details($p_data['link']) : [];
                if (!empty($p_data['link']) && empty($details)) {
                    ArtImageFailedProducts::record(
                        $sku,
                        (string)($p_data['link'] ?? ''),
                        (string)($p_data['title'] ?? ''),
                        'timeout_detalhe',
                        $item_to_process
                    );
                }
```

(Obs.: aqui o payload é `$item_to_process`, que tem `product_data`, `subcategory_id`, `subcategory_parent_id` — mesma forma que `import_single_product` espera.)

- [ ] **Step 2: `resolve()` no sucesso (manual)**

Localizar (linha ~702):

```php
                if ($new_prod_id) {
                    $logs[] = ($product_id ? "Produto atualizado" : "Produto criado") . ": {$product->get_name()} (ID: {$new_prod_id})";
```

Inserir logo após essa linha do `$logs[]`:

```php
                    ArtImageFailedProducts::resolve($sku);
```

- [ ] **Step 3: Capturar `falha_imagem` (manual)** — localizar no mesmo método o trecho que processa a imagem principal via `artimage_download_and_attach_image(...)` (mesma assinatura da Task 2). No ramo em que o retorno é falsy (imagem não anexada) para uma URL válida, inserir:

```php
                            ArtImageFailedProducts::record(
                                $sku,
                                (string)($p_data['link'] ?? ''),
                                (string)($p_data['title'] ?? ''),
                                'falha_imagem',
                                $item_to_process
                            );
```

(Se o caminho manual não tiver tratamento de imagem principal espelhando a Task 2, pular este step — `timeout_detalhe` e `resolve` já cobrem o essencial do caminho manual. Registrar a decisão no commit.)

- [ ] **Step 4: Lint**

Run: `php -l includes/importer.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add includes/importer.php
git commit -m "feat: captura/resolve de falhas no caminho manual import_products_batch"
```

- [ ] **Step 6: Deploy** — publicar; `php -l` no publicado.

---

## Task 4: Captura `lote_300s` via hook de falha do Action Scheduler

**Files:**
- Modify: `includes/action-scheduler-manager.php` (novo método estático)
- Modify: `includes/loader.php` (registrar hook)

- [ ] **Step 1: Adicionar handler estático em `ArtImageASManager`**

No fim da classe `ArtImageASManager` (antes do `}` final da classe), adicionar:

```php
    /**
     * Handler do hook action_scheduler_failed_execution.
     * Quando uma ação import_products_batch morre (300s/fatal), registra os
     * produtos do lote como falha (lote_300s) para retry posterior.
     *
     * @param int $action_id
     */
    public static function on_action_failed($action_id): void
    {
        if (!function_exists('ActionScheduler')) {
            return;
        }
        try {
            $store = ActionScheduler::store();
            $action = $store->fetch_action($action_id);
            if (!$action || $action->get_hook() !== self::HOOK_PREFIX . 'import_products_batch') {
                return;
            }
            $args = $action->get_args();
            $products = $args['products'] ?? [];
            foreach ($products as $data) {
                $p = $data['product_data'] ?? $data;
                $code = isset($p['code']) ? sanitize_text_field($p['code']) : '';
                if ($code === '') {
                    continue;
                }
                ArtImageFailedProducts::record(
                    $code,
                    (string)($p['link'] ?? ''),
                    (string)($p['title'] ?? ''),
                    'lote_300s',
                    $data
                );
            }
        } catch (\Throwable $e) {
            // Nunca deixar o handler de falha lançar
            if (class_exists('ArtImageLogger')) {
                ArtImageLogger::error('Falha ao registrar lote morto: ' . $e->getMessage());
            }
        }
    }
```

(Confirmar que `HOOK_PREFIX` é a constante usada para montar `artimage_import_products_batch` — é a mesma usada em `schedule_product_batches`, linha ~158.)

- [ ] **Step 2: Registrar o hook em `includes/loader.php`**

Junto do hook do init da Task 1, adicionar:

```php
// Captura lotes de produto que morrem (300s/fatal) no Action Scheduler
add_action('action_scheduler_failed_execution', ['ArtImageASManager', 'on_action_failed'], 10, 1);
```

- [ ] **Step 3: Lint**

Run: `php -l includes/action-scheduler-manager.php && php -l includes/loader.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 4: Commit**

```bash
git add includes/action-scheduler-manager.php includes/loader.php
git commit -m "feat: captura lote_300s via action_scheduler_failed_execution"
```

- [ ] **Step 5: Deploy + verificação**

Publicar os dois arquivos. Verificação funcional (servidor): simular o handler com args fake:

Run: `wp eval '$id=as_schedule_single_action(time()+3600,"artimage_import_products_batch",["products"=>[["product_data"=>["code"=>"FAILTEST","link"=>"http://x/9","title"=>"X"]]]],"artimage"); do_action("action_scheduler_failed_execution",$id); echo ArtImageFailedProducts::count_pending();'`
Expected: conta de pendentes aumenta (FAILTEST registrado). Depois limpar: `wp eval 'ArtImageFailedProducts::resolve("FAILTEST"); as_unschedule_all_actions("artimage_import_products_batch",["products"=>[["product_data"=>["code"=>"FAILTEST","link"=>"http://x/9","title"=>"X"]]]],"artimage");'`

---

## Task 5: Captura `exception` (erros agregados) + Fase de retry

**Files:**
- Modify: `includes/sync-manager.php` (`as_import_products_batch` ~340-365; `as_start_import_phase` ~140-180; `as_check_phase_completion` ~186-250; arrays de fases linha 60 e 231)
- Modify: `includes/action-scheduler-manager.php` (`schedule_retry_batches`)

- [ ] **Step 1: Registrar `exception` nos erros agregados do lote**

Em `sync-manager.php`, no método `as_import_products_batch`, localizar (linha ~360-364):

```php
        // Log de erros específicos
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->log("[AS] Erro produto {$error['sku']}: {$error['error']}");
            }
        }
```

Substituir por:

```php
        // Log + registro de erros por-produto que NÃO mataram a ação
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->log("[AS] Erro produto {$error['sku']}: {$error['error']}");
                $sku = (string)($error['sku'] ?? '');
                if ($sku !== '' && $sku !== 'unknown') {
                    // Recupera o payload do produto correspondente no lote
                    $payload = [];
                    $link = '';
                    $name = '';
                    foreach ($products as $data) {
                        $p = $data['product_data'] ?? $data;
                        if ((string)($p['code'] ?? '') === $sku) {
                            $payload = $data;
                            $link = (string)($p['link'] ?? '');
                            $name = (string)($p['title'] ?? '');
                            break;
                        }
                    }
                    ArtImageFailedProducts::record($sku, $link, $name, 'exception', $payload);
                }
            }
        }
```

- [ ] **Step 2: Adicionar `schedule_retry_batches()` em `ArtImageASManager`**

Em `includes/action-scheduler-manager.php`, adicionar método estático (junto dos outros de agendamento):

```php
    /**
     * Re-enfileira produtos com falha em lotes de 1 (evita re-bater nos 300s).
     * Incrementa attempts dos SKUs re-enfileirados.
     *
     * @param array<int,object> $rows linhas de ArtImageFailedProducts::get_retryable()
     * @return int quantidade re-enfileirada
     */
    public static function schedule_retry_batches(array $rows): int
    {
        if (!self::is_available() || empty($rows)) {
            return 0;
        }
        $scheduled = 0;
        $codes = [];
        $timestamp = time();
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if (!is_array($payload) || empty($payload)) {
                continue;
            }
            as_schedule_single_action(
                $timestamp + ($scheduled * self::PRODUCT_BATCH_DELAY),
                self::HOOK_PREFIX . 'import_products_batch',
                ['products' => [$payload]], // lote de 1
                self::GROUP
            );
            $codes[] = $row->code;
            $scheduled++;
        }
        if (!empty($codes)) {
            ArtImageFailedProducts::increment_attempts($codes);
        }
        ArtImageTimezoneHelper::log_with_timezone("[AS] Retry: re-enfileirados {$scheduled} produtos (lote 1)");
        return $scheduled;
    }
```

- [ ] **Step 3: Adicionar `retry_products` às listas de fases**

Em `sync-manager.php`, na linha ~60 e na linha ~231, trocar:

```php
        $phases = ['categories', 'subcategories', 'artists', 'prepare_products', 'products'];
```

por:

```php
        $phases = ['categories', 'subcategories', 'artists', 'prepare_products', 'products', 'retry_products'];
```

(São duas ocorrências — alterar ambas.)

- [ ] **Step 4: Iniciar a fase `retry_products`**

No método `as_start_import_phase` (switch por fase, ~140-178), adicionar um `case` para `retry_products` que re-enfileira os retryables e agenda a verificação de conclusão. Inserir antes do `default`/fim do switch:

```php
            case 'retry_products':
                $rows = ArtImageFailedProducts::get_retryable();
                $count = ArtImageASManager::schedule_retry_batches($rows);
                $this->log("[AS] Fase retry_products: {$count} produtos re-enfileirados");
                // Se nada para re-tentar, conclui o sync direto
                if ($count === 0) {
                    $this->as_complete_sync($session_id);
                    return;
                }
                break;
```

(Confirmar que a verificação de conclusão da fase é agendada ao final do método para todas as fases — `retry_products` reutiliza `is_products_phase_complete()` porque também usa o hook `import_products_batch`. O `check_delay` da linha ~179 já trata `products`; incluir `retry_products` no mesmo ramo de 60s.)

Na linha ~179, trocar:

```php
        $check_delay = ($phase === 'prepare_products' || $phase === 'products') ? 60 : 30;
```

por:

```php
        $check_delay = in_array($phase, ['prepare_products', 'products', 'retry_products'], true) ? 60 : 30;
```

- [ ] **Step 5: Concluir/avançar a fase `products` → `retry_products`, e `retry_products` → fim**

Em `as_check_phase_completion` (~186-250):

(a) No mapa `$entity_map` (~188-194), adicionar `'retry_products' => null,`.

(b) Na verificação especial, fazer `retry_products` reusar a checagem de batches. Trocar o bloco (~205-211):

```php
        } elseif ($phase === 'products') {
            if (!ArtImageASManager::is_products_phase_complete()) {
                $this->log("[AS] Fase produtos (batches) ainda em progresso, verificando novamente em 30s");
                ArtImageASManager::schedule_phase_check($phase, $session_id, 30);
                return;
            }
```

por:

```php
        } elseif ($phase === 'products' || $phase === 'retry_products') {
            if (!ArtImageASManager::is_products_phase_complete()) {
                $this->log("[AS] Fase {$phase} (batches) ainda em progresso, verificando novamente em 30s");
                ArtImageASManager::schedule_phase_check($phase, $session_id, 30);
                return;
            }
```

(c) Trocar a finalização (~239-243). Hoje:

```php
        // Se é a última fase (products), finalizar sincronização
        if ($phase === 'products') {
            $this->as_complete_sync($session_id);
            return;
        }
```

por:

```php
        // Após 'products': se houver retryables, ir para a fase de retry; senão concluir.
        if ($phase === 'products') {
            if (!empty(ArtImageFailedProducts::get_retryable())) {
                // limpar o guard para permitir reentrar em retry_products em rodadas seguintes
                delete_transient('artimage_as_phase_retry_products_completed');
                $this->log("[AS] Avançando para fase: retry_products");
                ArtImageASManager::schedule_phase_start('retry_products', $session_id);
            } else {
                $this->as_complete_sync($session_id);
            }
            return;
        }

        // Após 'retry_products': se ainda há retryables (attempts < 3), repetir; senão concluir.
        if ($phase === 'retry_products') {
            if (!empty(ArtImageFailedProducts::get_retryable())) {
                delete_transient('artimage_as_phase_retry_products_completed');
                $this->log("[AS] Retry: ainda há produtos elegíveis, nova rodada");
                ArtImageASManager::schedule_phase_start('retry_products', $session_id);
            } else {
                $this->as_complete_sync($session_id);
            }
            return;
        }
```

(O `array_search`/`$next_phase` abaixo continua válido para as demais fases.)

- [ ] **Step 6: Lint**

Run: `php -l includes/sync-manager.php && php -l includes/action-scheduler-manager.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 7: Commit**

```bash
git add includes/sync-manager.php includes/action-scheduler-manager.php
git commit -m "feat: captura exception agregada + fase retry_products (lote 1, ate 3x)"
```

- [ ] **Step 8: Deploy + verificação**

Publicar os dois arquivos; `php -l` no publicado. Verificação de loop de tentativas (servidor):

Run: `wp eval 'ArtImageFailedProducts::record("RT1","http://artimage.com.br/produtos/detalhe/1","P","timeout_detalhe",["product_data"=>["code"=>"RT1","link"=>"http://artimage.com.br/produtos/detalhe/1","title"=>"P"]]); $r=ArtImageFailedProducts::get_retryable(); echo count($r); ArtImageASManager::schedule_retry_batches($r); $r2=ArtImageFailedProducts::get_retryable(); echo "|".(int)$r2[0]->attempts;'`
Expected: imprime `1|1` (1 retryable; após agendar, attempts = 1). Limpar depois: `wp eval 'ArtImageFailedProducts::resolve("RT1");'` e cancelar a action de teste.

---

## Task 6: Aba "Falhas" no admin

**Files:**
- Modify: `admin/admin-ui.php`

- [ ] **Step 1: Adicionar o link da aba**

Em `admin/admin-ui.php`, na barra de abas (~31), após a aba de Diagnóstico, adicionar:

```php
    echo '<a href="?page=art-image&tab=failures" class="nav-tab ' . ($active_tab === 'failures' ? 'nav-tab-active' : '') . '">Falhas</a>';
```

- [ ] **Step 2: Renderizar o conteúdo da aba**

Antes do `echo '</div>';` final de `art_image_render_admin_page` (~95), adicionar:

```php
    if ($active_tab === 'failures') {
        $pendentes = ArtImageFailedProducts::get_pending();
        $total = count($pendentes);
        echo '<div class="art-image-admin">';
        echo '<h3>Produtos com falha</h3>';
        echo '<p>' . esc_html($total) . ' produto(s) com falha pendente. O retry automático (até 3x) roda no fim de cada importação.</p>';
        echo '<p><button class="button button-primary" id="artimage-retry-failed">Tentar todos agora</button> <span id="artimage-retry-status"></span></p>';

        if ($total === 0) {
            echo '<p><em>Nenhuma falha registrada.</em></p>';
        } else {
            echo '<table class="wp-list-table widefat striped"><thead><tr>';
            echo '<th>SKU</th><th>Produto</th><th>Link na artimage</th><th>Motivo</th><th>Tentativas</th><th>Última falha</th>';
            echo '</tr></thead><tbody>';
            foreach ($pendentes as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->code) . '</td>';
                echo '<td>' . esc_html($row->name) . '</td>';
                echo '<td>';
                if (!empty($row->source_url)) {
                    echo '<a href="' . esc_url($row->source_url) . '" target="_blank" rel="noopener">abrir</a>';
                } else {
                    echo '—';
                }
                echo '</td>';
                echo '<td>' . esc_html($row->reason) . '</td>';
                echo '<td>' . esc_html((string)$row->attempts) . '</td>';
                echo '<td>' . esc_html((string)$row->last_failed_at) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
```

- [ ] **Step 3: Adicionar o JS do botão (inline, no mesmo bloco da aba)**

Logo após a tabela (ainda dentro do `if ($active_tab === 'failures')`, antes do `echo '</div>';`), adicionar:

```php
        $nonce = wp_create_nonce('art_image_nonce');
        $ajax = admin_url('admin-ajax.php');
        echo "<script>
        (function(){
            var b=document.getElementById('artimage-retry-failed');
            if(!b)return;
            b.addEventListener('click',function(){
                b.disabled=true;
                document.getElementById('artimage-retry-status').textContent='Re-enfileirando...';
                var body='action=art_image_retry_failed_products&_ajax_nonce=" . esc_js($nonce) . "';
                fetch('" . esc_js($ajax) . "',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
                  .then(function(r){return r.json();})
                  .then(function(j){document.getElementById('artimage-retry-status').textContent=(j&&j.data&&j.data.message)||'OK'; setTimeout(function(){location.reload();},1500);})
                  .catch(function(){document.getElementById('artimage-retry-status').textContent='Erro'; b.disabled=false;});
            });
        })();
        </script>";
```

- [ ] **Step 4: Lint**

Run: `php -l admin/admin-ui.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add admin/admin-ui.php
git commit -m "feat: aba Falhas no admin (lista + link artimage + botao retry)"
```

- [ ] **Step 6: Deploy + verificação manual**

Publicar `admin/admin-ui.php`. Abrir no admin: `?page=art-image&tab=failures`. Esperado: a aba aparece, lista os pendentes (se houver), com link clicável para a artimage e o botão "Tentar todos agora".

---

## Task 7: AJAX "Tentar todos agora"

**Files:**
- Modify: `includes/async-handler.php`

- [ ] **Step 1: Registrar e implementar o handler**

Em `includes/async-handler.php`, junto dos outros `add_action('wp_ajax_...')`, adicionar:

```php
add_action('wp_ajax_art_image_retry_failed_products', 'art_image_handle_retry_failed_products');
```

E adicionar a função:

```php
function art_image_handle_retry_failed_products() {
    check_ajax_referer('art_image_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sem permissão']);
    }
    // Retry manual: force = true (ignora o limite de 3 tentativas)
    $rows = ArtImageFailedProducts::get_retryable(true);
    $count = ArtImageASManager::schedule_retry_batches($rows);
    wp_send_json_success([
        'message' => "{$count} produto(s) re-enfileirado(s). Serão processados pelo cron.",
        'count' => $count,
    ]);
}
```

- [ ] **Step 2: Lint**

Run: `php -l includes/async-handler.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/async-handler.php
git commit -m "feat: AJAX art_image_retry_failed_products (botao Tentar todos agora)"
```

- [ ] **Step 4: Deploy + verificação**

Publicar. Na aba "Falhas", clicar "Tentar todos agora" → esperado: mensagem "N produto(s) re-enfileirado(s)" e a página recarrega. Conferir no log do plugin a linha `[AS] Retry: re-enfileirados N produtos (lote 1)`.

---

## Task 8: Push + verificação fim-a-fim em produção

- [ ] **Step 1: Push de todos os commits**

```bash
git push origin main
```

- [ ] **Step 2: Confirmar publicação de todos os arquivos** no servidor (failed-products.php, loader.php, importer.php, sync-manager.php, action-scheduler-manager.php, admin-ui.php, async-handler.php) e `php -l` em cada um (via probe shell) — todos sem erro.

- [ ] **Step 3: Verificação fim-a-fim (servidor):**
  - Conferir que a tabela existe e tem registros conforme a importação roda: `wp eval 'echo ArtImageFailedProducts::count_pending();'`
  - Aguardar a fase de produtos concluir e conferir no log do plugin: aparece `[AS] Avançando para fase: retry_products` e `[AS] Retry: re-enfileirados N produtos (lote 1)`.
  - Conferir que produtos re-tentados com sucesso somem da aba (count_pending cai).
  - Conferir que, após 3 rodadas, o que sobrar fica listado na aba com `attempts = 3`.

- [ ] **Step 4: Limpeza** de quaisquer registros de teste (`TESTE1`, `FAILTEST`, `RT1`) que tenham sobrado: `wp eval 'foreach(["TESTE1","FAILTEST","RT1"] as $c){ArtImageFailedProducts::resolve($c);}'`

---

## Self-Review (cobertura do spec)

- **Tabela própria (spec §3.1):** Task 1. ✓
- **Captura escopo "tudo" (spec §3.2):** `lote_300s` (Task 4), `exception` agregado (Task 5 step 1), `timeout_detalhe` (Tasks 2/3), `falha_imagem` (Tasks 2/3). ✓
- **Dois caminhos de import:** `import_single_product` (Task 2) + `import_products_batch` manual (Task 3). ✓
- **Resolução ao importar com sucesso (spec §3.3):** Tasks 2/3 (`resolve()`). ✓
- **Retry automático até 3x, lote 1 (spec §3.4):** Task 5 (fase `retry_products`, `get_retryable` com `attempts < 3`, `schedule_retry_batches` lote 1, loop até esgotar). ✓
- **Aba "Falhas" (spec §3.5):** Task 6 (lista mínima + link artimage + "Tentar todos agora"). ✓
- **Botão retry manual / AJAX:** Task 7 (`force = true`). ✓
- **Caso degenerado sem payload (spec §5):** `get_retryable` exige `payload IS NOT NULL AND payload <> ''` → entradas sem payload ficam listadas mas não entram no retry. ✓
- **Deploy repo + produção (spec §7):** steps de deploy em cada task + Task 8. ✓
