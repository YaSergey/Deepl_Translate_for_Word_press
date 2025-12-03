<?php

if (!defined('ABSPATH')) {
    exit;
}

class PMT_Page_Translator
{
    private $options;
    private $provider;
    private $logger;
    private $slug_generator;
    private $batcher;
    private $acf_service;
    private $seo_service;

    public function __construct($options, PMT_Translation_Provider_Interface $provider, callable $logger, callable $slug_generator, ?PMT_Translation_Batcher $batcher = null, ?PMT_ACF_Translation_Service $acf_service = null, ?PMT_SEO_Translation_Service $seo_service = null)
    {
        $this->options = $options;
        $this->provider = $provider;
        $this->logger = $logger;
        $this->slug_generator = $slug_generator;
        $this->batcher = $batcher;
        $this->acf_service = $acf_service;
        $this->seo_service = $seo_service;
    }

    public function translate_published_pages($target_language, $context = array())
    {
        if (!$this->can_use_polylang()) {
            return new WP_Error('pmt_missing_polylang', __('Polylang не активирован, перевод страниц недоступен.', 'polylang-mass-translation-deepl'));
        }

        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
        ));

        foreach ($pages as $page) {
            if (!empty($context['rule_engine']) && $context['rule_engine']->should_skip_post($page)) {
                continue;
            }

            $this->translate_single_page($page, $target_language, $context);
        }
    }

    public function translate_recent_pages($target_language, $since_timestamp, $context = array())
    {
        if (!$this->can_use_polylang()) {
            return new WP_Error('pmt_missing_polylang', __('Polylang не активирован, перевод страниц недоступен.', 'polylang-mass-translation-deepl'));
        }

        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'date_query' => array(
                array(
                    'after' => gmdate('Y-m-d H:i:s', $since_timestamp),
                    'inclusive' => true,
                ),
            ),
        ));

        foreach ($pages as $page) {
            if (!empty($context['rule_engine']) && $context['rule_engine']->should_skip_post($page)) {
                continue;
            }

            $this->translate_single_page($page, $target_language, $context);
        }
    }

    public function translate_single_page($page, $target_language, $context = array())
    {
        if (!$this->can_use_polylang()) {
            return new WP_Error('pmt_missing_polylang', __('Polylang не активирован, перевод страниц недоступен.', 'polylang-mass-translation-deepl'));
        }

        $source_language = pll_get_post_language($page->ID);

        if (!$source_language) {
            return;
        }

        $existing_translation = pll_get_post($page->ID, $target_language);
        if ($existing_translation) {
            return;
        }

        $translation = array(
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_author' => $page->post_author,
            'post_parent' => $page->post_parent,
            'menu_order' => $page->menu_order,
            'comment_status' => $page->comment_status,
            'ping_status' => $page->ping_status,
        );

        $translated_blocks = $this->translate_fields(array(
            'title' => $page->post_title,
            'content' => $page->post_content,
            'excerpt' => $page->post_excerpt,
        ), $source_language, $target_language);

        $translated_title = $translated_blocks['title'] ?? $page->post_title;
        $translated_content = $translated_blocks['content'] ?? $page->post_content;
        $translated_excerpt = $translated_blocks['excerpt'] ?? $page->post_excerpt;

        $translation['post_title'] = $translated_title ?: $page->post_title;
        $translation['post_content'] = $translated_content ?: $page->post_content;
        $translation['post_excerpt'] = $translated_excerpt ?: $page->post_excerpt;

        $translation['post_name'] = call_user_func($this->slug_generator, $translation['post_title'], $target_language);

        if (!empty($context['preview'])) {
            if (!empty($context['job_manager']) && !empty($context['job_id'])) {
                $preview = array(
                    'title' => $page->post_title,
                    'title_translated' => $translation['post_title'],
                    'excerpt_preview' => wp_trim_words(wp_strip_all_tags($page->post_content), 20),
                    'excerpt_translated' => wp_trim_words(wp_strip_all_tags($translation['post_content']), 20),
                );
                $context['job_manager']->add_entity($context['job_id'], 'page_preview', $page->ID, $preview);
            }
            return;
        }

        $translation_id = wp_insert_post($translation);

        if (is_wp_error($translation_id)) {
            $this->log_error('Ошибка создания перевода страницы: ' . $translation_id->get_error_message());
            return;
        }

        if (!empty($context['job_manager']) && !empty($context['job_id'])) {
            $context['job_manager']->add_entity($context['job_id'], 'post', $translation_id);
        }

        pll_set_post_language($translation_id, $target_language);
        $translations = pll_get_post_translations($page->ID);
        $translations[$target_language] = $translation_id;
        pll_save_post_translations($translations);

        $this->copy_featured_image($page->ID, $translation_id);
        $this->copy_post_meta($page->ID, $translation_id);

        if ($this->acf_service) {
            $this->acf_service->translate_fields($translation_id, $target_language, $source_language, $context);
        }

        if ($this->seo_service) {
            $this->seo_service->translate_seo_meta($translation_id, $target_language, $source_language, $context);
        }

        do_action('deepl_on_page_translated', $page->ID, $translation_id, $target_language);
    }

    private function translate_fields(array $fields, $source_language, $target_language)
    {
        $texts = array_values($fields);
        if ($this->batcher) {
            $translated = $this->batcher->translate_batch($texts, $target_language, $source_language);
        } else {
            $translated = array();
            foreach ($texts as $index => $text) {
                $result = $this->provider->translate_text($text, $target_language, $source_language, array(
                    'tag_handling' => 'html',
                    'preserve_formatting' => '1',
                ));
                if (is_wp_error($result)) {
                    $this->log_error($result->get_error_message());
                    continue;
                }
                $translated[$index] = $result;
            }
        }

        $keys = array_keys($fields);
        $result_map = array();
        foreach ($keys as $idx => $key) {
            $result_map[$key] = $translated[$idx] ?? $fields[$key];
        }

        return $result_map;
    }

    private function copy_featured_image($original_id, $translation_id)
    {
        $thumbnail_id = get_post_thumbnail_id($original_id);
        if ($thumbnail_id) {
            set_post_thumbnail($translation_id, $thumbnail_id);
        }
    }

    private function copy_post_meta($from_post_id, $to_post_id)
    {
        $meta = get_post_meta($from_post_id);
        $blocked = array('_edit_lock', '_edit_last');
        foreach ($meta as $key => $values) {
            if (in_array($key, $blocked, true)) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($to_post_id, $key, maybe_unserialize($value));
            }
        }
    }

    private function log_error($message)
    {
        call_user_func($this->logger, $message);
    }

    private function can_use_polylang()
    {
        return function_exists('pll_get_post_language') && function_exists('pll_get_post');
    }
}
