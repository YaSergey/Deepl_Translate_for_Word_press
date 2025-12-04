<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_Template_Translation_Service
{
    private $batcher;
    private $logger;

    public function __construct(PMT_Translation_Batcher $batcher, ?callable $logger = null)
    {
        $this->batcher = $batcher;
        $this->logger = $logger;
    }

    public function translate_templates($target_language, $context = array())
    {
        $templates = get_posts(array(
            'post_type' => 'ct_template',
            'post_status' => array('publish', 'draft'),
            'numberposts' => -1,
        ));

        foreach ($templates as $template) {
            if (!empty($context['rule_engine']) && $context['rule_engine']->should_skip_template($template->ID)) {
                continue;
            }
            $this->translate_single_template($template, $target_language, $context);
        }
    }

    private function translate_single_template($template, $target_language, $context = array())
    {
        $source_lang = function_exists('pll_get_post_language') ? pll_get_post_language($template->ID) : null;
        if (!$source_lang || ($existing = function_exists('pll_get_post') ? pll_get_post($template->ID, $target_language) : null)) {
            return;
        }

        do_action('deepl_before_template_translation', $template->ID, $target_language);

        $meta_json = get_post_meta($template->ID, '_ct_builder_json', true);
        $decoded = json_decode($meta_json, true);

        if (empty($decoded)) {
            return;
        }

        $texts = $this->collect_texts($decoded);
        $translated = $this->batcher->translate_batch($texts, $target_language, $source_lang);
        $translated_json = $this->apply_translations($decoded, $translated);

        if (!empty($context['preview'])) {
            if (!empty($context['job_manager']) && !empty($context['job_id'])) {
                $context['job_manager']->add_entity($context['job_id'], 'template_preview', $template->ID, array(
                    'title' => $template->post_title,
                    'title_translated' => $translated[0] ?? $template->post_title,
                ));
            }
            return;
        }

        $new_template_id = wp_insert_post(array(
            'post_type' => 'ct_template',
            'post_status' => 'draft',
            'post_title' => $translated[0] ?? ($template->post_title),
            'post_content' => $template->post_content,
        ));

        if (is_wp_error($new_template_id)) {
            $this->log('Template creation failed: ' . $new_template_id->get_error_message());
            return;
        }

        if (!empty($context['job_manager']) && !empty($context['job_id'])) {
            $context['job_manager']->add_entity($context['job_id'], 'post', $new_template_id);
        }

        update_post_meta($new_template_id, '_ct_builder_json', wp_json_encode($translated_json));
        $this->clone_template_meta($template->ID, $new_template_id);

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($new_template_id, $target_language);
            $translations = pll_get_post_translations($template->ID);
            $translations[$target_language] = $new_template_id;
            pll_save_post_translations($translations);
        }

        do_action('deepl_after_template_translation', $template->ID, $new_template_id, $target_language);
    }

    private function collect_texts(array $node)
    {
        $texts = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($node));
        foreach ($iterator as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (in_array($key, array('text', 'heading', 'title', 'name', 'ct_content', 'ct_title'), true)) {
                $texts[] = $value;
            }
        }

        return $texts;
    }

    private function apply_translations(array $structure, array $translations)
    {
        $index = 0;
        $walker = function (&$item, $key) use (&$index, $translations) {
            if (is_string($item) && in_array($key, array('text', 'heading', 'title', 'name', 'ct_content', 'ct_title'), true)) {
                $item = $translations[$index] ?? $item;
                $index++;
            }
        };

        array_walk_recursive($structure, $walker);
        return $structure;
    }

    private function clone_template_meta($source_id, $target_id)
    {
        $keys = array(
            '_ct_template_categories',
            '_ct_template_tags',
            '_ct_template_custom_taxonomies',
            '_ct_template_archive_among_taxonomies',
            '_ct_template_archive_post_types',
            '_ct_template_authors_archives',
            '_ct_template_post_types',
            '_ct_template_taxonomies',
            '_ct_template_post_of_parents',
            '_ct_builder_shortcodes',
        );

        foreach ($keys as $key) {
            $value = get_post_meta($source_id, $key, true);
            if ($value) {
                update_post_meta($target_id, $key, $value);
            }
        }
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
