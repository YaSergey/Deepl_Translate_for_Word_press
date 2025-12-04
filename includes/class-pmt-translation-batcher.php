<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_Translation_Batcher
{
    private $provider;
    private $cache;
    private $logger;
    private $max_chars_per_request;

    public function __construct(PMT_Translation_Provider_Interface $provider, PMT_API_Cache $cache, ?callable $logger = null, $max_chars_per_request = 5000)
    {
        $this->provider = $provider;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->max_chars_per_request = (int) $max_chars_per_request > 0 ? (int) $max_chars_per_request : 5000;
    }

    public function set_provider(PMT_Translation_Provider_Interface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @param array $strings
     */
    public function translate_batch(array $strings, $target_language, $source_language = null, $options = array())
    {
        $results = array();
        $pending = array();

        foreach ($strings as $index => $string) {
            $pre_filtered = apply_filters('deepl_pre_translate_text', $string, $source_language, $target_language);
            $string = is_string($pre_filtered) ? $pre_filtered : $string;
            $cached = $this->cache->get($string, $source_language, $target_language, $this->provider->get_key());
            if (null !== $cached) {
                $results[$index] = $cached;
                continue;
            }
            $pending[$index] = $string;
        }

        if (!empty($pending)) {
            $chunks = $this->chunk_by_length(array_values($pending));
            $translated_values = array();

            foreach ($chunks as $chunk) {
                $translation_result = $this->provider->translate_batch($chunk, $target_language, $source_language, array_merge(array(
                    'tag_handling' => 'html',
                    'preserve_formatting' => '1',
                ), $options));

                if (is_wp_error($translation_result)) {
                    $this->log('Batch translation error: ' . $translation_result->get_error_message());
                    continue;
                }

                $translated_values = array_merge($translated_values, is_array($translation_result) ? array_values($translation_result) : array($translation_result));
            }

            $position = 0;
            foreach ($pending as $index => $original) {
                $translated_text = $translated_values[$position] ?? $original;
                $position++;
                $translated_text = apply_filters('deepl_post_translate_text', $translated_text, $original, $source_language, $target_language);
                $results[$index] = $translated_text;
                $this->cache->set($original, $source_language, $target_language, $translated_text, $this->provider->get_key());
            }
        }

        ksort($results);
        return $results;
    }

    private function chunk_by_length(array $items)
    {
        $chunks = array();
        $current = array();
        $current_length = 0;

        foreach ($items as $item) {
            $length = is_string($item) ? strlen($item) : 0;
            if ($current_length + $length > $this->max_chars_per_request && !empty($current)) {
                $chunks[] = $current;
                $current = array();
                $current_length = 0;
            }
            $current[] = $item;
            $current_length += $length;
        }

        if (!empty($current)) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
