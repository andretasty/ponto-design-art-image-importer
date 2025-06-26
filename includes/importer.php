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
                    if ($artimage_sync_tracker) {
                        $artimage_sync_tracker->mark_category_imported($term_id, $cat_data['slug']);
                    }
                    $logs[] = "Categoria criada: {$cat_data['nome']}";
                }
            } else {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                // Marca categoria existente como importada
                if ($artimage_sync_tracker) {
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
                    if ($artimage_sync_tracker) {
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
                if ($artimage_sync_tracker) {
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
            $subs = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'fields'     => 'all',
            ]);
            if (is_wp_error($subs) || empty($subs)) {
                set_transient($queue_key, [], 1 * HOUR_IN_SECONDS);
                set_transient($processed_key, 0, 1 * HOUR_IN_SECONDS);
                set_transient($total_key, 0, 1 * HOUR_IN_SECONDS);
                $logs[] = 'Nenhuma subcategoria encontrada para buscar produtos.';
                return [
                    'status' => 'completed',
                    'logs' => $logs,
                    'product_queue_count' => 0,
                    'has_more' => false,
                ];
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
        // Monta a URL correta
        $products_url = '';
        if ($parent_slug && $filtro_sub_id) {
            $products_url = "https://artimage.com.br/produtos/{$parent_slug}?filtro-sub={$filtro_sub_id}";
        }
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

    public function import_products_batch(int $page = 1, int $batch_size = 5): array
    {
        // Aplica otimizações antes de começar
        do_action('art_image_before_import');
        
        global $artimage_sync_tracker;
        
        $logs = [];
        $processed_in_batch = 0;
        $master_lock_key = 'artimage_master_import_lock';
        $batch_lock_key = 'artimage_product_batch_lock';
        $cancel_flag_key = 'artimage_cancel_product_import_flag';
        $queue_key = 'artimage_product_import_queue';
        $processed_key = 'artimage_product_processed_count';
        $total_key = 'artimage_product_import_total';
        $subs_list_key = 'artimage_product_subs_list';

        if ($page === 1) {
            if (get_transient($master_lock_key)) {
                return ['status' => 'error', 'logs' => ['Outro processo de importação (master) já está em execução – abortando produtos.'], 'has_more' => false];
            }
            set_transient($master_lock_key, 'products', 12 * HOUR_IN_SECONDS);
            set_transient($batch_lock_key, 1, 12 * HOUR_IN_SECONDS);
            delete_transient($cancel_flag_key);
            
            // Inicia nova sessão de sincronização
            if ($artimage_sync_tracker) {
                $artimage_sync_tracker->start_sync_session();
                $logs[] = "Nova sessão de sincronização iniciada.";
            }

            // Só prepara a fila se ela não existir ou estiver vazia
            $product_queue = get_transient($queue_key);
            if ($product_queue === false || empty($product_queue)) {
                $logs[] = "Iniciando preparação da fila de produtos. Isso pode levar algum tempo...";
                $preparation_result = $this->prepare_product_import_queue_batch();
                $logs = array_merge($logs, $preparation_result['logs']);
                set_transient($total_key, $preparation_result['product_queue_count'], 12 * HOUR_IN_SECONDS);
                $logs[] = "Preparação da fila de produtos concluída. Total de produtos na fila para esta sessão: " . $preparation_result['product_queue_count'];

                if ($preparation_result['product_queue_count'] === 0) {
                    delete_transient($batch_lock_key);
                    if (get_transient($master_lock_key) === 'products') {
                        delete_transient($master_lock_key);
                    }
                    // $total_key is already deleted or set to 0
                    $logs[] = "Nenhum produto encontrado na fila inicial para importar.";
                    return ['status' => 'completed', 'logs' => $logs, 'current_total_processed' => 0, 'total_to_import' => 0, 'has_more' => false];
                }
                // Atualiza a variável local para o processamento do lote
                $product_queue = get_transient($queue_key);
            }
        } else {
            if (!get_transient($batch_lock_key)) {
                $current_master_holder = get_transient($master_lock_key);
                if ($current_master_holder === 'products') {
                     delete_transient($master_lock_key);
                }
                return ['status' => 'error', 'logs' => ['Erro: Processo de importação de produtos em lote não iniciado ou bloqueio de lote expirou.'], 'has_more' => false];
            }
        }
        
        if (get_transient($cancel_flag_key)) {
            delete_transient($queue_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'products') {
                delete_transient($master_lock_key);
            }
            delete_transient($cancel_flag_key);
            $logs[] = "Importação de produtos cancelada pelo usuário.";
            return ['status' => 'cancelled', 'logs' => $logs, 'has_more' => false];
        }

        $product_queue = get_transient($queue_key);
        $current_total_processed = (int) get_transient($processed_key);
        $total_to_import = (int) get_transient($total_key); // Retrieve total

        if ($product_queue === false && $page > 1) {
             if (get_transient($master_lock_key) === 'products') {
                delete_transient($master_lock_key);
             }
             delete_transient($batch_lock_key);
             return ['status' => 'error', 'logs' => ['Erro: Fila de importação de produtos não encontrada para processamento em lote (página > 1).'], 'total_to_import' => $total_to_import, 'has_more' => false];
        }
        
        $offset = ($page - 1) * $batch_size;
        $batch_items = array_slice((array)$product_queue, $offset, $batch_size);

        if (empty($batch_items)) {
            $logs[] = "Importação de produtos concluída. Total processado nesta sessão: {$current_total_processed} de {$total_to_import}.";
            
            // Limpar transients da importação de produtos
            delete_transient($queue_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'products') {
                delete_transient($master_lock_key);
            }
            
            return [
                'status' => 'completed',
                'logs' => $logs,
                'current_total_processed' => $current_total_processed,
                'total_to_import' => $total_to_import,
                'has_more' => false,
            ];
        }
        
        $logs[] = "Processando lote {$page} de produtos. Itens no lote: " . count($batch_items) . ". Processados nesta sessão: {$current_total_processed} de {$total_to_import} (aprox.).";

        foreach ($batch_items as $item_to_process) {
            if (get_transient($cancel_flag_key)) {
                $this->cleanup_import('products');
                $logs[] = 'Importação de produtos cancelada pelo usuário.';
                return [
                    'status' => 'cancelled',
                    'logs' => $logs,
                    'current_total_processed' => $current_total_processed,
                    'total_to_import' => $total_to_import,
                    'has_more' => false,
                ];
            }
            $p = $item_to_process['product_data'];
            $sub_term_id = $item_to_process['subcategory_id'];
            $sub_parent_id = $item_to_process['subcategory_parent_id'];

            $exists = wc_get_product_id_by_sku($p['code']);
            $product_details = [];
            
            if ($exists) {
                $product = wc_get_product($exists);
                $logs[] = "Atualizando produto existente (SKU {$p['code']}): {$p['title']} - usando dados da listagem";
                // Para produtos existentes, usar apenas dados da listagem (otimização)
            } else {
                $product = new WC_Product_Simple();
                $logs[] = "Criando novo produto (SKU {$p['code']}): {$p['title']} - buscando detalhes completos";
                // Só buscar detalhes completos para produtos novos
                if (!empty($p['link'])) {
                    $product_details = $this->client->get_product_details($p['link']);
                }
            }
            $product_title = isset($product_details['title']) && $product_details['title'] ? $product_details['title'] : $p['title'];
            $product_code = isset($product_details['code']) && $product_details['code'] ? $product_details['code'] : $p['code'];
            $product_price = isset($product_details['price']) && $product_details['price'] ? $product_details['price'] : $p['price'];

            $product->set_name(sanitize_text_field($product_title));
            $product->set_sku(sanitize_text_field($product_code));
            $price_numeric = floatval(str_replace(['R$', '.', ','], ['', '', '.'], $product_price));
            $product->set_regular_price($price_numeric);
            $product->set_status('publish');
            
            // Adiciona a descrição diretamente ao produto
            if (isset($product_details['description']) && $product_details['description']) {
                $product->set_description(wp_kses_post($product_details['description']));
            }
            
            // Processa o tamanho e adiciona as dimensões
            $size = isset($product_details['size']) && $product_details['size'] ? $product_details['size'] : $p['size'];
            if ($size) {
                // Extrai as dimensões do tamanho (formato: 20CM x50CM x20CM -> largura x altura x comprimento)
                if (preg_match('/(\d+)CM\s*x\s*(\d+)CM\s*x\s*(\d+)CM/i', $size, $matches)) {
                    $width = floatval($matches[1]);  // Primeiro número é largura
                    $height = floatval($matches[2]); // Segundo número é altura
                    $length = floatval($matches[3]); // Terceiro número é comprimento
                    
                    // Define as dimensões em centímetros
                    $product->set_width($width);
                    $product->set_height($height);
                    $product->set_length($length);
                    
                    // Estima o peso baseado no volume (em kg)
                    // Fórmula: volume em m³ * densidade estimada (assumindo 500kg/m³ para arte)
                    $volume = ($width * $height * $length) / 1000000; // converte para m³
                    $estimated_weight = $volume * 500; // 500kg/m³
                    $product->set_weight($estimated_weight);
                }
            }
            
            $prod_id = $product->save();

            if ($prod_id && !is_wp_error($prod_id)) {
                // Atualiza as categorias
                wp_set_object_terms($prod_id, [(int)$sub_term_id], 'product_cat', false);
                if ($sub_parent_id) {
                    wp_set_object_terms($prod_id, [(int)$sub_parent_id], 'product_cat', true);
                }

                // Atualiza os metadados
                update_post_meta($prod_id, '_external_link', esc_url_raw($p['link']));
                update_post_meta($prod_id, '_size', sanitize_text_field($size));
                update_post_meta($prod_id, '_technique', sanitize_text_field(isset($product_details['technique']) ? $product_details['technique'] : ''));
                update_post_meta($prod_id, '_frame', sanitize_text_field(isset($product_details['frame']) ? $product_details['frame'] : ''));

                // Salva artista
                $artist_name_to_find = sanitize_text_field(isset($product_details['artist']) && $product_details['artist'] ? $product_details['artist'] : (isset($p['artist_name']) ? $p['artist_name'] : ''));
                if ($artist_name_to_find) {
                    $artist_term = term_exists($artist_name_to_find, 'artist');
                    if ($artist_term) {
                        $artist_term_id = is_array($artist_term) ? $artist_term['term_id'] : $artist_term;
                        wp_set_object_terms($prod_id, (int)$artist_term_id, 'artist', true);
                        $logs[] = "Produto ID {$prod_id} ('{$product_title}') associado ao artista: {$artist_name_to_find} (ID {$artist_term_id}).";
                    } else {
                        $logs[] = "Artista '{$artist_name_to_find}' não encontrado para o produto ID {$prod_id} ('{$product_title}').";
                    }
                }

                // Atualiza imagens apenas se não existirem
                if (!has_post_thumbnail($prod_id) && !empty($product_details['images']) && is_array($product_details['images'])) {
                    $image_ids = [];
                    foreach ($product_details['images'] as $img_url) {
                        $attach_id = $this->artimage_download_and_attach_image($img_url, $prod_id);
                        if ($attach_id) {
                            $image_ids[] = $attach_id;
                        }
                    }
                    if (!empty($image_ids)) {
                        set_post_thumbnail($prod_id, $image_ids[0]);
                        if (count($image_ids) > 1) {
                            update_post_meta($prod_id, '_product_image_gallery', implode(',', array_slice($image_ids, 1)));
                        }
                    }
                }

                // Marca produto como importado nesta sessão
                if ($artimage_sync_tracker) {
                    $artimage_sync_tracker->mark_product_imported($prod_id, $product_code);
                }
                
                $logs[] = "Produto " . ($exists ? "atualizado" : "criado") . ": {$product_title} (ID {$prod_id})";
            } else {
                $error_message = is_wp_error($prod_id) ? $prod_id->get_error_message() : 'Erro desconhecido ao salvar produto.';
                $logs[] = 'Erro ao ' . ($exists ? "atualizar" : "criar") . ' produto "' . $product_title . '": ' . $error_message;
            }
            $current_total_processed++; 
            $processed_in_batch++;
        }
        
        set_transient($processed_key, $current_total_processed, 12 * HOUR_IN_SECONDS);
        
        $next_batch_start_index = $page * $batch_size;
        $has_more_in_current_queue = count((array)$product_queue) > $next_batch_start_index;

        $current_status = 'processing';
        if (!$has_more_in_current_queue) {
            // Sempre marcar como concluído quando não há mais itens na fila atual
            $logs[] = "Importação de produtos concluída. Total processado nesta sessão: {$current_total_processed} de {$total_to_import}.";
            $current_status = 'completed';
            
            // Finaliza sessão de sincronização e limpa itens não importados
            if ($artimage_sync_tracker) {
                $cleanup_enabled = get_option('artimage_enable_cleanup', true);
                $dry_run = get_option('artimage_cleanup_dry_run', false);
                
                $sync_results = $artimage_sync_tracker->finish_sync_session([
                    'cleanup_enabled' => $cleanup_enabled,
                    'dry_run' => $dry_run
                ]);
                
                $logs[] = "Sincronização finalizada. Produtos removidos: {$sync_results['products_deleted']}, Categorias: {$sync_results['categories_deleted']}, Artistas: {$sync_results['artists_deleted']}";
            }
            
            // Limpar transients da importação de produtos
            delete_transient($queue_key);
            delete_transient($processed_key);
            delete_transient($batch_lock_key);
            if (get_transient($master_lock_key) === 'products') {
                delete_transient($master_lock_key);
            }
        } else {
            $logs[] = "Lote {$page} de produtos concluído. Processados nesta sessão: {$current_total_processed} de {$total_to_import}.";
        }

        return [
            'status' => $current_status,
            'logs' => $logs,
            'processed_in_batch' => $processed_in_batch,
            'current_total_processed' => $current_total_processed,
            'total_to_import' => $total_to_import, // Added total_to_import
            'has_more' => $has_more_in_current_queue,
            'next_page' => $page + 1
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
                    if ($artimage_sync_tracker) {
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
                    if ($artimage_sync_tracker) {
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
