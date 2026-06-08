<?php
/**
 * Sistema de tracking para sincronização completa
 * Permite marcar itens importados e deletar os que não estão mais na fonte
 */

if (!defined('ABSPATH')) {
    exit;
}

class ArtImageSyncTracker {
    
    private $current_sync_id;
    
    public function __construct() {
        $this->current_sync_id = get_option('artimage_current_sync_id', null);
        if (!$this->current_sync_id) {
            $this->current_sync_id = time(); // Fallback if not set
        }
    }
    
    /**
     * Inicia nova sessão de sincronização
     */
    public function start_sync_session() {
        $this->current_sync_id = time();
        update_option('artimage_current_sync_id', $this->current_sync_id);
        update_option('artimage_last_sync_start', current_time('mysql'));
        
        // Log do início da sincronização
        error_log("[Art Image] Iniciando nova sessão de sincronização: {$this->current_sync_id}");
        
        return $this->current_sync_id;
    }
    
    /**
     * Marca um produto como importado na sessão atual
     */
    public function mark_product_imported($product_id, $sku = null) {
        update_post_meta($product_id, '_artimage_last_sync', $this->current_sync_id);
        update_post_meta($product_id, '_artimage_last_sync_date', current_time('mysql'));
        
        if ($sku) {
            update_post_meta($product_id, '_artimage_source_sku', $sku);
        }
    }
    
    /**
     * Marca uma categoria como importada na sessão atual
     */
    public function mark_category_imported($term_id, $source_slug = null) {
        update_term_meta($term_id, '_artimage_last_sync', $this->current_sync_id);
        update_term_meta($term_id, '_artimage_last_sync_date', current_time('mysql'));
        
        if ($source_slug) {
            update_term_meta($term_id, '_artimage_source_slug', $source_slug);
        }
    }
    
    /**
     * Marca um artista como importado na sessão atual
     */
    public function mark_artist_imported($term_id, $source_slug = null) {
        update_term_meta($term_id, '_artimage_last_sync', $this->current_sync_id);
        update_term_meta($term_id, '_artimage_last_sync_date', current_time('mysql'));
        
        if ($source_slug) {
            update_term_meta($term_id, '_artimage_source_slug', $source_slug);
        }
    }
    
    /**
     * Finaliza sessão de sincronização e limpa itens não importados
     */
    public function finish_sync_session($options = []) {
        $cleanup_enabled = $options['cleanup_enabled'] ?? true;
        $dry_run = $options['dry_run'] ?? false;
        
        $results = [
            'products_deleted' => 0,
            'categories_deleted' => 0,
            'artists_deleted' => 0,
            'deleted_items' => []
        ];
        
        if ($cleanup_enabled) {
            // Limpar produtos não importados
            $results['products_deleted'] = $this->cleanup_products($dry_run);
            
            // Limpar categorias não importadas
            $results['categories_deleted'] = $this->cleanup_categories($dry_run);
            
            // Limpar artistas não importados
            $results['artists_deleted'] = $this->cleanup_artists($dry_run);
        }
        
        // Salvar estatísticas da sincronização
        update_option('artimage_last_sync_end', current_time('mysql'));
        update_option('artimage_last_sync_results', $results);
        
        error_log("[Art Image] Sincronização finalizada. Produtos deletados: {$results['products_deleted']}, Categorias: {$results['categories_deleted']}, Artistas: {$results['artists_deleted']}");
        
        return $results;
    }
    
    /**
     * Remove produtos que não foram importados na última sessão
     * IMPORTANTE: Só remove produtos que foram importados por este plugin
     * (identificados pela meta _artimage_source_sku)
     */
    private function cleanup_products($dry_run = false) {
        global $wpdb;

        // Busca produtos que:
        // 1. Foram importados por este plugin (têm _artimage_source_sku)
        // 2. Não foram marcados na sessão atual
        $products_to_delete = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm_source_sku.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_source_sku ON (p.ID = pm_source_sku.post_id AND pm_source_sku.meta_key = '_artimage_source_sku')
            LEFT JOIN {$wpdb->postmeta} pm_sync ON (p.ID = pm_sync.post_id AND pm_sync.meta_key = '_artimage_last_sync')
            WHERE p.post_type = 'product'
            AND p.post_status IN ('publish', 'private', 'draft')
            AND (pm_sync.meta_value IS NULL OR pm_sync.meta_value != %s)
            AND pm_source_sku.meta_value IS NOT NULL
        ", $this->current_sync_id));
        
        $deleted_count = 0;
        
        foreach ($products_to_delete as $product) {
            if ($dry_run) {
                error_log("[Art Image] DRY RUN - Deletaria produto: {$product->post_title} (SKU: {$product->sku})");
            } else {
                // Move para lixeira ao invés de deletar permanentemente
                wp_trash_post($product->ID);
                error_log("[Art Image] Produto movido para lixeira: {$product->post_title} (SKU: {$product->sku})");
            }
            $deleted_count++;
        }
        
        return $deleted_count;
    }
    
