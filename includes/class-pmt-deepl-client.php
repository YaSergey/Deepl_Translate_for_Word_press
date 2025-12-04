<?php

//chatGPT version 1.1

if (!defined('ABSPATH')) {
    exit;
}

class PMT_DeepL_Translation_Provider implements PMT_Translation_Provider_Interface
{
    private $api_key;
    private $api_url;
    private $logger;
    private $rate_limiter;

    public function __construct($api_key, $api_url, ?callable $logger = null, $rate_limiter = null)
    {
        $this->api_key      = $api_key;
        $this->api_url      = $api_url ?: 'https://api-free.deepl.com/v2/translate';
        $this->logger       = $logger;
        $this->rate_limiter = $rate_limiter;
    }

    public function get_key()
    {
        return 'deepl';
    }

    public function get_label()
    {
        return __('DeepL', 'polylang-mass-translation-deepl');
    }

    public function translate_batch(array $items, $target_language, $source_language = null, $options = array())
    {
        if (empty($items)) {
            return new WP_Error(
                'pmt_missing_data',
                __('Не хватает данных для запроса DeepL.', 'polylang-mass-translation-deepl')
            );
        }

        $items = $this->normalize_text_items($items);

        if (empty($items)) {
            return new WP_Error(
                'pmt_missing_data',
                __('Не хватает данных для запроса DeepL.', 'polylang-mass-translation-deepl')
            );
        }

        return $this->translate_text($items, $target_language, $source_language, $options);
    }

    public function translate_text($text, $target_language, $source_language = null, $extra_args = array())
    {
        if (is_array($text)) {
            $text = $this->normalize_text_items($text);
        }

        if ((is_array($text) && empty($text)) || empty($text) || empty($target_language) || empty($this->api_key)) {
            return new WP_Error(
                'pmt_missing_data',
                __('Не хватает данных для запроса DeepL.', 'polylang-mass-translation-deepl')
            );
        }

        // Подсчёт символов через хелпер с fallback без mbstring.
        $char_count = $this->count_characters($text);

        if ($this->rate_limiter && !$this->rate_limiter->allow($char_count)) {
            return new WP_Error(
                'pmt_rate_limited',
                __('Превышен лимит запросов к DeepL. Попробуйте позже.', 'polylang-mass-translation-deepl')
            );
        }

        $body = array(
            'auth_key'    => $this->api_key,
            'text'        => $text,
            'target_lang' => $this->map_language($target_language),
        );

        if (!empty($source_language)) {
            $body['source_lang'] = $this->map_language($source_language);
        }

        $style_args = apply_filters(
            'pmt_deepl_style_args',
            array(),
            $text,
            $target_language,
            $source_language
        );

        $body = array_merge($body, $extra_args, $style_args);

        $response = wp_remote_post($this->api_url, array(
            'body'    => $body,
            'timeout' => 20,
        ));

        if (is_wp_error($response)) {
            $this->log_error('DeepL HTTP error: ' . $response->get_error_message());
            return $response;
        }

        $status_code   = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $this->log_error('DeepL API error: ' . $response_body);

            return new WP_Error(
                'deepl_api_error',
                sprintf(
                    __('Ошибка DeepL: %s', 'polylang-mass-translation-deepl'),
                    $response_body
                )
            );
        }

        $data = json_decode($response_body, true);

        if (!isset($data['translations'][0]['text'])) {
            $this->log_error('DeepL malformed response: ' . $response_body);

            return new WP_Error(
                'deepl_bad_response',
                __('Некорректный ответ DeepL.', 'polylang-mass-translation-deepl')
            );
        }

        $texts = wp_list_pluck($data['translations'], 'text');

        if (is_array($text)) {
            return $texts;
        }

        return $texts[0];
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

    private function log_error($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }

    private function map_language($language)
    {
        $map = array(
            'en'    => 'EN-GB',
            'en_US'=> 'EN-US',
            'en_GB'=> 'EN-GB',
            'de'    => 'DE',
            'fr'    => 'FR',
            'es'    => 'ES',
            'it'    => 'IT',
            'pt'    => 'PT-PT',
            'pt_BR'=> 'PT-BR',
            'nl'    => 'NL',
            'pl'    => 'PL',
            'ru'    => 'RU',
            'uk'    => 'UK',
            'ja'    => 'JA',
            'zh'    => 'ZH',
            'bg'    => 'BG',
            'cs'    => 'CS',
            'da'    => 'DA',
            'el'    => 'EL',
            'et'    => 'ET',
            'fi'    => 'FI',
            'hu'    => 'HU',
            'id'    => 'ID',
            'lv'    => 'LV',
            'lt'    => 'LT',
            'ro'    => 'RO',
            'sk'    => 'SK',
            'sl'    => 'SL',
            'sv'    => 'SV',
            'tr'    => 'TR',
        );

        return $map[$language] ?? strtoupper($language);
    }
}

// Backwards compatibility
if (!class_exists('PMT_DeepL_Client')) {
    class_alias('PMT_DeepL_Translation_Provider', 'PMT_DeepL_Client');
}
