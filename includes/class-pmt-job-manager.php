<?php

if (!defined('ABSPATH')) {
    exit;
}

class PMT_Job_Manager
{
    private $option_key = 'pmt_jobs';
    private $max_jobs = 20;

    public function create_job($type, $target_language, $mode = 'apply', $provider = 'deepl')
    {
        $jobs = $this->get_jobs();
        $job_id = uniqid('pmt_job_', true);

        $jobs[$job_id] = array(
            'id' => $job_id,
            'type' => $type,
            'mode' => $mode,
            'target_language' => $target_language,
            'provider' => $provider,
            'status' => 'pending',
            'created_at' => current_time('timestamp'),
            'updated_at' => current_time('timestamp'),
            'entities' => array(),
            'backups' => array(),
            'errors' => array(),
        );

        $jobs = $this->trim_jobs($jobs);
        update_option($this->option_key, $jobs, false);

        return $job_id;
    }

    public function add_entity($job_id, $type, $entity_id, array $preview = array())
    {
        $jobs = $this->get_jobs();
        if (!isset($jobs[$job_id])) {
            return;
        }

        $jobs[$job_id]['entities'][] = array(
            'type' => $type,
            'id' => $entity_id,
            'preview' => $preview,
        );
        $jobs[$job_id]['updated_at'] = current_time('timestamp');

        update_option($this->option_key, $jobs, false);
    }

    public function add_backup($job_id, $type, $entity_id, array $data)
    {
        $jobs = $this->get_jobs();
        if (!isset($jobs[$job_id])) {
            return;
        }

        $jobs[$job_id]['backups'][] = array(
            'type' => $type,
            'id' => $entity_id,
            'data' => $data,
        );
        $jobs[$job_id]['updated_at'] = current_time('timestamp');

        update_option($this->option_key, $jobs, false);
    }

    public function set_status($job_id, $status)
    {
        $jobs = $this->get_jobs();
        if (!isset($jobs[$job_id])) {
            return;
        }

        $jobs[$job_id]['status'] = $status;
        $jobs[$job_id]['updated_at'] = current_time('timestamp');

        update_option($this->option_key, $jobs, false);
    }

    public function add_error($job_id, $message)
    {
        $jobs = $this->get_jobs();
        if (!isset($jobs[$job_id])) {
            return;
        }

        $jobs[$job_id]['errors'][] = $message;
        $jobs[$job_id]['updated_at'] = current_time('timestamp');

        update_option($this->option_key, $jobs, false);
    }

    public function get_jobs()
    {
        $jobs = get_option($this->option_key, array());
        if (!is_array($jobs)) {
            $jobs = array();
        }
        return $jobs;
    }

    public function get_job($job_id)
    {
        $jobs = $this->get_jobs();
        return $jobs[$job_id] ?? null;
    }

    public function rollback($job_id)
    {
        $job = $this->get_job($job_id);
        if (!$job) {
            return new WP_Error('pmt_job_not_found', 'Job not found');
        }

        foreach ($job['backups'] as $backup) {
            if ($backup['type'] === 'post') {
                $this->rollback_post($backup['id'], $backup['data']);
            }

            if ($backup['type'] === 'menu') {
                $this->rollback_menu($backup['data']);
            }
        }

        foreach ($job['entities'] as $entity) {
            if ($entity['type'] === 'post') {
                wp_delete_post($entity['id'], true);
            }
            if ($entity['type'] === 'menu') {
                wp_delete_nav_menu($entity['id']);
            }
        }

        $this->set_status($job_id, 'rolled_back');

        return true;
    }

    private function rollback_post($post_id, array $data)
    {
        if (isset($data['post'])) {
            wp_update_post(array_merge(array('ID' => $post_id), $data['post']));
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    private function rollback_menu(array $data)
    {
        if (empty($data['menu_id']) || empty($data['items'])) {
            return;
        }

        foreach ($data['items'] as $item_id => $label) {
            wp_update_nav_menu_item($data['menu_id'], $item_id, array(
                'menu-item-title' => $label,
            ));
        }
    }

    private function trim_jobs(array $jobs)
    {
        if (count($jobs) <= $this->max_jobs) {
            return $jobs;
        }

        uasort($jobs, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });

        return array_slice($jobs, 0, $this->max_jobs, true);
    }
}
