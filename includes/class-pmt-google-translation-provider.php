

<?php

//chatGpt version 1.1

if (!defined('ABSPATH')) {
    exit;
}

class PMT_Google_Translation_Provider implements PMT_Translation_Provider_Interface
{
    private $project_id;
    private $location;
    private $api_key;
    private $service_account;
    private $logger;
    private $rate_limiter;

    public function __construct(
        $project_id,
        $location,
        $api_key = '',
        $service_account_json = '',
        ?callable $logger = null,
        $rate_limiter = null
    ) {
        $this->project_id      = $project_id;
        $this->location        = $location ?: 'global';
        $this->api_key         = $api_key;
        $this->service_account = $service_account_json;
        $this->logger          = $logger;
        $this->rate_limiter    = $rate_limiter;
    }

    public function get_key()
    {
        return 'google';
    }

    public function get_label()
    {
        return __('Google Cloud Translation', 'polylang-mass-translation-deepl');
    }

    public function translate_text($text, $target_language, $source_language = null, $options = array())
    {
        $items = is_array($text) ? $text : array($text);
        $items = $this->normalize_text_items($items);

        if (empty($items)) {
            return new WP_Error(
                'pmt_google_missing_data',
                __('Не хватает данных для запроса Google Translation.', 'polylang-mass-translation-deepl')
            );
        }

        $result = $this->translate_batch($items, $target_language, $source_language, $options);

        if (is_wp_error($result)) {
            return $result;
        }

        if (is_array($text)) {
            return $result;
        }

        return $result[0] ?? $text;
    }

    public function translate_batch(array $items, $target_language, $source_language = null, $options = array())
    {
        $items = $this->normalize_text_items($items);

        if (empty($items) || empty($target_language) || (!$this->api_key && !$this->service_account)) {
            return new WP_Error(
                'pmt_google_missing_data',
                __('Не хватает данных для запроса Google Translation.', 'polylang-mass-translation-deepl')
            );
        }

        // Подсчёт символов через хелпер с fallback без mbstring.
        $char_count = $this->count_characters($items);

        if ($this->rate_limiter && !$this->rate_limiter->allow($char_count)) {
            return new WP_Error(
                'pmt_rate_limited',
                __('Превышен лимит запросов к Google Translation. Попробуйте позже.', 'polylang-mass-translation-deepl')
            );
        }

        $body = array(
            'contents'            => array_values($items),
            'targetLanguageCode'  => $this->normalize_language($target_language),
            'mimeType'            => 'text/html',
        );

        if (!empty($source_language)) {
            $body['sourceLanguageCode'] = $this->normalize_language($source_language);
        }

        $body = apply_filters(
            'deepl_translation_provider_options',
            $body,
            'google',
            $items,
            $target_language,
            $source_language
        );

        $url = sprintf(
            'https://translation.googleapis.com/v3/projects/%s/locations/%s:translateText',
            rawurlencode($this->project_id),
            rawurlencode($this->location)
        );

        $headers = array('Content-Type' => 'application/json');
        $args    = array(
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => 20,
        );

        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        } elseif (!empty($this->api_key)) {
            $url = add_query_arg('key', $this->api_key, $url);
        }

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $this->log('Google Translation HTTP error: ' . $response->get_error_message());
            return $response;
        }

        $status_code   = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            $this->log('Google Translation API error: ' . $response_body);

            return new WP_Error(
                'google_api_error',
                sprintf(
                    __('Ошибка Google Translation: %s', 'polylang-mass-translation-deepl'),
                    $response_body
                )
            );
        }

        $data         = json_decode($response_body, true);
        $translations = $data['translations'] ?? array();
        $texts        = array();

        foreach ($translations as $translation) {
            $texts[] = $translation['translatedText'] ?? '';
        }

        if (empty($texts)) {
            $this->log('Google Translation malformed response: ' . $response_body);

            return new WP_Error(
                'google_bad_response',
                __('Некорректный ответ Google Translation.', 'polylang-mass-translation-deepl')
            );
        }

        return $texts;
    }

    /**
     * Подсчёт длины строки или массива строк с fallback без mbstring.
     *
     * @param string|array $text
     * @return int
     */
    private function count_characters($text)
    {
        $length_fn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

        if (is_array($text)) {
            return array_sum(array_map(
                function ($item) use ($length_fn) {
                    return $length_fn((string) $item);
                },
                $text
            ));
        }

        return $length_fn((string) $text);
    }

    private function normalize_text_items(array $items)
    {
        $normalized = array();

        foreach ($items as $item) {
            $string_item = (string) $item;

            if ($string_item === '') {
                continue;
            }

            $normalized[] = $string_item;
        }

        return $normalized;
    }

    private function normalize_language($language)
    {
        return str_replace('_', '-', strtolower($language));
    }

    private function get_access_token()
    {
        if (empty($this->service_account)) {
            return '';
        }

        $cache_key = 'pmt_google_token_' . md5($this->service_account);
        $cached    = get_transient($cache_key);

        if ($cached) {
            return $cached;
        }

        $data = json_decode($this->service_account, true);

        if (empty($data['client_email']) || empty($data['private_key'])) {
            return new WP_Error(
                'pmt_google_credentials',
                __('Неверный формат JSON ключа службы Google.', 'polylang-mass-translation-deepl')
            );
        }

        $now   = time();
        $claim = array(
            'iss'   => $data['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-translation',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        );

        $jwt_header = rtrim(strtr(base64_encode(json_encode(array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        ))), '+/', '-_'), '=');

        $jwt_claim = rtrim(strtr(base64_encode(json_encode($claim)), '+/', '-_'), '=');

        $signature_input = $jwt_header . '.' . $jwt_claim;

        if (!function_exists('openssl_sign')) {
            return new WP_Error(
                'pmt_google_sign',
                __('Расширение OpenSSL недоступно, подпись JWT невозможна.', 'polylang-mass-translation-deepl')
            );
        }

        $signature = '';
        $success   = openssl_sign($signature_input, $signature, $data['private_key'], 'sha256');

        if (!$success) {
            return new WP_Error(
                'pmt_google_sign',
                __('Не удалось подписать JWT для Google.', 'polylang-mass-translation-deepl')
            );
        }

        $jwt = $signature_input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            $this->log('Google token HTTP error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            $this->log('Google token response error: ' . wp_remote_retrieve_body($response));

            return new WP_Error(
                'pmt_google_token_error',
                __('Не удалось получить токен доступа Google.', 'polylang-mass-translation-deepl')
            );
        }

        $token = $body['access_token'];

        set_transient($cache_key, $token, (int) $body['expires_in']);

        return $token;
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
