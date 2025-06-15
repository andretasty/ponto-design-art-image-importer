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

    public function __construct()
    {
        $this->email    = get_option('art_image_email');
        $this->password = get_option('art_image_password');
    }

    /**
     * Retorna cookies válidos para uso ou faz login se necessário.
     */
    public function get_authenticated_cookies()
    {
        $cookies = get_option('artimage_cookies');
        if ($this->check_cookie_validity($cookies)) {
            return $cookies;
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
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return strpos($body, 'login/logout') !== false;
    }

    /**
     * Realiza o login em 3 etapas e retorna os cookies finais.
     */
    private function login()
    {
        // Etapa 1: obter CSRF e cookies iniciais
        $step1 = wp_remote_get("{$this->base_login_url}/login", [
            'timeout' => 20,
            'headers' => ['User-Agent' => $this->user_agent()],
        ]);

        if (is_wp_error($step1)) return false;

        $html   = wp_remote_retrieve_body($step1);
        preg_match('/var CSRF_TOKEN = "([^"]+)";/', $html, $matches);
        if (empty($matches[1])) return false;
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

        if (is_wp_error($step2)) return false;

        $json = json_decode(wp_remote_retrieve_body($step2), true);
        $jwt  = $json['result']['token'] ?? $json['token'] ?? null;
        if (!$jwt) return false;

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

        if (is_wp_error($step3)) return false;

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

    // Busca as categorias no site

    public function get_main_categories()
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) return [];

        $cookie_header = $this->format_cookies($cookies);

        $response = wp_remote_get('https://artimage.com.br/produtos/art-gallery', [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return [];

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
        if (!$cookies) return [];

        $cookie_header = $this->format_cookies($cookies);

        $response = wp_remote_get($main_category_url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return [];

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

    public function get_products($subcategory_slug)
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) return [];

        $cookie_header = $this->format_cookies($cookies);

        $base_url = "https://artimage.com.br/produtos/{$subcategory_slug}?order=1&grid=mini&page=1";

        // Pega a primeira página para descobrir o total de páginas
        $response = wp_remote_get($base_url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return [];

        $html = wp_remote_retrieve_body($response);
        $total_pages = 1;

        if (preg_match_all('#data-ci-pagination-page="(\d+)"#i', $html, $matches)) {
            $page_numbers = array_map('intval', $matches[1]);
            $total_pages = max($page_numbers);
        }

        $products = [];

        for ($page = 1; $page <= $total_pages; $page++) {
            $url = "https://artimage.com.br/produtos/{$subcategory_slug}?order=1&grid=mini&page={$page}";

            $response = wp_remote_get($url, [
                'headers' => ['Cookie' => $cookie_header],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) continue;

            $html = wp_remote_retrieve_body($response);

            if (preg_match_all('#<a href="([^"]+/produtos/detalhe/[^"]+)">.*?<span class="item-title">(.*?)</span>.*?<span class="item-price">(.*?)</span>.*?<span class="item-size">(.*?)</span>.*?<span class="item-code">(.*?)</span>#is', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $products[] = [
                        'link'  => html_entity_decode($match[1]),
                        'title' => trim(strip_tags($match[2])),
                        'price' => trim(strip_tags($match[3])),
                        'size'  => trim(strip_tags($match[4])),
                        'code'  => trim(strip_tags($match[5])),
                    ];
                }
            }
        }

        return $products;
    }

    // Retorna detalhes do produto

    public function get_product_details(string $product_url): array
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        // 1. Busca a página do produto
        $response = wp_remote_get($product_url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
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

        return $details;
    }

    public function get_artists($artists_page_url): array
    {
        $cookies = $this->get_authenticated_cookies();
        if (!$cookies) {
            return [];
        }

        $cookie_header = $this->format_cookies($cookies);

        $response = wp_remote_get($artists_page_url, [
            'headers' => ['Cookie' => $cookie_header],
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
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
