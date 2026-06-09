# Design — Retry de produtos com falha + Aba de Falhas

**Data:** 2026-06-09
**Plugin:** Art Image (WordPress / WooCommerce, importação via Action Scheduler)
**Status:** Aprovado pelo usuário (aguardando review do spec)

## 1. Contexto e problema

A importação de produtos roda em lotes via Action Scheduler (hook `artimage_import_products_batch`, 5 produtos por lote). Hoje, falhas acontecem e **se perdem**:

- **Lote estoura 300s** (`action_scheduler_failure_period`): quando o site externo (artimage.com.br) está lento, um lote de 5 produtos passa de 300s e o Action Scheduler mata a ação. Os ~5 produtos do lote não entram. (Observado: ~13 lotes mortos em 1h30 num dia de lentidão.)
- **Timeout de detalhe** (`cURL error 28`, `art_image_api_timeout` = 20s): a página de detalhe de um produto não responde a tempo. O produto é criado **degradado** (só com dados da listagem: título/preço; sem descrição/dados da página de detalhe).
- **Falha de download de imagem**: a imagem do produto não baixa.

Não há registro estruturado dessas falhas nem mecanismo de re-tentativa. O operador não tem visibilidade do que falhou.

## 2. Objetivos

1. **Rastrear** todo produto que falhou, em qualquer um dos 3 modos acima (escopo "tudo").
2. **Re-tentar automaticamente** os produtos com falha **depois** que a importação normal de produtos terminar, em **até 3 tentativas**.
3. **Aba "Falhas"** no admin do plugin: lista mínima dos produtos pendentes, cada um com **link para o produto na artimage**, e um botão **"Tentar todos agora"** (retry manual).

### Não-objetivos
- Não alterar o `api_timeout` (20s) — decisão do usuário de manter; as falhas resultantes serão capturadas e re-tentadas.
- Não criar ações por linha (retry individual) nem filtros na aba — escopo mínimo.
- Não exibir histórico de resolvidos — produto que volta a importar com sucesso sai da lista.

## 3. Arquitetura

### 3.1 Armazenamento — tabela própria

Tabela `{$wpdb->prefix}artimage_failed_products`, criada via `dbDelta` na ativação e numa checagem de versão/init (idempotente, para o plugin já ativo em produção).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | — |
| `code` | VARCHAR(191) UNIQUE | SKU/código do produto (chave de deduplicação) |
| `source_url` | VARCHAR(255) | Link do produto na artimage (`/produtos/detalhe/{id}`) |
| `name` | VARCHAR(255) | Nome do produto (para exibição) |
| `reason` | VARCHAR(40) | `lote_300s` \| `timeout_detalhe` \| `falha_imagem` \| `exception` |
| `payload` | LONGTEXT | JSON dos dados de listagem do produto (`$p_data`), suficiente para re-importar |
| `attempts` | SMALLINT UNSIGNED DEFAULT 0 | nº de re-tentativas já feitas |
| `status` | VARCHAR(20) DEFAULT 'pendente' | `pendente` \| `resolvido` |
| `first_failed_at` | DATETIME | primeira falha |
| `last_failed_at` | DATETIME | falha mais recente |

Gravação com `INSERT ... ON DUPLICATE KEY UPDATE` por `code` → evita corrida quando vários lotes falham ao mesmo tempo; re-falha atualiza `reason`/`last_failed_at` sem duplicar.

`payload` guarda os dados da listagem (`$p_data`) para que o retry re-importe sem depender da ação do Action Scheduler original (que pode já ter sido podada).

### 3.2 Pontos de captura (escopo "tudo")

1. **Lote 300s / exception (ação morta):** via o hook do Action Scheduler `action_scheduler_failed_execution`. Quando uma ação `artimage_import_products_batch` falha, o handler extrai a lista de produtos dos `args` e registra cada um (`reason = lote_300s`). É a forma confiável de capturar a ação que foi morta no meio (não dá para capturar de dentro dela).
2. **Timeout de detalhe (degradado):** no fluxo de importação do produto, quando `get_product_details()` retorna vazio por erro/timeout → registra (`reason = timeout_detalhe`). O produto continua sendo criado degradado, mas fica marcado.
3. **Falha de imagem:** quando o download da imagem retorna falha → registra (`reason = falha_imagem`).

### 3.3 Resolução (saída da lista)

Sempre que um produto é importado **com sucesso** (no fluxo normal ou no retry) — detalhe obtido e produto salvo sem erro — chama `resolve($code)`, que **remove** o registro da tabela. Assim a aba mostra só o que ainda está pendente.

