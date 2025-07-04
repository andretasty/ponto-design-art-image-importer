<?php
if (! defined('ABSPATH')) {
    exit;
}

// Garante que o WooCommerce está ativo
if (! class_exists('WooCommerce')) {
    return;
}

/**
 * Classe responsável por importar dados do ArtImageApiClient
 */
class ArtImageImporter
{
    private $client;

    public function __construct()
    {
        $this->client = new ArtImageApiClient();
    }

    /**
     * Limpa transients e locks para um tipo de importação.
     */
    private function cleanup_import(string $type): void {
        $master_lock_key = 'artimage_master_import_lock';
        switch ($type) {
            case 'categories':
                delete_transient('artimage_category_import_queue');
                delete_transient('artimage_category_import_total');
                delete_transient('artimage_category_processed_count');
                delete_transient('artimage_category_batch_lock');
                delete_transient('artimage_cancel_category_import_flag');
                break;
            case 'subcategories':
                delete_transient('artimage_subcategory_import_queue');
                delete_transient('artimage_subcategory_import_total');
                delete_transient('artimage_subcategory_processed_count');
                delete_transient('artimage_subcategory_batch_lock');
                delete_transient('artimage_cancel_subcategory_import_flag');
                break;
            case 'products':
                delete_transient('artimage_product_import_queue');
                delete_transient('artimage_product_processed_count');
                delete_transient('artimage_product_import_total');
                delete_transient('artimage_product_batch_lock');
                delete_transient('artimage_cancel_product_import_flag');
                break;
            case 'artists':
                delete_transient('artimage_artist_import_queue');
                delete_transient('artimage_artist_import_total');
                delete_transient('artimage_artist_processed_count');
                delete_transient('artimage_artist_batch_lock');
                delete_transient('artimage_cancel_artist_import_flag');
                break;
        }
        if (get_transient($master_lock_key) === $type) {
            delete_transient($master_lock_key);
        }
    }

    /**
     * Importa categorias principais para product_cat
     */
    public function import_categories(int $page = 1, int $batch_size = 20): array
    {
        global $artimage_sync_tracker;
        
        $logs = [];
        $processed_in_batch = 0;
        $master_lock_key = 'artimage_master_import_lock';
        $batch_lock_key = 'artimage_category_batch_lock';
        $queue_key = 'artimage_category_import_queue';
        $total_key = 'artimage_category_import_total';
        $processed_key = 'artimage_category_processed_count';
        $cancel_flag_key = 'artimage_cancel_category_import_flag';

        if ($page === 1) {
            if (get_transient($master_lock_key)) {
                return [
                    'status' => 'error',
                    'logs' => ['Outro processo de importação (master) já está em execução – abortando categorias.'],
                    'has_more' => false,
                ];
            }
            set_transient($master_lock_key, 'categories', 15 * MINUTE_IN_SECONDS); // Lock with type
            set_transient($batch_lock_key, 1, 15 * MINUTE_IN_SECONDS);
            delete_transient($cancel_flag_key); // Clear previous cancel flag

            $all_cats_data = $this->client->get_main_categories();
            if (empty($all_cats_data)) {
                delete_transient($master_lock_key);
                delete_transient($batch_lock_key);
                $logs[] = "Nenhuma categoria principal encontrada para importar.";
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => 0,
                    'total_to_import' => 0,
                    'has_more' => false,
                ];
            }

            set_transient($queue_key, $all_cats_data, 1 * HOUR_IN_SECONDS);
            set_transient($total_key, count($all_cats_data), 1 * HOUR_IN_SECONDS);
            set_transient($processed_key, 0, 1 * HOUR_IN_SECONDS);
            $logs[] = "Fila de importação de categorias preparada com " . count($all_cats_data) . " itens.";
        } else {
            if (!get_transient($batch_lock_key)) {
                $current_master_holder = get_transient($master_lock_key);
                if ($current_master_holder === 'categories') {
                     delete_transient($master_lock_key); 
                }
                return [
                    'status' => 'error',
                    'logs' => ['Erro: Processo de importação de categorias em lote não iniciado ou bloqueio de lote expirou.'],
                    'has_more' => false,
                ];
            }
        }

