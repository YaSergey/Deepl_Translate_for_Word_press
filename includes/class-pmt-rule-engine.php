<?php

if (!defined('ABSPATH')) {
    exit;
}

class PMT_Rule_Engine
{
    private $rules;

    public function __construct(array $rules = array())
    {
        $defaults = array(
            'include_post_types' => array('page'),
            'exclude_post_ids' => array(),
            'exclude_template_ids' => array(),
            'exclude_acf_keys' => array(),
            'exclude_selectors' => array(),
        );

        $this->rules = wp_parse_args($rules, $defaults);
    }

    public function should_skip_post($post)
    {
        $post_type = is_object($post) ? $post->post_type : $post;

        if (!in_array($post_type, (array) $this->rules['include_post_types'], true)) {
            return true;
        }

        $post_id = is_object($post) ? (int) $post->ID : (int) $post;
        if (in_array($post_id, array_map('intval', (array) $this->rules['exclude_post_ids']), true)) {
            return true;
        }

        return false;
    }

    public function should_skip_template($template_id)
    {
        return in_array((int) $template_id, array_map('intval', (array) $this->rules['exclude_template_ids']), true);
    }

    public function should_skip_acf_key($field_key)
    {
        return in_array($field_key, (array) $this->rules['exclude_acf_keys'], true);
    }

    public function should_skip_selector($selector)
    {
        return in_array($selector, (array) $this->rules['exclude_selectors'], true);
    }
}