    /**
     * Remove categorias que não foram importadas na última sessão
     */
    private function cleanup_categories($dry_run = false) {
        global $wpdb;
        
        // Busca categorias que não foram marcadas na sessão atual
        $categories_to_delete = $wpdb->get_results($wpdb->prepare("
            SELECT t.term_id, t.name, tm_source.meta_value as source_slug
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            LEFT JOIN {$wpdb->termmeta} tm_sync ON (t.term_id = tm_sync.term_id AND tm_sync.meta_key = '_artimage_last_sync')
            LEFT JOIN {$wpdb->termmeta} tm_source ON (t.term_id = tm_source.term_id AND tm_source.meta_key = '_artimage_source_slug')
            WHERE tt.taxonomy = 'product_cat'
            AND (tm_sync.meta_value IS NULL OR tm_sync.meta_value != %s)
            AND tm_source.meta_value IS NOT NULL
        ", $this->current_sync_id));
        
        $deleted_count = 0;
        
        foreach ($categories_to_delete as $category) {
            if ($dry_run) {
                error_log("[Art Image] DRY RUN - Deletaria categoria: {$category->name} (Slug: {$category->source_slug})");
            } else {
                wp_delete_term($category->term_id, 'product_cat');
                error_log("[Art Image] Categoria deletada: {$category->name} (Slug: {$category->source_slug})");
            }
            $deleted_count++;
        }
        
        return $deleted_count;
    }
    
    /**
     * Remove artistas que não foram importados na última sessão
     */
    private function cleanup_artists($dry_run = false) {
        global $wpdb;
        
        // Busca artistas que não foram marcados na sessão atual
        $artists_to_delete = $wpdb->get_results($wpdb->prepare("
            SELECT t.term_id, t.name, tm_source.meta_value as source_slug
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            LEFT JOIN {$wpdb->termmeta} tm_sync ON (t.term_id = tm_sync.term_id AND tm_sync.meta_key = '_artimage_last_sync')
            LEFT JOIN {$wpdb->termmeta} tm_source ON (t.term_id = tm_source.term_id AND tm_source.meta_key = '_artimage_source_slug')
            WHERE tt.taxonomy = 'artist'
            AND (tm_sync.meta_value IS NULL OR tm_sync.meta_value != %s)
            AND tm_source.meta_value IS NOT NULL
        ", $this->current_sync_id));
        
        $deleted_count = 0;
        
        foreach ($artists_to_delete as $artist) {
            if ($dry_run) {
                error_log("[Art Image] DRY RUN - Deletaria artista: {$artist->name} (Slug: {$artist->source_slug})");
            } else {
                wp_delete_term($artist->term_id, 'artist');
                error_log("[Art Image] Artista deletado: {$artist->name} (Slug: {$artist->source_slug})");
            }
            $deleted_count++;
        }
        
        return $deleted_count;
    }
    
    /**
     * Retorna estatísticas da última sincronização
     */
    public function get_sync_stats() {
        return [
            'last_sync_start' => get_option('artimage_last_sync_start'),
            'last_sync_end' => get_option('artimage_last_sync_end'),
            'last_sync_results' => get_option('artimage_last_sync_results', []),
            'current_sync_id' => get_option('artimage_current_sync_id')
        ];
    }
}

// Inicializar o tracker globalmente
global $artimage_sync_tracker;
$artimage_sync_tracker = new ArtImageSyncTracker();

// Endpoint AJAX para limpeza manual
add_action('wp_ajax_art_image_manual_cleanup', function() {
    check_ajax_referer('art_image_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sem permissão');
    }
    
    global $artimage_sync_tracker;
    
    if (!$artimage_sync_tracker) {
        wp_send_json_error('Sistema de tracking não disponível');
    }
    
    $cleanup_enabled = get_option('artimage_enable_cleanup', true);
    $dry_run = get_option('artimage_cleanup_dry_run', false);
    
    if (!$cleanup_enabled && !$dry_run) {
        wp_send_json_error('Limpeza automática está desabilitada');
    }
    
    // Força o início de uma nova sessão para limpeza manual
    $artimage_sync_tracker->start_sync_session();
    
    $results = $artimage_sync_tracker->finish_sync_session([
        'cleanup_enabled' => true,
        'dry_run' => $dry_run
    ]);
    
    $message = $dry_run ? 'Simulação concluída' : 'Limpeza executada';
    $message .= " - Produtos: {$results['products_deleted']}, Categorias: {$results['categories_deleted']}, Artistas: {$results['artists_deleted']}";
    
    wp_send_json_success(['message' => $message, 'results' => $results]);
}); 