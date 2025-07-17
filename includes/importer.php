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
     * Prepara a fila de produtos para importação em lotes, buscando de todas as subcategorias.
     * Versão Final.
     */
    public function prepare_product_import_queue_batch($current_sub_index = 0) {
        $logs = [];
        $subs_list_key = 'artimage_product_subs_list';
        $queue_key = 'artimage_product_import_queue';

        // Passo 1: Obter a lista de todas as subcategorias para processar
        if ($current_sub_index === 0) {
            $this->cleanup_import('products');
            $all_terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
            $subs = array_filter($all_terms, fn($term) => $term->parent != 0);
            
            $limit = apply_filters('art_image_debug_subcategory_limit', 0);
            if ($limit > 0 && count($subs) > $limit) {
                $logs[] = "MODO DE TESTE: A importação foi limitada a {$limit} subcategorias.";
                $subs = array_slice($subs, 0, $limit);
            }
            
            set_transient($subs_list_key, array_values($subs), 12 * HOUR_IN_SECONDS);
            set_transient($queue_key, [], 12 * HOUR_IN_SECONDS);
            $logs[] = "Lista de " . count($subs) . " subcategorias preparada para verificação de produtos.";
        }

        $subs = get_transient($subs_list_key);
        if (empty($subs) || !isset($subs[$current_sub_index])) {
            $total_queued = count(get_transient($queue_key) ?: []);
            set_transient('artimage_product_import_total', $total_queued, 12 * HOUR_IN_SECONDS);
            $logs[] = "Preparação da fila de produtos concluída. Total de produtos: {$total_queued}.";
            delete_transient($subs_list_key);
            return ['status' => 'completed', 'logs' => $logs, 'product_queue_count' => $total_queued, 'has_more' => false];
        }

        $sub = $subs[$current_sub_index];
        $parent_term = get_term($sub->parent, 'product_cat');
        $parent_slug = $parent_term && !is_wp_error($parent_term) ? $parent_term->slug : '';
        $filtro_sub_id = get_term_meta($sub->term_id, 'filtro_sub_id', true);

        if ($parent_slug && $filtro_sub_id) {
            $products_url = "https://artimage.com.br/produtos/{$parent_slug}?filtro-sub={$filtro_sub_id}";
            $logs[] = "Coletando produtos de: {$sub->name} (URL: {$products_url})";
            
            // Usar get_products que lida com paginação
            $products_from_api = $this->client->get_products($products_url);
            $product_queue = get_transient($queue_key) ?: [];

            if (!empty($products_from_api)) {
                foreach ($products_from_api as $p_data) {
                    $product_queue[] = [
                        'product_data' => $p_data,
                        'subcategory_id' => $sub->term_id,
                        'subcategory_parent_id' => $sub->parent,
                    ];
                }
                $logs[] = "Coletados " . count($products_from_api) . " produtos de {$sub->name}.";
            } else {
                $logs[] = "Nenhum produto encontrado em {$sub->name}.";
            }
            set_transient($queue_key, $product_queue, 12 * HOUR_IN_SECONDS);
        } else {
            $logs[] = "AVISO: Pulando subcategoria '{$sub->name}' por falta de dados (Pai: {$parent_slug}, Filtro ID: {$filtro_sub_id}).";
        }
        
        $next_sub_index = $current_sub_index + 1;
        $total_subs = count($subs);
        $logs[] = "Progresso da preparação: {$next_sub_index}/{$total_subs} subcategorias.";

        return [
            'status' => 'preparing',
            'logs' => $logs,
            'current_sub_index' => $next_sub_index,
            'total_subs' => $total_subs,
            'has_more' => true,
        ];
    }

    /**
     * Processa um lote de produtos da fila, com controle de tempo e lógica de negócio completa.
     * Esta é a versão final e corrigida.
     *
     * @param int $page Página do lote (controle interno).
     * @param int $batch_size O tamanho do lote de produtos a serem processados por execução.
     * @return array O resultado do processamento.
     */
    public function import_products_batch(int $page = 1, int $batch_size = 5): array {
        art_image_log_product_processing('IMPORTER', 'BATCH_START', ['page' => $page, 'batch_size' => $batch_size]);

        global $artimage_sync_tracker;
        $start_time = time();
        $max_exec_time = (int) ini_get('max_execution_time');
        $time_limit = $max_exec_time > 0 ? min(45, floor($max_exec_time * 0.85)) : 45;
        $logs = [];
        $processed_in_this_run = 0;

        $queue_key = 'artimage_product_import_queue';
        $total_key = 'artimage_product_import_total';
        $processed_key = 'artimage_product_processed_count';

        $product_queue = get_transient($queue_key);
        
        $queue_status = 'OK';
        if ($product_queue === false) $queue_status = 'NOT_FOUND';
        if (!is_array($product_queue)) $queue_status = 'NOT_AN_ARRAY';
        if (empty($product_queue)) $queue_status = 'EMPTY';
        
        art_image_log_product_processing('IMPORTER', 'QUEUE_STATUS', ['status' => $queue_status, 'count' => is_array($product_queue) ? count($product_queue) : 0]);

        if ($product_queue === false || !is_array($product_queue)) {
            $this->cleanup_import('products');
            return ['status' => 'error', 'logs' => ['Erro: Fila de produtos não encontrada ou inválida.'], 'has_more' => false];
        }

        $total_to_import = (int) get_transient($total_key);
        $current_total_processed = (int) get_transient($processed_key);
        
        if (empty($product_queue)) {
            $logs[] = "Importação de produtos concluída.";
            if (isset($GLOBALS['artimage_sync_tracker'])) {
                 $GLOBALS['artimage_sync_tracker']->finish_sync_session(['cleanup_enabled' => true, 'dry_run' => false]);
            }
            $this->cleanup_import('products');
            return ['status' => 'completed', 'logs' => $logs, 'has_more' => false];
        }
        
        $items_to_process_in_this_run = array_slice($product_queue, 0, $batch_size, true);

        foreach ($items_to_process_in_this_run as $queue_key_index => $item_to_process) {
            if ((time() - $start_time) >= $time_limit) {
                $logs[] = "Limite de tempo de execução atingido. Salvando progresso para continuar.";
                set_transient($queue_key, array_values($product_queue), 12 * HOUR_IN_SECONDS);
                set_transient($processed_key, $current_total_processed, 12 * HOUR_IN_SECONDS);
                return ['status' => 'processing', 'logs' => $logs, 'processed_in_batch' => $processed_in_this_run, 'current_total_processed' => $current_total_processed, 'total_to_import' => $total_to_import, 'has_more' => true];
            }

            try {
                $p_data = $item_to_process['product_data'] ?? null;
                if (!$p_data || empty($p_data['code'])) {
                    $logs[] = "AVISO: Item na fila sem SKU. Pulando. Dados: " . json_encode($item_to_process);
                    unset($product_queue[$queue_key_index]);
                    continue;
                }
                
                $sku = sanitize_text_field($p_data['code']);
                $logs[] = "Processando produto SKU: {$sku}";
                art_image_log_product_processing($sku, 'INICIANDO_PROCESSAMENTO');
                
                // Buscar detalhes do produto da API
                $details = !empty($p_data['link']) ? $this->client->get_product_details($p_data['link']) : [];
                $details_count = empty($details) ? 0 : count($details);
                $logs[] = "Detalhes obtidos da API: " . ($details_count === 0 ? "Nenhum" : "{$details_count} campos");
                art_image_log_product_processing($sku, 'DETALHES_API_OBTIDOS', ['campos' => $details_count, 'link' => $p_data['link'] ?? 'N/A']);
                
                $product_id = wc_get_product_id_by_sku($sku);
                art_image_log_product_processing($sku, 'VERIFICANDO_EXISTENCIA', ['product_id' => $product_id ?: 'Nenhum']);

                $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
                art_image_log_product_processing($sku, 'OBJETO_PRODUTO', ['tipo' => $product_id ? 'Existente' : 'Novo', 'classe' => is_object($product) ? get_class($product) : 'N/A']);
                
                if (!$product) {
                    $logs[] = "ERRO: Não foi possível criar/obter produto para SKU: {$sku}";
                    art_image_log_product_processing($sku, 'ERRO_CRIAR_OBJETO_PRODUTO');
                    unset($product_queue[$queue_key_index]);
                    continue;
                }

                // Configurar dados básicos do produto
                $product->set_sku($sku);
                $product->set_name(sanitize_text_field($details['title'] ?? $p_data['title'] ?? 'Produto sem título'));
                $product->set_regular_price(floatval(str_replace(['R$', '.', ','], ['', '', '.'], $details['price'] ?? $p_data['price'] ?? '0')));
                $product->set_description(wp_kses_post($details['description'] ?? ''));
                $product->set_status('publish');

                // Define as categorias ANTES de salvar para evitar "Sem Categoria"
                $category_ids = array_filter([
                    (int)$item_to_process['subcategory_id'],
                    (int)$item_to_process['subcategory_parent_id']
                ]);
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
                
                // Define as dimensões para o frete
                if (!empty($details['size'])) {
                    $parsed_dims = art_image_parse_dimensions($details['size']);
                    if ($parsed_dims['width']) {
                        $product->set_width($parsed_dims['width']);
                    }
                    if ($parsed_dims['height']) {
                        $product->set_height($parsed_dims['height']);
                    }
                    if ($parsed_dims['length']) {
                        $product->set_length($parsed_dims['length']);
                    }
                }

                art_image_log_product_processing($sku, 'SALVANDO_PRODUTO', ['nome' => $product->get_name(), 'preco' => $product->get_regular_price()]);
                $new_prod_id = $product->save();
                art_image_log_product_processing($sku, 'PRODUTO_SALVO', ['new_product_id' => $new_prod_id]);

                if ($new_prod_id) {
                    $logs[] = ($product_id ? "Produto atualizado" : "Produto criado") . ": {$product->get_name()} (ID: {$new_prod_id})";
                    art_image_log_product_processing($sku, 'DEFININDO_TERMOS');
                    
                    // Definir artista se disponível
                    if (!empty($details['artist']) && ($term = get_term_by('name', $details['artist'], 'artist'))) {
                        wp_set_object_terms($new_prod_id, $term->term_id, 'artist');
                        $logs[] = "Artista definido: {$details['artist']}";
                    }
                    
                    // Salvar campos personalizados
                    art_image_log_product_processing($sku, 'SALVANDO_CAMPOS_PERSONALIZADOS');
                    $custom_fields_saved = 0;
                    if (!empty($details['technique'])) {
                        update_post_meta($new_prod_id, '_technique', sanitize_text_field($details['technique']));
                        $custom_fields_saved++;
                    }
                    if (!empty($details['frame'])) {
                        update_post_meta($new_prod_id, '_frame', sanitize_text_field($details['frame']));
                        $custom_fields_saved++;
                    }
                    if (!empty($details['size'])) {
                        update_post_meta($new_prod_id, '_size', sanitize_text_field($details['size']));
                        $custom_fields_saved++;
                    }
                    if ($custom_fields_saved > 0) {
                        $logs[] = "Campos personalizados salvos: {$custom_fields_saved}";
                    }
                    
                    // Processar imagens
                    art_image_log_product_processing($sku, 'INICIANDO_PROCESSAMENTO_IMAGEM');
                    $images_processed = 0;
                    if (!empty($details['images']) && is_array($details['images'])) {
                        $logs[] = "Processando " . count($details['images']) . " imagens";
                        art_image_log_product_processing($sku, 'PROCESSANDO_IMAGENS', ['total' => count($details['images'])]);
                        
                        // Definir imagem principal (primeira imagem)
                        if ((!$product_id || !has_post_thumbnail($new_prod_id)) && !empty($details['images'][0])) {
                            $main_image_url = $details['images'][0];
                            if (art_image_is_valid_image_url($main_image_url)) {
                                $logs[] = "Baixando imagem principal: " . $main_image_url;
                                if ($thumb_id = $this->artimage_download_and_attach_image($main_image_url, $new_prod_id)) {
                                    set_post_thumbnail($new_prod_id, $thumb_id);
                                    $images_processed++;
                                    $logs[] = "Imagem principal definida (ID: {$thumb_id})";
                                } else {
                                    $logs[] = "ERRO: Falha ao baixar imagem principal";
                                    art_image_log_product_processing($sku, 'ERRO_DOWNLOAD_IMAGEM_PRINCIPAL', ['url' => $main_image_url]);
                                }
                            } else {
                                $logs[] = "AVISO: URL da imagem principal inválida: " . $main_image_url;
                                art_image_log_product_processing($sku, 'URL_IMAGEM_INVALIDA', ['url' => $main_image_url]);
                            }
                        }
                        
                        // Processar galeria de imagens (demais imagens)
                        if (count($details['images']) > 1) {
                            $max_gallery_images = apply_filters('art_image_max_gallery_images', 5);
                            $gallery_images = array_slice($details['images'], 1, $max_gallery_images);
                            $gallery_ids = [];
                            
                            foreach ($gallery_images as $img_url) {
                                if (art_image_is_valid_image_url($img_url)) {
                                    $logs[] = "Baixando imagem da galeria: " . $img_url;
                                    if ($attach_id = $this->artimage_download_and_attach_image($img_url, $new_prod_id)) {
                                        $gallery_ids[] = $attach_id;
                                        $images_processed++;
                                        $logs[] = "Imagem da galeria adicionada (ID: {$attach_id})";
                                    } else {
                                        $logs[] = "ERRO: Falha ao baixar imagem da galeria";
                                        art_image_log_product_processing($sku, 'ERRO_DOWNLOAD_IMAGEM_GALERIA', ['url' => $img_url]);
                                    }
                                } else {
                                    $logs[] = "AVISO: URL da imagem da galeria inválida: " . $img_url;
                                    art_image_log_product_processing($sku, 'URL_IMAGEM_GALERIA_INVALIDA', ['url' => $img_url]);
                                }
                            }
                            
                            // Definir galeria de imagens
                            if (!empty($gallery_ids)) {
                                $product->set_gallery_image_ids($gallery_ids);
                                $product->save();
                                $logs[] = "Galeria de imagens definida com " . count($gallery_ids) . " imagens";
                            }
                        }
                        
                        $logs[] = "Total de imagens processadas: {$images_processed}";
                        art_image_log_product_processing($sku, 'FIM_PROCESSAMENTO_IMAGEM', ['processadas' => $images_processed]);
                    } else {
                        $logs[] = "Nenhuma imagem disponível para este produto";
                        art_image_log_product_processing($sku, 'NENHUMA_IMAGEM_DISPONIVEL');
                    }
                    
                    // Marcar produto como importado no tracker
                    if (isset($GLOBALS['artimage_sync_tracker'])) {
                        $GLOBALS['artimage_sync_tracker']->mark_product_imported($new_prod_id, $sku);
                    }
                    
                    // Log final do processamento
                    art_image_log_product_processing($sku, 'PROCESSAMENTO_CONCLUIDO', [
                        'produto_id' => $new_prod_id,
                        'campos_personalizados' => $custom_fields_saved ?? 0,
                        'imagens_processadas' => $images_processed,
                        'acao' => $product_id ? 'ATUALIZADO' : 'CRIADO'
                    ]);
                    
                } else {
                    $logs[] = "ERRO: Falha ao salvar produto SKU: {$sku}";
                    art_image_log_product_processing($sku, 'ERRO_SALVAMENTO');
                }
            } catch (Exception $e) {
                $logs[] = "ERRO CRÍTICO ao processar SKU {$sku}: " . $e->getMessage();
                art_image_log_import_error('PRODUCT_IMPORT', "Erro ao processar produto {$sku}: " . $e->getMessage(), $p_data);
            }
            
            unset($product_queue[$queue_key_index]);
            $current_total_processed++; 
            $processed_in_this_run++;
        }

        set_transient($queue_key, array_values($product_queue), 12 * HOUR_IN_SECONDS);
        set_transient($processed_key, $current_total_processed, 12 * HOUR_IN_SECONDS);

        return ['status' => 'processing', 'logs' => $logs, 'processed_in_batch' => $processed_in_this_run, 'current_total_processed' => $current_total_processed, 'total_to_import' => $total_to_import, 'has_more' => !empty($product_queue)];
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
        if (empty($image_url)) {
            art_image_log_import_error('IMAGE_DOWNLOAD', 'URL da imagem está vazia', ['post_id' => $post_id]);
            return 0;
        }
        
        // Verifica se já existe attachment com essa URL para evitar duplicidade
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_artimage_original_url' AND meta_value = %s LIMIT 1",
            $image_url
        ));
        if ($existing) {
            ArtImageTimezoneHelper::log_with_timezone("Imagem já existe no sistema (ID: {$existing}): {$image_url}");
            return (int)$existing;
        }

        try {
            ArtImageTimezoneHelper::log_with_timezone("[DEBUG] Iniciando download da imagem para Post ID {$post_id} a partir de: {$image_url}");
            // Baixa a imagem para o diretório de uploads
            $timeout = apply_filters('art_image_download_timeout', 30);
            $tmp = download_url($image_url, $timeout);

            if (is_wp_error($tmp)) {
                art_image_log_import_error('IMAGE_DOWNLOAD', 'Erro ao baixar imagem: ' . $tmp->get_error_message(), [
                    'url' => $image_url,
                    'post_id' => $post_id
                ]);
                ArtImageTimezoneHelper::log_with_timezone("[DEBUG] Falha em download_url para Post ID {$post_id}. Erro: " . $tmp->get_error_message());
                return 0;
            }

            ArtImageTimezoneHelper::log_with_timezone("[DEBUG] Download da imagem concluído para Post ID {$post_id}. Arquivo temporário em: {$tmp}");

            // Prepara array para upload
            $file_array = [];
            $file_array['name'] = basename(parse_url($image_url, PHP_URL_PATH));
            $file_array['tmp_name'] = $tmp;
            
            // Garante que o nome do arquivo não está vazio
            if (empty($file_array['name']) || $file_array['name'] === '/') {
                $file_array['name'] = 'product_image_' . time() . '.jpg';
            }

            // Garante que as funções necessárias do WordPress estão carregadas
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            ArtImageTimezoneHelper::log_with_timezone("[DEBUG] Dependências de mídia carregadas. Executando media_handle_sideload para Post ID {$post_id}.");

            // Usa a função do WP para inserir como attachment
            $attach_id = media_handle_sideload($file_array, $post_id);
            
            if (is_wp_error($attach_id)) {
                @unlink($file_array['tmp_name']);
                art_image_log_import_error('IMAGE_UPLOAD', 'Erro ao criar attachment: ' . $attach_id->get_error_message(), [
                    'url' => $image_url,
                    'post_id' => $post_id,
                    'filename' => $file_array['name']
                ]);
                ArtImageTimezoneHelper::log_with_timezone("[DEBUG] Falha em media_handle_sideload para Post ID {$post_id}. Erro: " . $attach_id->get_error_message());
                return 0;
            }
            
            // Salva a URL original para evitar duplicidade futura
            update_post_meta($attach_id, '_artimage_original_url', $image_url);
            
            ArtImageTimezoneHelper::log_with_timezone("Imagem baixada e anexada com sucesso (ID: {$attach_id}): {$image_url}");
            return $attach_id;
            
        } catch (Exception $e) {
            art_image_log_import_error('IMAGE_DOWNLOAD', 'Exceção ao processar imagem: ' . $e->getMessage(), [
                'url' => $image_url,
                'post_id' => $post_id
            ]);
            return 0;
        }
    }
}

