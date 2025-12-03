<?php

if (!defined('ABSPATH')) {
    exit;
}

class PMT_Preview_Store
{
    private $timeout;

    public function __construct($timeout = 3600)
    {
        $this->timeout = (int) $timeout;
    }

    public function store($job_id, array $data)
    {
        set_transient($this->key($job_id), $data, $this->timeout);
    }

    public function get($job_id)
    {
        $data = get_transient($this->key($job_id));
        return $data ? $data : array();
    }

    public function clear($job_id)
    {
        delete_transient($this->key($job_id));
    }

    private function key($job_id)
    {
        return 'pmt_preview_' . sanitize_key($job_id);
    }
}
