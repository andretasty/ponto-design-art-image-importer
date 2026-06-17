<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsável por interagir com a API do site externo.
 */
class ArtImageApiClient
{
    private $base_login_url = 'https://minha-conta.artimage.com.br';
    private $resolve_url    = 'https://artimage.com.br/resolve';

    private $email;
    private $password;
    private $timeout = 30; // Timeout padrão

    public function __construct()
    {
        $this->email    = get_option('art_image_email');
        $this->password = get_option('art_image_password');
        // Permite configurar timeout via filtro
        $this->timeout = apply_filters('art_image_api_timeout', 30);
    }

    /**
     * Retorna cookies válidos para uso ou faz login se necessário.
     */
    public function get_authenticated_cookies($force = false)
    {
        if (!$force) {
            $cookies = get_option('artimage_cookies');
            if ($this->check_cookie_validity($cookies)) {
                return $cookies;
            }
        }

        return $this->login();
    }

    /**
     * Verifica se os cookies atuais ainda estão válidos.
     */
    private function check_cookie_validity($cookies)
    {
        if (empty($cookies) || !is_array($cookies)) {
            return false;
        }

        $cookie_header = $this->format_cookies($cookies);
        $response = wp_remote_get('https://artimage.com.br', [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            art_image_log_import_error('API', 'Erro ao verificar validade dos cookies: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return strpos($body, 'login/logout') !== false;
    }

    /**
     * Login serializado entre processos (anti login-stampede).
     *
     * Durante um sync via Action Scheduler vários processos rodam em paralelo e
     * compartilham a MESMA option `artimage_cookies`. Se a sessão cai, todos tentam
     * relogar ao mesmo tempo; como o artimage costuma invalidar a sessão anterior a
     * cada login, logins concorrentes derrubam o cookie uns dos outros.
     *
     * Aqui usamos um lock nomeado do MySQL (GET_LOCK) para que apenas UM processo
     * faça o login de verdade; os demais aguardam e reaproveitam a sessão recém
     * renovada (re-checada dentro do lock), sem disparar novos logins.
     */
    private function login()
    {
        global $wpdb;
        $lock_name = 'art_image_login';
        $have_lock = false;

        if (isset($wpdb) && is_object($wpdb)) {
            $have_lock = ((int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, 15))) === 1;
        }

        // Já com o lock (ou após o timeout de espera), confere se outro processo
        // renovou a sessão enquanto aguardávamos — se sim, reaproveita.
        // Lê direto do banco (bypassando o Redis Object Cache persistente, que poderia
        // servir um valor velho e levar este processo a relogar à toa).
        $existing = $this->get_fresh_cookies();
        if ($this->check_cookie_validity($existing)) {
            if ($have_lock) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            }
            return $existing;
        }

        $result = $this->do_login();

        if ($have_lock) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }

        return $result;
    }

