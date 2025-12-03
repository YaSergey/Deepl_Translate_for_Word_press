<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_Translation_Batcher
{
    private $provider;
    private $cache;
    private $logger;

    public function __construct(PMT_Translation_Provider_Interface $provider, PMT_API_Cache $cache, ?callable $logger = null)
    {
        $this->provider = $provider;
        $this->cache = $cache;
        $this->logger = $logger;
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
            $translation_result = $this->provider->translate_batch(array_values($pending), $target_language, $source_language, array_merge(array(
                'tag_handling' => 'html',
                'preserve_formatting' => '1',
            ), $options));

            if (is_wp_error($translation_result)) {
                $this->log('Batch translation error: ' . $translation_result->get_error_message());
            } else {
                $translated_values = is_array($translation_result) ? array_values($translation_result) : array($translation_result);
                $position = 0;
                foreach ($pending as $index => $original) {
                    $translated_text = $translated_values[$position] ?? $original;
                    $position++;
                    $translated_text = apply_filters('deepl_post_translate_text', $translated_text, $original, $source_language, $target_language);
                    $results[$index] = $translated_text;
                    $this->cache->set($original, $source_language, $target_language, $translated_text, $this->provider->get_key());
                }
            }
        }

        ksort($results);
        return $results;
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