### 3.4 Retry automático (até 3x)

Quando a fase de produtos conclui (no avanço de fase após `products`), agenda uma **fase de retry**:

- Seleciona registros `status = 'pendente'` e `attempts < 3`.
- Re-enfileira como ações de importação, porém com **lote de tamanho 1** (1 produto por ação) — isso evita re-bater no limite de 300s (a causa original de boa parte das falhas) e isola produto lento.
- Cada produto re-enfileirado tem `attempts` incrementado.
- A reimportação usa o mesmo caminho de importação, a partir do `payload`.
- O que continuar falhando após 3 tentativas permanece `pendente` na aba (para retry manual ou próximo ciclo semanal).

### 3.5 Aba "Falhas" (admin)

Nova aba em `admin/admin-ui.php` (segue o padrão existente: link `nav-tab` + bloco `if ($active_tab === 'failures')`).

- Lista de `status = 'pendente'`, colunas: **código (SKU)**, **link para a artimage** (`source_url`, clicável, abre em nova aba), **motivo**, **tentativas**, **última falha**.
- Contador no topo ("X produtos com falha").
- Botão **"Tentar todos agora"** → AJAX que re-enfileira os pendentes (manual: permite mesmo com `attempts >= 3`).
- Estado vazio: "Nenhuma falha registrada."

## 4. Componentes / arquivos

- **Novo:** `includes/failed-products.php` — classe `ArtImageFailedProducts`:
  - `maybe_create_table()` (dbDelta, idempotente)
  - `record($code, $source_url, $name, $reason, $payload)` (upsert)
  - `resolve($code)` (delete)
  - `get_pending($limit)` / `count_pending()`
  - `get_retryable()` (pendente, attempts < 3)
  - `increment_attempts($codes)`
  - `retry_all($force = false)` (re-enfileira via Action Scheduler, lote 1)
- **`includes/loader.php`:** `require_once` do novo arquivo; registra `maybe_create_table` na ativação/init; registra o hook `action_scheduler_failed_execution`.
- **`includes/importer.php`:** chama `record(... timeout_detalhe/falha_imagem ...)` nos pontos de captura 2 e 3; chama `resolve($code)` no sucesso.
- **`includes/action-scheduler-manager.php`:** após a fase `products`, agenda a fase de retry; handler do hook de falha (captura 1) registra os produtos do lote morto.
- **`admin/admin-ui.php`:** nova aba "Falhas" + renderização da lista.
- **`includes/async-handler.php`:** handler AJAX `art_image_retry_failed_products` (nonce) para o botão "Tentar todos agora".

## 5. Tratamento de erros e casos de borda

- **Tabela inexistente** (plugin já ativo): `maybe_create_table()` roda no init/upgrade, cria se faltar.
- **Corrida** (vários lotes falham juntos): `ON DUPLICATE KEY UPDATE` por `code`.
- **Retry re-bate em lentidão:** `attempts` incrementa; após 3, fica pendente (não martela infinito).
- **Produto degradado que depois importa OK no retry:** `resolve()` remove o registro.
- **Mesmo produto falha de 2 formas** (ex.: lote_300s e depois timeout_detalhe): 1 linha por `code`, `reason` reflete a falha mais recente.
- **`payload` ausente** (falha capturada sem dados de listagem completos): o retry tenta pelo `source_url`/`code`; se não der, mantém pendente.

## 6. Testes

- **Unitário/lógico:** `record()` insere e faz upsert; `resolve()` remove; `get_pending()`/`get_retryable()` filtram corretamente; `increment_attempts()` soma.
- **Integração simulada:** simular ação `import_products_batch` falha → captura 1 grava os produtos do `args`; simular `get_product_details` vazio → captura 2; sucesso no retry → `resolve` remove.
- **Manual (produção):** reduzir temporariamente o `api_timeout` para forçar falhas → conferir que aparecem na aba com link; clicar "Tentar todos agora" → conferir que somem ao importar; conferir que o retry usa lote 1.

## 7. Notas de implementação / deploy

- As mudanças vão para o repositório **e** para o código publicado (o site em produção é editado direto, como nas correções anteriores). A criação da tabela roda no init.
- Reaproveitar o caminho de importação existente no retry (não duplicar lógica de import).
- Manter o padrão de logging (`ArtImageLogger` / `log_with_timezone`) nos pontos de captura e no retry.