    /**
     * Lê a option artimage_cookies direto do banco, ignorando o object cache.
     * Usado dentro do lock de login para garantir que enxergamos a sessão mais
     * recente gravada por outro processo (o Redis persistente pode servir valor velho).
     */
    private function get_fresh_cookies()
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return get_option('artimage_cookies');
        }
        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'artimage_cookies')
        );
        if ($value === null) {
            return false;
        }
        // refresca o cache deste processo para leituras posteriores ficarem coerentes
        $cookies = maybe_unserialize($value);
        wp_cache_set('artimage_cookies', $cookies, 'options');
        return $cookies;
    }

    /**
     * Realiza o login em 3 etapas e retorna os cookies finais.
     */
    private function do_login()
    {
        // Etapa 1: obter CSRF e cookies iniciais
        $step1 = wp_remote_get("{$this->base_login_url}/login", [
            'timeout' => 20,
            'headers' => ['User-Agent' => $this->user_agent()],
        ]);

        if (is_wp_error($step1)) {
            art_image_log_import_error('API', 'Erro no login - Etapa 1: ' . $step1->get_error_message());
            return false;
        }

        $html   = wp_remote_retrieve_body($step1);
        preg_match('/var CSRF_TOKEN = "([^"]+)";/', $html, $matches);
        if (empty($matches[1])) {
            art_image_log_import_error('API', 'CSRF Token não encontrado');
            return false;
        }
        $csrf_token = $matches[1];
        $cookies1   = wp_remote_retrieve_cookies($step1);
        $cookie_str1 = $this->format_cookies($cookies1);

        // Etapa 2: enviar login
        $step2 = wp_remote_post("{$this->base_login_url}/login/login", [
            'timeout' => 20,
            'headers' => [
                'Content-Type'     => 'application/x-www-form-urlencoded; charset=UTF-8',
                'x-csrf-token'     => $csrf_token,
                'x-requested-with' => 'XMLHttpRequest',
                'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                'Referer'          => "{$this->base_login_url}/login",
                'Cookie'           => $cookie_str1,
                'User-Agent'       => $this->user_agent(),
            ],
            'body' => [
                'email'    => $this->email,
                'password' => $this->password,
            ],
        ]);

        if (is_wp_error($step2)) {
            art_image_log_import_error('API', 'Erro no login - Etapa 2: ' . $step2->get_error_message());
            return false;
        }

        $json = json_decode(wp_remote_retrieve_body($step2), true);
        $jwt  = $json['result']['token'] ?? $json['token'] ?? null;
        if (!$jwt) {
            art_image_log_import_error('API', 'JWT Token não encontrado na resposta de login', $json);
            return false;
        }

        $cookies2   = wp_remote_retrieve_cookies($step2);
        $all_cookies = array_merge($cookies1, $cookies2);
        $cookie_str2 = $this->format_cookies($all_cookies);

        // Etapa 3: resolver sessão
        $step3 = wp_remote_get("{$this->resolve_url}?token={$jwt}", [
            'timeout' => 20,
            'headers' => [
                'Referer'    => "{$this->base_login_url}/",
                'Cookie'     => $cookie_str2,
                'User-Agent' => $this->user_agent(),
            ],
        ]);

        if (is_wp_error($step3)) {
            art_image_log_import_error('API', 'Erro no login - Etapa 3: ' . $step3->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($step3);
        if (strpos($body, 'login/logout') !== false) {
            $final_cookies = array_merge($all_cookies, wp_remote_retrieve_cookies($step3));
            update_option('artimage_cookies', $final_cookies);
            return $final_cookies;
        }

        return false;
    }

    /**
     * Converte array de WP_Http_Cookie para string de header.
     */
    private function format_cookies($cookie_objects)
    {
        if (!is_array($cookie_objects)) return '';
        $pairs = [];
        foreach ($cookie_objects as $cookie) {
            $pairs[] = $cookie->name . '=' . $cookie->value;
        }
        return implode('; ', $pairs);
    }

    /**
     * User Agent usado nas requisições
     */
    private function user_agent()
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/108.0 Safari/537.36';
    }

    /**
     * Detecta se o HTML retornado é a tela de login (sessão expirada/redirecionada)
     * em vez de uma página autenticada. Toda página logada do artimage contém o link
     * "login/logout"; a tela de login não. Esse é o mesmo sinal usado por
     * check_cookie_validity(), de modo que uma subcategoria legitimamente vazia (mas
     * autenticada) NÃO é confundida com sessão caída.
     */
    private function looks_logged_out($html)
    {
        return !is_string($html) || strpos($html, 'login/logout') === false;
    }

    /**
     * GET autenticado com recuperação de sessão.
     *
     * Faz a requisição com os cookies atuais. Se o artimage redirecionar para a tela de
     * login (sessão expirada ou rotacionada durante um sync longo/paralelo via Action
     * Scheduler), força um novo login e repete a requisição UMA vez. Atualiza
     * $cookie_header por referência para que as chamadas seguintes reaproveitem a sessão
     * renovada.
     *
     * Sem isso, uma sessão caída faz o site devolver a página de login (sem nenhum
     * link de produto), o scraper interpreta como "nenhum produto" e a subcategoria
     * fica vazia silenciosamente.
     */
    private function authenticated_get($url, &$cookie_header)
    {
        $response = wp_remote_get($url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!$this->looks_logged_out(wp_remote_retrieve_body($response))) {
            return $response;
        }

        // Sessão caiu no meio da coleta: re-autentica (forçado) e tenta de novo.
        art_image_log_import_error('API', 'Sessão expirada detectada (redirecionado ao login). Re-autenticando e repetindo: ' . $url);
        $cookies = $this->get_authenticated_cookies(true);
        if (!$cookies) {
            return new WP_Error('art_image_reauth_failed', 'Falha ao re-autenticar durante coleta de ' . $url);
        }
        $cookie_header = $this->format_cookies($cookies);

        return wp_remote_get($url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => $this->timeout,
        ]);
    }

    // Busca as categorias no site

    public function get_main_categories()
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            art_image_log_import_error('API', 'Falha na autenticação ao buscar categorias');
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        $response = wp_remote_get('https://artimage.com.br/produtos/art-gallery', [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            art_image_log_import_error('API', 'Erro ao buscar categorias principais: ' . $response->get_error_message());
            return [];
        }

        $html = wp_remote_retrieve_body($response);

        // Parse nav.sections
        $categories = [];

        if (preg_match('#<nav class="sections[^"]*">(.*?)</nav>#is', $html, $navMatch)) {
            $navContent = $navMatch[1];
            if (preg_match_all('#<a[^>]+href="([^"]+)"[^>]*>\s*<span>([^<]+)</span>#i', $navContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = trim($match[1]);
                    $name = trim($match[2]);
                    $slug = basename(parse_url($url, PHP_URL_PATH));
                    $categories[] = [
                        'nome' => $name,
                        'slug' => $slug,
                        'url'  => $url,
                    ];
                }
            }
        }

        return $categories;
    }

    public function get_subcategories($main_category_url)
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            art_image_log_import_error('API', 'Falha na autenticação ao buscar subcategorias');
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        $response = wp_remote_get($main_category_url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => $this->timeout,
        ]);

        if (is_wp_error($response)) {
            art_image_log_import_error('API', 'Erro ao buscar subcategorias de ' . $main_category_url . ': ' . $response->get_error_message());
            return [];
        }

        $html = wp_remote_retrieve_body($response);

        $subcategories = [];

        if (preg_match_all('#<a[^>]+href="([^"]*filtro-sub=\d+)"[^>]*>\s*<span>([^<]+)</span>#i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = html_entity_decode($match[1]);
                $name = trim($match[2]);
                $slug = basename(parse_url($url, PHP_URL_PATH)) . '?' . parse_url($url, PHP_URL_QUERY);
                $subcategories[] = [
                    'nome' => $name,
                    'slug' => $slug,
                    'url'  => 'https://artimage.com.br' . $url,
                ];
            }
        }

        return $subcategories;
    }

    // Busca os produtos

    public function get_products($subcategory_slug_or_url)
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            art_image_log_import_error('API', 'Falha na autenticação ao buscar produtos');
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        // Permite receber uma URL completa ou apenas o slug
        if (strpos($subcategory_slug_or_url, 'http') === 0) {
            // É uma URL completa
            $base_url = $subcategory_slug_or_url;
        } else {
            // É um slug
            $base_url = "https://artimage.com.br/produtos/{$subcategory_slug_or_url}?order=1&grid=mini&page=1";
        }

        // Pega a primeira página para descobrir o total de páginas
        $response = $this->authenticated_get($base_url, $cookie_header);

        if (is_wp_error($response)) {
            art_image_log_import_error('API', 'Erro ao buscar produtos de ' . $base_url . ': ' . $response->get_error_message());
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        $total_pages = 1;

        if (preg_match_all('#data-ci-pagination-page="(\d+)"#i', $html, $matches)) {
            $page_numbers = array_map('intval', $matches[1]);
            $total_pages = max($page_numbers);
        }

        $products = [];

        for ($page = 1; $page <= $total_pages; $page++) {
            if (strpos($subcategory_slug_or_url, 'http') === 0) {
                // Adiciona ou substitui o parâmetro page na URL
                $url = preg_replace('/([&?])page=\d*/', '', $subcategory_slug_or_url);
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'order=1&grid=mini&page=' . $page;
            } else {
                $url = "https://artimage.com.br/produtos/{$subcategory_slug_or_url}?order=1&grid=mini&page={$page}";
            }

            $response = $this->authenticated_get($url, $cookie_header);

            if (is_wp_error($response)) {
                art_image_log_import_error('API', 'Erro ao buscar página ' . $page . ' de produtos: ' . $response->get_error_message());
                continue;
            }

            $html = wp_remote_retrieve_body($response);

            // Regex atualizado para a nova estrutura HTML (novembro 2025)
            // Captura: link, título, preço, tamanho e código (SKU)
            $pattern = '#<a\s+href="([^"]+/produtos/detalhe/[^"]+)"[^>]*>.*?'
                     . '<span\s+class="item-title">([^<]+)</span>.*?'
                     . '<span\s+class="item-price">([^<]+)</span>.*?'
                     . '<span\s+class="item-size">([^<]+)</span>.*?'
                     . '<span\s+class="item-code[^"]*">([^<]+)</span>.*?'
                     . '</a>#is';

            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $products[] = [
                        'link'        => html_entity_decode(trim($match[1])),
                        'title'       => trim($match[2]),
                        'price'       => trim($match[3]),
                        'size'        => trim($match[4]),
                        'code'        => trim($match[5]),
                        'artist_name' => '', // Será preenchido durante a importação se necessário
                    ];
                }
            } else {
                // Se nenhum produto foi encontrado, registra para debug
                $html_length = strlen($html);
                $has_links = strpos($html, '/produtos/detalhe/') !== false;
                art_image_log_import_error('API', "Nenhum produto encontrado na página {$page}. HTML: {$html_length} bytes, Links presentes: " . ($has_links ? 'sim' : 'não'));
            }
        }

        return $products;
    }

    // Retorna detalhes do produto

    public function get_product_details(string $product_url): array
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            art_image_log_import_error('API', 'Falha na autenticação ao buscar detalhes do produto');
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        // 1. Busca a página do produto
        $response = $this->authenticated_get($product_url, $cookie_header);
        if (is_wp_error($response)) {
            art_image_log_import_error('API', 'Erro ao buscar detalhes do produto ' . $product_url . ': ' . $response->get_error_message());
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        $details = [];

        // 2. Título do produto
        if (preg_match(
            '#<h1[^>]+class="head-title"[^>]*>([^<]+)</h1>#i',
            $html,
            $match
        )) {
            $details['title'] = trim($match[1]);
        }

        // 3. Imagens (todas dentro de ovw-slick)
        $details['images'] = [];
        if (preg_match_all(
            '#<img[^>]+class="ovw-image"[^>]+src="([^"]+)"#i',
            $html,
            $img_matches
        )) {
            foreach ($img_matches[1] as $src) {
                $details['images'][] = html_entity_decode($src);
            }
        }

        // 4. Artista
        if (preg_match(
            '#<h3>\s*Artista\s*</h3>.*?<b[^>]*>([^<]+)</b>#is',
            $html,
            $match
        )) {
            $details['artist'] = trim($match[1]);
        }

        // 5. Código (SKU)
        if (preg_match(
            '#<h3>\s*Código\s*</h3>.*?<p[^>]*class="sku"[^>]*>([^<]+)</p>#is',
            $html,
            $match
        )) {
            $details['code'] = trim($match[1]);
        }

        // 6. Técnica
        if (preg_match(
            '#<h3>\s*Técnica\s*</h3>.*?<p>([^<]+)</p>#is',
            $html,
            $match
        )) {
            $details['technique'] = trim($match[1]);
        }

        // 7. Moldura
        if (preg_match(
            '#<h3>\s*Moldura\s*</h3>.*?<p>([^<]+)</p>#is',
            $html,
            $match
        )) {
            $details['frame'] = trim($match[1]);
        }

        // 8. Descrição
        if (preg_match(
            '#<h3>\s*Descrição\s*</h3>.*?<div class="col-data">\s*(.*?)\s*</div>#is',
            $html,
            $match
        )) {
            $details['description'] = trim($match[1]);
        }

        // 9. Tamanho
        if (preg_match(
            '#<p[^>]+id="size"[^>]*>([^<]+)</p>#i',
            $html,
            $match
        )) {
            $details['size'] = trim($match[1]);
        }

        // 10. Preço
        if (preg_match(
            '#<p[^>]+id="price"[^>]*>([^<]+)</p>#i',
            $html,
            $match
        )) {
            $details['price'] = trim($match[1]);
        }

        // 11. Id de detalhe de origem (para agrupar composições)
        if (preg_match('#/detalhe/(\d+)#', $product_url, $idm)) {
            $details['detalhe_id'] = (int) $idm[1];
        }

        // 12. Obras da composição (produtos que aparecem só dentro deste produto).
        // A descoberta sai de graça: o HTML do produto já foi baixado acima.
        if (class_exists('ArtImageComposition')) {
            $members = ArtImageComposition::parse_from_html($html);
            if (!empty($members)) {
                $details['composition_members'] = $members;
            }
        }

        return $details;
    }

    public function get_artists($artists_page_url): array
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            art_image_log_import_error('API', 'Falha na autenticação ao buscar artistas');
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        $response = wp_remote_get($artists_page_url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => $this->timeout,
        ]);
        if (is_wp_error($response)) {
            art_image_log_import_error('API', 'Erro ao buscar artistas de ' . $artists_page_url . ': ' . $response->get_error_message());
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        $artists = [];

        // Captura cada bloco .artt-item
        if (preg_match_all(
            '#<div\s+class="artt-item"[^>]*data-slug="([^"]+)"[^>]*>.*?<figure[^>]*>.*?<img\s+src="([^"]+)"[^>]*>.*?</figure>.*?<h2\s+class="artt-name">(.*?)</h2>.*?<div\s+class="artt-text">.*?<p>(.*?)</p>#is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $slug        = trim($m[1]);
                $image_url   = html_entity_decode($m[2]);
                $title       = trim(strip_tags($m[3]));
                $description = trim(strip_tags($m[4]));

                $artists[] = [
                    'slug'        => $slug,
                    'image'       => $image_url,
                    'title'       => $title,
                    'description' => $description,
                ];
            }
        }

        return $artists;
    }
}