        if (get_transient($cancel_flag_key)) {
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'categories') {
                delete_transient($master_lock_key);
            }
            delete_transient($cancel_flag_key);
            $logs[] = "Importação de categorias cancelada pelo usuário.";
            return ['status' => 'cancelled', 'logs' => $logs, 'has_more' => false];
        }

        $category_queue = get_transient($queue_key);
        $total_to_import = (int) get_transient($total_key);
        $current_total_processed = (int) get_transient($processed_key);

        if ($category_queue === false || ($total_to_import === 0 && $page > 1)) {
            if (get_transient($master_lock_key) === 'categories') {
                delete_transient($master_lock_key);
            }
            delete_transient($batch_lock_key);
            return [
                'status' => 'error',
                'logs' => ['Erro: Fila de importação de categorias não encontrada ou vazia inesperadamente.'],
                'has_more' => false,
            ];
        }
        
        $offset = ($page - 1) * $batch_size;
        $batch_items = array_slice((array)$category_queue, $offset, $batch_size);

        if (empty($batch_items)) {
            if ($current_total_processed >= $total_to_import) {
                $logs[] = "Importação de categorias concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
                delete_transient($queue_key);
                delete_transient($total_key);
                delete_transient($processed_key);
                delete_transient($batch_lock_key);
                if (get_transient($master_lock_key) === 'categories') {
                    delete_transient($master_lock_key);
                }
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            } else {
                $logs[] = "Lote de categorias vazio, mas a importação não foi concluída. Verificando...";
                 if (get_transient($master_lock_key) === 'categories') {
                    delete_transient($master_lock_key);
                }
                delete_transient($batch_lock_key);
                return [
                    'status' => 'error',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
        }
        
        $logs[] = "Processando lote {$page} de categorias. Itens no lote: " . count($batch_items) . ". Processados anteriormente: {$current_total_processed} de {$total_to_import}.";

        foreach ($batch_items as $cat_data) {
            if (get_transient($cancel_flag_key)) {
                $this->cleanup_import('categories');
                $logs[] = 'Importação de categorias cancelada pelo usuário.';
                return [
                    'status' => 'cancelled',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
            $term = term_exists($cat_data['slug'], 'product_cat');
            if (!$term) {
                $result = wp_insert_term($cat_data['nome'], 'product_cat', [
                    'slug' => $cat_data['slug'],
                ]);
                if (is_wp_error($result)) {
                    $logs[] = 'Erro ao criar categoria "' . $cat_data['nome'] . '": ' . $result->get_error_message();
                } else {
                    $term_id = $result['term_id'];
                    // Marca categoria como importada
                    if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                        $artimage_sync_tracker->mark_category_imported($term_id, $cat_data['slug']);
                    }
                    $logs[] = "Categoria criada: {$cat_data['nome']}";
                }
            } else {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                // Marca categoria existente como importada
                if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                    $artimage_sync_tracker->mark_category_imported($term_id, $cat_data['slug']);
                }
                $logs[] = "Categoria já existe: {$cat_data['nome']}";
            }
            $current_total_processed++;
            $processed_in_batch++;
        }

        set_transient($processed_key, $current_total_processed, 1 * HOUR_IN_SECONDS);
        $has_more = $current_total_processed < $total_to_import;

        if (!$has_more) {
            $logs[] = "Importação de categorias concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'categories') {
                delete_transient($master_lock_key);
            }
        } else {
            $logs[] = "Lote {$page} de categorias concluído. {$current_total_processed}/{$total_to_import} categorias processadas no total.";
        }

        return [
            'status' => $has_more ? 'processing' : 'completed',
            'logs' => $logs,
            'processed_in_batch' => $processed_in_batch,
            'current_total_processed' => $current_total_processed,
            'total_to_import' => $total_to_import,
            'has_more' => $has_more,
            'next_page' => $page + 1
        ];
    }

    /**
     * Importa subcategorias para product_cat, com parent
     */
    public function import_subcategories(int $page = 1, int $batch_size = 20): array
    {
        global $artimage_sync_tracker;
        
        $logs = [];
        $processed_in_batch = 0;
        $master_lock_key = 'artimage_master_import_lock';
        $batch_lock_key = 'artimage_subcategory_batch_lock';
        $queue_key = 'artimage_subcategory_import_queue';
        $total_key = 'artimage_subcategory_import_total';
        $processed_key = 'artimage_subcategory_processed_count';
        $cancel_flag_key = 'artimage_cancel_subcategory_import_flag';

        if ($page === 1) {
            if (get_transient($master_lock_key)) {
                return [
                    'status' => 'error',
                    'logs' => ['Outro processo de importação (master) já está em execução – abortando subcategorias.'],
                    'has_more' => false,
                ];
            }
            set_transient($master_lock_key, 'subcategories', 15 * MINUTE_IN_SECONDS);
            set_transient($batch_lock_key, 1, 15 * MINUTE_IN_SECONDS);
            delete_transient($cancel_flag_key);

            $logs[] = "Iniciando coleta de todas as subcategorias...";
            $all_subcats_to_import = [];
            $parent_categories = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => 0, 
            ]);

            if (is_wp_error($parent_categories)) {
                delete_transient($master_lock_key);
                delete_transient($batch_lock_key);
                $logs[] = "Erro ao buscar categorias pai: " . $parent_categories->get_error_message();
                return ['status' => 'error', 'logs' => $logs, 'has_more' => false];
            }
            if (empty($parent_categories)) {
                 delete_transient($master_lock_key);
                 delete_transient($batch_lock_key);
                 $logs[] = "Nenhuma categoria pai encontrada para buscar subcategorias.";
                 return ['status' => 'completed', 'logs' => $logs, 'current_total_processed' => 0, 'total_to_import' => 0, 'has_more' => false];
            }

            foreach ($parent_categories as $parent_cat) {
                $url = "https://artimage.com.br/produtos/{$parent_cat->slug}";
                $sub_data_from_api = $this->client->get_subcategories($url);
                if (!empty($sub_data_from_api)) {
                    foreach ($sub_data_from_api as $sub_item) {
                        // Gera slug base e slug único
                        $base_slug = sanitize_title($sub_item['nome']);
                        $unique_slug = $base_slug . '-' . $parent_cat->term_id;
                        $all_subcats_to_import[] = [
                            'nome' => $sub_item['nome'],
                            'slug' => $unique_slug,
                            'parent_id' => $parent_cat->term_id,
                            'parent_name' => $parent_cat->name,
                            'url' => $sub_item['url'],
                        ];
                    }
                    $logs[] = "Coletadas " . count($sub_data_from_api) . " subcategorias de {$parent_cat->name}.";
                } else {
                    $logs[] = "Nenhuma subcategoria encontrada para {$parent_cat->name} na API.";
                }
            }

            if (empty($all_subcats_to_import)) {
                delete_transient($master_lock_key);
                delete_transient($batch_lock_key);
                $logs[] = "Nenhuma subcategoria encontrada no total para importar.";
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => 0,
                    'total_to_import' => 0,
                    'has_more' => false,
                ];
            }

            set_transient($queue_key, $all_subcats_to_import, 1 * HOUR_IN_SECONDS);
            set_transient($total_key, count($all_subcats_to_import), 1 * HOUR_IN_SECONDS);
            set_transient($processed_key, 0, 1 * HOUR_IN_SECONDS);
            $logs[] = "Fila de importação de subcategorias preparada com " . count($all_subcats_to_import) . " itens.";

        } else {
            if (!get_transient($batch_lock_key)) {
                $current_master_holder = get_transient($master_lock_key);
                if ($current_master_holder === 'subcategories') {
                     delete_transient($master_lock_key);
                }
                return [
                    'status' => 'error',
                    'logs' => ['Erro: Processo de importação de subcategorias em lote não iniciado ou bloqueio de lote expirou.'],
                    'has_more' => false,
                ];
            }
        }

        if (get_transient($cancel_flag_key)) {
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'subcategories') {
                delete_transient($master_lock_key);
            }
            delete_transient($cancel_flag_key);
            $logs[] = "Importação de subcategorias cancelada pelo usuário.";
            return ['status' => 'cancelled', 'logs' => $logs, 'has_more' => false];
        }

        $subcategory_queue = get_transient($queue_key);
        $total_to_import = (int) get_transient($total_key);
        $current_total_processed = (int) get_transient($processed_key);

        if ($subcategory_queue === false || ($total_to_import === 0 && $page > 1)) {
             if (get_transient($master_lock_key) === 'subcategories') {
                delete_transient($master_lock_key);
            }
            delete_transient($batch_lock_key);
            return [
                'status' => 'error',
                'logs' => ['Erro: Fila de importação de subcategorias não encontrada ou vazia inesperadamente.'],
                'has_more' => false,
            ];
        }

        $offset = ($page - 1) * $batch_size;
        $batch_items = array_slice((array)$subcategory_queue, $offset, $batch_size);

        if (empty($batch_items)) {
            if ($current_total_processed >= $total_to_import) {
                $logs[] = "Importação de subcategorias concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
                delete_transient($queue_key);
                delete_transient($total_key);
                delete_transient($processed_key);
                delete_transient($batch_lock_key);
                if (get_transient($master_lock_key) === 'subcategories') {
                    delete_transient($master_lock_key);
                }
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            } else {
                $logs[] = "Lote de subcategorias vazio, mas a importação não foi concluída. Verificando...";
                 if (get_transient($master_lock_key) === 'subcategories') {
                    delete_transient($master_lock_key);
                }
                delete_transient($batch_lock_key);
                return [
                    'status' => 'error',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
        }

        $logs[] = "Processando lote {$page} de subcategorias. Itens no lote: " . count($batch_items) . ". Processados anteriormente: {$current_total_processed} de {$total_to_import}.";

        foreach ($batch_items as $sub_data) {
            if (get_transient($cancel_flag_key)) {
                $this->cleanup_import('subcategories');
                $logs[] = 'Importação de subcategorias cancelada pelo usuário.';
                return [
                    'status' => 'cancelled',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
            $term = term_exists($sub_data['slug'], 'product_cat');
            // Extrai o filtro-sub da URL, se existir
            $filtro_sub_id = null;
            if (!empty($sub_data['url']) && preg_match('/[?&]filtro-sub=(\d+)/', $sub_data['url'], $match)) {
                $filtro_sub_id = $match[1];
            }
            if (!$term) {
                $result = wp_insert_term($sub_data['nome'], 'product_cat', [
                    'slug'   => $sub_data['slug'],
                    'parent' => $sub_data['parent_id'],
                ]);
                if (is_wp_error($result)) {
                    $logs[] = 'Erro ao criar subcategoria "' . $sub_data['nome'] . '" (pai: ' . $sub_data['parent_name'] . '): ' . $result->get_error_message();
                } else {
                    $term_id = $result['term_id'];
                    $logs[] = "Subcategoria criada: {$sub_data['nome']} (pai: {$sub_data['parent_name']})";
                    if ($filtro_sub_id) {
                        update_term_meta($term_id, 'filtro_sub_id', $filtro_sub_id);
                    }
                    // Marca subcategoria como importada
                    if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                        $artimage_sync_tracker->mark_category_imported($term_id, $sub_data['slug']);
                    }
                }
            } else {
                $logs[] = "Subcategoria já existe: {$sub_data['nome']}";
                $term_id = is_array($term) ? $term['term_id'] : $term;
                if ($filtro_sub_id) {
                    update_term_meta($term_id, 'filtro_sub_id', $filtro_sub_id);
                }
                // Marca subcategoria existente como importada
                if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                    $artimage_sync_tracker->mark_category_imported($term_id, $sub_data['slug']);
                }
            }
            $current_total_processed++;
            $processed_in_batch++;
        }

        set_transient($processed_key, $current_total_processed, 1 * HOUR_IN_SECONDS);
        $has_more = $current_total_processed < $total_to_import;

        if (!$has_more) {
            $logs[] = "Importação de subcategorias concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'subcategories') {
                delete_transient($master_lock_key);
            }
        } else {
            $logs[] = "Lote {$page} de subcategorias concluído. {$current_total_processed}/{$total_to_import} subcategorias processadas no total.";
        }

        return [
            'status' => $has_more ? 'processing' : 'completed',
            'logs' => $logs,
            'processed_in_batch' => $processed_in_batch,
            'current_total_processed' => $current_total_processed,
            'total_to_import' => $total_to_import,
            'has_more' => $has_more,
            'next_page' => $page + 1
        ];
    }

    /**
     * Prepara a fila de produtos de forma incremental (batch por subcategoria)
     */
    public function prepare_product_import_queue_batch($current_sub_index = 0) {
        $logs = [];
        $cancel_flag_key = 'artimage_cancel_product_import_flag';
        $subs_list_key = 'artimage_product_subs_list';
        $queue_key = 'artimage_product_import_queue';
        $total_key = 'artimage_product_import_total';
        $processed_key = 'artimage_product_processed_count';

        // Limpa o flag de cancelamento apenas no início da preparação
        if ($current_sub_index == 0) {
            delete_transient($cancel_flag_key);
            delete_transient($processed_key);
        }
        if (get_transient($cancel_flag_key)) {
            delete_transient($subs_list_key);
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            $logs[] = 'Preparação da fila cancelada pelo usuário.';
            return [
                'status' => 'cancelled',
                'logs' => $logs,
                'has_more' => false,
            ];
        }

        $subs = get_transient($subs_list_key);
        if ($subs === false) {
            $all_terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'fields'     => 'all',
            ]);

            if (is_wp_error($all_terms) || empty($all_terms)) {
                set_transient($queue_key, [], 1 * HOUR_IN_SECONDS);
                set_transient($processed_key, 0, 1 * HOUR_IN_SECONDS);
                set_transient($total_key, 0, 1 * HOUR_IN_SECONDS);
                $logs[] = 'Nenhuma categoria/subcategoria encontrada para buscar produtos.';
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'product_queue_count' => 0,
                    'has_more' => false,
                ];
            }

            // Filtra para obter apenas subcategorias (termos com um pai)
            $subs = array_filter($all_terms, function($term) {
                return $term->parent != 0;
            });
            $subs = array_values($subs); // Re-indexa as chaves do array

            // Aplica um limite para testes, se habilitado
            $limit = apply_filters('art_image_debug_subcategory_limit', 0); // Padrão 0 (sem limite)
            if ($limit > 0) {
                $original_count = count($subs);
                $subs = array_slice($subs, 0, $limit);
                $logs[] = "MODO DE TESTE: A importação de produtos foi limitada a {$limit} subcategorias (de um total de {$original_count} encontradas).";
            }

            set_transient($subs_list_key, $subs, 12 * HOUR_IN_SECONDS);
            set_transient($queue_key, [], 12 * HOUR_IN_SECONDS);
        }

        if ($current_sub_index >= count($subs)) {
            $product_queue = get_transient($queue_key);
            $queue_count = is_array($product_queue) ? count($product_queue) : 0;
            set_transient($total_key, $queue_count, 12 * HOUR_IN_SECONDS);
            $logs[] = "Preparação da fila de produtos concluída. Total de produtos: $queue_count.";
            delete_transient($subs_list_key);
            return [
                'status' => 'completed',
                'logs' => $logs,
                'product_queue_count' => $queue_count,
                'has_more' => false,
            ];
        }

        $sub = $subs[$current_sub_index];
        $logs[] = "Coletando produtos da subcategoria: {$sub->name} ({$sub->slug})...";
        // Recupera o slug do pai e o filtro_sub_id
        $parent_term = get_term($sub->parent, 'product_cat');
        $parent_slug = $parent_term && !is_wp_error($parent_term) ? $parent_term->slug : '';
        $filtro_sub_id = get_term_meta($sub->term_id, 'filtro_sub_id', true);
        
        if (empty($parent_slug) || empty($filtro_sub_id)) {
            $logs[] = "Pulando subcategoria '{$sub->name}' por falta de dados (sem pai ou sem filtro_sub_id).";
             return [
                'status' => 'preparing',
                'logs' => $logs,
                'current_sub_index' => $current_sub_index + 1,
                'total_subs' => count($subs),
                'has_more' => true,
            ];
        }

        // Monta a URL correta
        $products_url = "https://artimage.com.br/produtos/{$parent_slug}?filtro-sub={$filtro_sub_id}";
        
        $products_data = [];
        if ($products_url) {
            $products_data = $this->client->get_products($products_url);
        }
        $product_queue = get_transient($queue_key);
        if (!is_array($product_queue)) $product_queue = [];
        if (!empty($products_data)) {
            foreach ($products_data as $p_data) {
                $product_queue[] = [
                    'product_data' => $p_data,
                    'subcategory_id' => $sub->term_id,
                    'subcategory_parent_id' => $sub->parent,
                ];
            }
            $logs[] = "Coletados " . count($products_data) . " produtos de {$sub->name}.";
        } else {
            $logs[] = "Nenhum produto encontrado para {$sub->name}.";
        }
        set_transient($queue_key, $product_queue, 12 * HOUR_IN_SECONDS);
        
        // Atualiza o total incrementalmente durante a preparação
        $current_queue_size = is_array($product_queue) ? count($product_queue) : 0;
        set_transient($total_key, $current_queue_size, 12 * HOUR_IN_SECONDS);
        
        $logs[] = "Progresso: " . ($current_sub_index + 1) . "/" . count($subs) . " subcategorias.";
        return [
            'status' => 'preparing',
            'logs' => $logs,
            'current_sub_index' => $current_sub_index + 1,
            'total_subs' => count($subs),
            'has_more' => true,
        ];
    }

    /**
     * Processa um lote de produtos da fila, com controle de tempo e lógica de negócio completa.
     *
     * @param int $page A página do lote (usado para controle inicial).
     * @param int $batch_size O tamanho do lote de produtos a serem processados por execução.
     * @return array O resultado do processamento.
     */
    public function import_products_batch(int $page = 1, int $batch_size = 5): array {
        global $artimage_sync_tracker;
        $start_time = time();
        $max_exec_time = (int) ini_get('max_execution_time');
        $time_limit = $max_exec_time > 0 ? min(25, floor($max_exec_time * 0.75)) : 25;
        
        $logs = [];
        $processed_in_this_run = 0;

        // Chaves de transients
        $master_lock_key = 'artimage_master_import_lock';
        $queue_key = 'artimage_product_import_queue';
        $total_key = 'artimage_product_import_total';
        $processed_key = 'artimage_product_processed_count';
        $cancel_flag_key = 'artimage_cancel_product_import_flag';

        $logs[] = "DEBUG: Iniciando import_products_batch - Page: {$page}, Batch Size: {$batch_size}";

        // Inicialização na primeira página
        if ($page === 1 && get_transient($processed_key) === false) {
            $logs[] = "DEBUG: Primeira página - inicializando processo";
            if (get_transient($master_lock_key)) {
                return ['status' => 'error', 'logs' => ['Outro processo mestre já está em execução.'], 'has_more' => false];
            }
            set_transient($master_lock_key, 'products', 1 * HOUR_IN_SECONDS);
            delete_transient($cancel_flag_key);
            set_transient($processed_key, 0, 1 * HOUR_IN_SECONDS);
            $logs[] = "Iniciando novo processo de importação de produtos.";
            
            if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                $artimage_sync_tracker->start_sync_session();
                $logs[] = "DEBUG: Sync tracker inicializado";
            }
        }

        // Validação da fila
        $logs[] = "DEBUG: Validando fila de produtos";
        $product_queue = get_transient($queue_key);
        if ($product_queue === false || !is_array($product_queue)) {
            $logs[] = "ERROR: Fila de produtos não encontrada ou inválida";
             $this->cleanup_import('products');
            return ['status' => 'error', 'logs' => $logs, 'has_more' => false];
        }

        $total_to_import = (int) get_transient($total_key);
        $current_total_processed = (int) get_transient($processed_key);
        
        $logs[] = "DEBUG: Fila tem " . count($product_queue) . " produtos. Total: {$total_to_import}, Processados: {$current_total_processed}";

        $items_to_process_in_this_run = array_slice($product_queue, 0, $batch_size, true);
            
        if (empty($items_to_process_in_this_run)) {
            $logs[] = "Importação de produtos concluída.";
            if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                 $artimage_sync_tracker->finish_sync_session(['cleanup_enabled' => true, 'dry_run' => false]);
            }
            $this->cleanup_import('products');
            return ['status' => 'completed', 'logs' => $logs, 'has_more' => false];
        }
        
        $logs[] = "Iniciando processamento. Faltam " . ($total_to_import - $current_total_processed) . " de {$total_to_import} produtos.";
        $logs[] = "DEBUG: Processando " . count($items_to_process_in_this_run) . " itens neste lote";

        foreach ($items_to_process_in_this_run as $queue_key_index => $item_to_process) {
            // Verifica o tempo de execução
            if ((time() - $start_time) >= $time_limit) {
                $logs[] = "Limite de tempo de execução atingido. O processo continuará.";
                set_transient($queue_key, $product_queue, 1 * HOUR_IN_SECONDS);
                set_transient($processed_key, $current_total_processed, 1 * HOUR_IN_SECONDS);
                return ['status' => 'processing', 'logs' => $logs, 'processed_in_batch' => $processed_in_this_run, 'current_total_processed' => $current_total_processed, 'total_to_import' => $total_to_import, 'has_more' => true];
            }

            if (get_transient($cancel_flag_key)) {
                $this->cleanup_import('products');
                return ['status' => 'cancelled', 'logs' => ['Importação cancelada.'], 'has_more' => false];
            }

            try {
                $logs[] = "DEBUG: Processando item do índice {$queue_key_index}";
                
                $p_data = $item_to_process['product_data'] ?? null;
                if (!$p_data || !isset($p_data['code']) || empty($p_data['code'])) {
                    $logs[] = "ERROR: SKU inválido ou não encontrado. Item: " . json_encode($item_to_process);
                    unset($product_queue[$queue_key_index]);
                    continue;
                }
                
                $sku = sanitize_text_field($p_data['code']);
                $logs[] = "Processando SKU: {$sku} (" . ($current_total_processed + 1) . "/{$total_to_import})";

                $logs[] = "DEBUG: Buscando detalhes completos do produto na API...";
                $details = [];
                if (!empty($p_data['link'])) {
                    try {
                        $details = $this->client->get_product_details($p_data['link']);
                        $logs[] = "DEBUG: Detalhes obtidos com sucesso.";
                    } catch (Exception $e) {
                        $logs[] = "WARNING: Falha ao obter detalhes da API para SKU {$sku}. Erro: " . $e->getMessage();
                    }
                } else {
                    $logs[] = "WARNING: Link do produto não encontrado para SKU {$sku}. Não é possível obter detalhes.";
                }

                $product_id = wc_get_product_id_by_sku($sku);
                $is_update = $product_id > 0;
                $product = $is_update ? wc_get_product($product_id) : new WC_Product_Simple();

                if (!$product) {
                     $logs[] = "ERROR: Não foi possível carregar ou criar o objeto produto para o SKU {$sku}.";
                     unset($product_queue[$queue_key_index]);
                     continue;
                }

                $logs[] = "DEBUG: " . ($is_update ? "Atualizando produto (ID: {$product_id})" : "Criando novo produto");

                $product->set_sku($sku);
                $product_title = !empty($details['title']) ? $details['title'] : ($p_data['title'] ?? 'Produto sem título');
                $product->set_name(sanitize_text_field($product_title));

                $price_string = $details['price'] ?? $p_data['price'] ?? '0';
                $price_numeric = floatval(str_replace(['R$', '.', ','], ['', '', '.'], $price_string));
                $product->set_regular_price($price_numeric);
                
                if (!empty($details['description'])) {
                    $product->set_description(wp_kses_post($details['description']));
                }

                $product->set_status('publish');

                $new_prod_id = $product->save();

                if ($new_prod_id && !is_wp_error($new_prod_id)) {
                    $logs[] = "DEBUG: Produto salvo com ID: {$new_prod_id}. Iniciando pós-processamento.";
                    
                    // 1. Categorias
                    if (isset($item_to_process['subcategory_id'])) {
                        $term_ids = [(int)$item_to_process['subcategory_id']];
                        if (isset($item_to_process['subcategory_parent_id']) && $item_to_process['subcategory_parent_id']) {
                            $term_ids[] = (int)$item_to_process['subcategory_parent_id'];
                        }
                        wp_set_object_terms($new_prod_id, $term_ids, 'product_cat');
                        $logs[] = "DEBUG: Categorias associadas: " . implode(', ', $term_ids);
                    }

                    // 2. Artista
                    if (!empty($details['artist'])) {
                        $artist_term = get_term_by('name', $details['artist'], 'artist');
                        if ($artist_term) {
                            wp_set_object_terms($new_prod_id, $artist_term->term_id, 'artist');
                            $logs[] = "DEBUG: Artista associado: {$details['artist']} (ID: {$artist_term->term_id})";
                        } else {
                            $logs[] = "WARNING: Artista '{$details['artist']}' não encontrado no banco de dados.";
                        }
                    }

                    // 3. Imagens
                    $gallery_ids = [];
                    // Imagem principal
                    if (!empty($details['image'])) {
                        $logs[] = "DEBUG: Processando imagem principal: {$details['image']}";
                        $thumb_id = $this->artimage_download_and_attach_image($details['image'], $new_prod_id);
                        if ($thumb_id) {
                            set_post_thumbnail($new_prod_id, $thumb_id);
                            $logs[] = "DEBUG: Imagem principal definida (ID: {$thumb_id}).";
                        } else {
                            $logs[] = "WARNING: Falha ao baixar ou anexar a imagem principal.";
                        }
                    }

                    // Galeria de imagens
                    if (!empty($details['gallery_images']) && is_array($details['gallery_images'])) {
                        $logs[] = "DEBUG: Processando " . count($details['gallery_images']) . " imagens da galeria.";
                        foreach($details['gallery_images'] as $img_url) {
                            $attach_id = $this->artimage_download_and_attach_image($img_url, $new_prod_id);
                            if ($attach_id) {
                                $gallery_ids[] = $attach_id;
                            }
                        }
                        if (!empty($gallery_ids)) {
                            $product->set_gallery_image_ids($gallery_ids);
                            $product->save(); // Salva novamente para atualizar a galeria
                            $logs[] = "DEBUG: Galeria de imagens atualizada com " . count($gallery_ids) . " imagens.";
                        }
                    }

                    // Meta dados e tracking
                    if (!empty($p_data['link'])) {
                        update_post_meta($new_prod_id, '_external_link', esc_url_raw($p_data['link']));
                    }
                    if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                        $artimage_sync_tracker->mark_product_imported($new_prod_id, $sku);
                    }
                    
                    $logs[] = "Produto " . ($is_update ? "atualizado" : "criado") . ": {$product_title} (ID: {$new_prod_id})";
                } else {
                    $error_message = is_wp_error($new_prod_id) ? $new_prod_id->get_error_message() : 'Erro desconhecido ao salvar';
                    $logs[] = "ERROR: Falha ao salvar produto SKU {$sku}: {$error_message}";
                }

            } catch (Exception $e) {
                $logs[] = "ERRO CRÍTICO ao processar SKU {$sku}: " . $e->getMessage();
            } catch (Error $e) {
                $logs[] = "ERRO FATAL ao processar SKU {$sku}: " . $e->getMessage();
            }
            
            // Remove o produto processado da fila e atualiza contadores
            unset($product_queue[$queue_key_index]);
            $current_total_processed++; 
            $processed_in_this_run++;
        }

        $logs[] = "DEBUG: Salvando estado da fila";
        set_transient($queue_key, $product_queue, 1 * HOUR_IN_SECONDS);
        set_transient($processed_key, $current_total_processed, 1 * HOUR_IN_SECONDS);

        $has_more = !empty($product_queue);

        if (!$has_more) {
            $logs[] = "Importação de produtos finalizada. Total: {$current_total_processed}.";
            if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                 $artimage_sync_tracker->finish_sync_session(['cleanup_enabled' => true, 'dry_run' => false]);
            }
            $this->cleanup_import('products');
        } else {
            $logs[] = "Lote finalizado. Produtos restantes na fila: " . count($product_queue);
        }

        $logs[] = "DEBUG: Retornando resultado do lote";

        return [
            'status' => $has_more ? 'processing' : 'completed',
            'logs' => $logs,
            'processed_in_batch' => $processed_in_this_run,
            'current_total_processed' => $current_total_processed,
            'total_to_import' => $total_to_import,
            'has_more' => $has_more,
        ];
    }

    /**
     * Importa artistas para CPT 'artists'
     */
    public function import_artists(int $page = 1, int $batch_size = 20): array
    {
        global $artimage_sync_tracker;
        
        $logs = [];
        $processed_in_batch = 0;
        $master_lock_key = 'artimage_master_import_lock';
        $batch_lock_key = 'artimage_artist_batch_lock';
        $queue_key = 'artimage_artist_import_queue';
        $total_key = 'artimage_artist_import_total';
        $processed_key = 'artimage_artist_processed_count';
        $cancel_flag_key = 'artimage_cancel_artist_import_flag';

        if ($page === 1) {
            if (get_transient($master_lock_key)) {
                return [
                    'status' => 'error',
                    'logs' => ['Outro processo de importação (master) já está em execução – abortando artistas.'],
                    'has_more' => false,
                ];
            }
            set_transient($master_lock_key, 'artists', 15 * MINUTE_IN_SECONDS);
            set_transient($batch_lock_key, 1, 15 * MINUTE_IN_SECONDS);
            delete_transient($cancel_flag_key);

            $all_artists_data = $this->client->get_artists('https://artimage.com.br/artistas');
            if (empty($all_artists_data)) {
                delete_transient($master_lock_key);
                delete_transient($batch_lock_key);
                $logs[] = "Nenhum artista encontrado para importar.";
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => 0,
                    'total_to_import' => 0,
                    'has_more' => false,
                ];
            }

            set_transient($queue_key, $all_artists_data, 1 * HOUR_IN_SECONDS);
            set_transient($total_key, count($all_artists_data), 1 * HOUR_IN_SECONDS);
            set_transient($processed_key, 0, 1 * HOUR_IN_SECONDS);
            $logs[] = "Fila de importação de artistas preparada com " . count($all_artists_data) . " itens.";
        } else {
            if (!get_transient($batch_lock_key)) {
                $current_master_holder = get_transient($master_lock_key);
                if ($current_master_holder === 'artists') {
                     delete_transient($master_lock_key);
                }
                return [
                    'status' => 'error',
                    'logs' => ['Erro: Processo de importação de artistas em lote não iniciado ou bloqueio de lote expirou.'],
                    'has_more' => false,
                ];
            }
        }

        if (get_transient($cancel_flag_key)) {
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'artists') {
                delete_transient($master_lock_key);
            }
            delete_transient($cancel_flag_key);
            $logs[] = "Importação de artistas cancelada pelo usuário.";
            return ['status' => 'cancelled', 'logs' => $logs, 'has_more' => false];
        }

        $artist_queue = get_transient($queue_key);
        $total_to_import = (int) get_transient($total_key);
        $current_total_processed = (int) get_transient($processed_key);

        if ($artist_queue === false || ($total_to_import === 0 && $page > 1)) {
            if (get_transient($master_lock_key) === 'artists') {
                delete_transient($master_lock_key);
            }
            delete_transient($batch_lock_key);
            return [
                'status' => 'error',
                'logs' => ['Erro: Fila de importação de artistas não encontrada ou vazia inesperadamente.'],
                'has_more' => false,
            ];
        }
        
        $offset = ($page - 1) * $batch_size;
        $batch_items = array_slice((array)$artist_queue, $offset, $batch_size);

        if (empty($batch_items)) {
            if ($current_total_processed >= $total_to_import) {
                $logs[] = "Importação de artistas concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
                delete_transient($queue_key);
                delete_transient($total_key);
                delete_transient($processed_key);
                delete_transient($batch_lock_key);
                if (get_transient($master_lock_key) === 'artists') {
                    delete_transient($master_lock_key);
                }
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            } else {
                $logs[] = "Lote de artistas vazio, mas a importação não foi concluída. Verificando...";
                if (get_transient($master_lock_key) === 'artists') {
                    delete_transient($master_lock_key);
                }
                delete_transient($batch_lock_key);
                return [
                    'status' => 'error',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
        }
        
        $logs[] = "Processando lote {$page} de artistas. Itens no lote: " . count($batch_items) . ". Processados anteriormente: {$current_total_processed} de {$total_to_import}.";

        foreach ($batch_items as $a) {
            if (get_transient($cancel_flag_key)) {
                $this->cleanup_import('artists');
                $logs[] = 'Importação de artistas cancelada pelo usuário.';
                return [
                    'status' => 'cancelled',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
            $slug_to_check = sanitize_title($a['title']);
            $term_exists = term_exists($slug_to_check, 'artist'); 

            if ($term_exists) {
                $term_id = is_array($term_exists) ? $term_exists['term_id'] : $term_exists;
                $updated_term = wp_update_term($term_id, 'artist', [
                    'name'        => $a['title'],
                    'description' => $a['description'],
                    'slug'        => $slug_to_check,
                ]);

                if (is_wp_error($updated_term)) {
                    $logs[] = "Erro ao atualizar artista (termo) {$a['title']} (ID {$term_id}): " . $updated_term->get_error_message();
                } else {
                    update_term_meta($term_id, '_external_slug', $a['slug']); 
                    update_term_meta($term_id, '_image_url', $a['image']);
                    // Marca artista como importado
                    if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                        $artimage_sync_tracker->mark_artist_imported($term_id, $a['slug']);
                    }
                    $logs[] = "Artista (termo) atualizado: {$a['title']} (ID {$term_id}).";
                }
            } else {
                $term_data = wp_insert_term(
                    $a['title'],
                    'artist',
                    [
                        'description' => $a['description'],
                        'slug'        => $slug_to_check,
                    ]
                );

                if (is_wp_error($term_data)) {
                    $logs[] = "Erro ao criar artista (termo) {$a['title']}: " . $term_data->get_error_message();
                } else {
                    $term_id = $term_data['term_id'];
                    update_term_meta($term_id, '_external_slug', $a['slug']); 
                    update_term_meta($term_id, '_image_url', $a['image']);
                    // Marca artista como importado
                    if (isset($GLOBALS['artimage_sync_tracker']) && $artimage_sync_tracker) {
                        $artimage_sync_tracker->mark_artist_imported($term_id, $a['slug']);
                    }
                    $logs[] = "Artista (termo) criado: {$a['title']} (ID {$term_id})";
                }
            }
            $current_total_processed++;
            $processed_in_batch++;
        }

        set_transient($processed_key, $current_total_processed, 1 * HOUR_IN_SECONDS);
        $has_more = $current_total_processed < $total_to_import;

        if (!$has_more) {
            $logs[] = "Importação de artistas concluída. Total processado: {$current_total_processed} de {$total_to_import}.";
            delete_transient($queue_key);
            delete_transient($total_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'artists') {
                delete_transient($master_lock_key);
            }
        } else {
            $logs[] = "Lote {$page} de artistas concluído. {$current_total_processed}/{$total_to_import} artistas processados no total.";
        }

        return [
            'status' => $has_more ? 'processing' : 'completed',
            'logs' => $logs,
            'processed_in_batch' => $processed_in_batch,
            'current_total_processed' => $current_total_processed,
            'total_to_import' => $total_to_import,
            'has_more' => $has_more,
            'next_page' => $page + 1
        ];
    }

    // --- AJAX wrappers ---

    public function ajax_import_categories()
    {
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $result = $this->import_categories($page);
        wp_send_json_success($result);
    }

    public function ajax_import_subcategories()
    {
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $result = $this->import_subcategories($page);
        wp_send_json_success($result);
    }

    public function ajax_import_products()
    {
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $batch_size = apply_filters('art_image_product_import_batch_size', 5); 
        $result = $this->import_products_batch($page, $batch_size);
        wp_send_json_success($result);
    }

    public function ajax_import_artists()
    {
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $result = $this->import_artists($page);
        wp_send_json_success($result);
    }

    // --- AJAX Cancellation Handlers ---

    public function ajax_cancel_categories_import() {
        $this->cleanup_import('categories');
        wp_send_json_success(['message' => 'Importação de categorias cancelada com sucesso.']);
    }

    public function ajax_cancel_subcategories_import() {
        $this->cleanup_import('subcategories');
        wp_send_json_success(['message' => 'Importação de subcategorias cancelada com sucesso.']);
    }

    public function ajax_cancel_artists_import() {
        $this->cleanup_import('artists');
        wp_send_json_success(['message' => 'Importação de artistas cancelada com sucesso.']);
    }

    public function ajax_cancel_products_import() {
        $this->cleanup_import('products');
        wp_send_json_success(['message' => 'Importação de produtos cancelada com sucesso.']);
    }

    /**
     * Checks the master lock transient to see if any import is active.
     * @return string|false The type of import active, or false if none.
     */
    public function get_active_import_status() {
        $master_lock_key = 'artimage_master_import_lock';
        $active_import_type = get_transient($master_lock_key);
        
        if ($active_import_type) {
            return $active_import_type;
        }
        return false;
    }

    /**
     * Baixa uma imagem externa e anexa ao produto, retornando o ID do attachment
     */
    public function artimage_download_and_attach_image($image_url, $post_id) {
        if (empty($image_url)) return 0;
        // Verifica se já existe attachment com essa URL para evitar duplicidade
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_artimage_original_url' AND meta_value = %s LIMIT 1",
            $image_url
        ));
        if ($existing) return (int)$existing;

        // Baixa a imagem para o diretório de uploads
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) return 0;
        $file_array = [];
        $file_array['name'] = basename(parse_url($image_url, PHP_URL_PATH));
        $file_array['tmp_name'] = $tmp;

        // Usa a função do WP para inserir como attachment
        $attach_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attach_id)) {
            @unlink($file_array['tmp_name']);
            return 0;
        }
        // Salva a URL original para evitar duplicidade futura
        update_post_meta($attach_id, '_artimage_original_url', $image_url);
        return $attach_id;
    }
}
