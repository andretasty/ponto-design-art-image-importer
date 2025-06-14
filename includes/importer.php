<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simulações de importação para testes iniciais
 */

function art_image_import_categories() {
    sleep(1); // Simula tempo de processamento
    // Aqui futuramente será feita a requisição e importação real
    return 'Categorias importadas com sucesso!';
}

function art_image_import_products() {
    sleep(2); // Simula tempo de processamento
    return 'Produtos importados com sucesso!';
}

function art_image_import_artists() {
    sleep(1); // Simula tempo de processamento
    return 'Artistas importados com sucesso!';
}
