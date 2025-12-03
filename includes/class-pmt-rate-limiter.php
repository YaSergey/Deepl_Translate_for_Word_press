<?php

if (!defined('ABSPATH')) {
    exit;
}

class PMT_Rate_Limiter
{
    private $requests_per_minute;
    private $characters_per_minute;
    private $logger;

    public function __construct($requests_per_minute = 50, $characters_per_minute = 120000, ?callable $logger = null)
    {
        $this->requests_per_minute = (int) apply_filters('pmt_requests_per_minute', $requests_per_minute);
        $this->characters_per_minute = (int) apply_filters('pmt_characters_per_minute', $characters_per_minute);
        $this->logger = $logger ?: '__return_false';
    }

    private function get_window_key($type)
    {
        $minute = gmdate('YmdHi');
        return 'pmt_rl_' . $type . '_' . $minute;
    }

    private function get_count($key)
    {
        $value = get_transient($key);
        return $value ? (int) $value : 0;
    }

    private function increment($key, $amount)
    {
        $current = $this->get_count($key) + $amount;
        set_transient($key, $current, MINUTE_IN_SECONDS * 2);
        return $current;
    }

    public function allow($char_count = 0)
    {
        $request_key = $this->get_window_key('req');
        $char_key = $this->get_window_key('char');

        $requests = $this->get_count($request_key);
        $chars = $this->get_count($char_key);

        $would_exceed_requests = $requests + 1 > $this->requests_per_minute;
        $would_exceed_chars = $chars + $char_count > $this->characters_per_minute;

        if ($would_exceed_requests || $would_exceed_chars) {
            $this->log_limit('rate-limit-block', array(
                'requests' => $requests,
                'chars' => $chars,
            ));
            return false;
        }

        $this->increment($request_key, 1);
        $this->increment($char_key, $char_count);

        if ($requests + 1 > max(1, floor($this->requests_per_minute * 0.8)) || $chars + $char_count > max(1, floor($this->characters_per_minute * 0.8))) {
            $this->log_limit('rate-limit-warning', array(
                'requests' => $requests + 1,
                'chars' => $chars + $char_count,
            ));
        }

        return true;
    }

    private function log_limit($label, array $context = array())
    {
        if (!$this->logger) {
            return;
        }

        call_user_func($this->logger, $label . ': ' . wp_json_encode($context));
    }
}
