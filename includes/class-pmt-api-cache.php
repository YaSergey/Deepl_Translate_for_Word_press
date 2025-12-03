<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_API_Cache
{
    private $group = 'pmt_deepl_cache';
    private $ttl;

    public function __construct($ttl = 86400)
    {
        $this->ttl = $ttl;
    }

    private function build_key($text, $source, $target, $provider = 'deepl')
    {
        return md5(wp_json_encode(array('t' => $text, 's' => $source, 'd' => $target, 'p' => $provider)));
    }

    public function get($text, $source, $target, $provider = 'deepl')
    {
        $key = $this->build_key($text, $source, $target, $provider);
        $cached = wp_cache_get($key, $this->group);

        if (false !== $cached) {
            return $cached;
        }

        $option_key = $this->group . '_' . $key;
        $option_value = get_option($option_key);
        if ($option_value) {
            wp_cache_set($key, $option_value, $this->group, $this->ttl);
            return $option_value;
        }

        return null;
    }

    public function set($text, $source, $target, $translated, $provider = 'deepl')
    {
        $key = $this->build_key($text, $source, $target, $provider);
        wp_cache_set($key, $translated, $this->group, $this->ttl);
        update_option($this->group . '_' . $key, $translated, false);
    }
}
