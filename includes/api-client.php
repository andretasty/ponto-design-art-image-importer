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
}
