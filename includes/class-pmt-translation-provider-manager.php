<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_Translation_Provider_Manager
{
    private $providers = array();
    private $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    public function register_provider(PMT_Translation_Provider_Interface $provider)
    {
        $this->providers[$provider->get_key()] = $provider;
    }

    public function get_provider($key)
    {
        return $this->providers[$key] ?? null;
    }

    public function get_default_provider(array $options = array())
    {
        $choice = $options['translation_engine'] ?? 'deepl';
        $choice = apply_filters('deepl_translation_provider_choice', $choice, $options);
        return $this->get_provider($choice) ?: $this->get_provider('deepl');
    }

    public function all()
    {
        return $this->providers;
    }

    public function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
