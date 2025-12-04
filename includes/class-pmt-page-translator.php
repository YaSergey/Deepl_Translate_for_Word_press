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

        $post_types = $this->get_enabled_post_types();

        if (empty($post_types)) {
            return;
        }

        $pages = get_posts(array(
            'post_type' => $post_types,
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

        $post_types = $this->get_enabled_post_types();

        if (empty($post_types)) {
            return;
        }

        $pages = get_posts(array(
            'post_type' => $post_types,
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

        if (!$this->is_supported_post_type($page->post_type)) {
            return;
        }

        $source_language = pll_get_post_language($page->ID);

        if (!$source_language) {
            return;
        }

        $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($page->ID) : array();
        $existing_translation = $translations[$target_language] ?? null;

        $translation = array(
            'ID' => $existing_translation ?: 0,
            'post_type' => $page->post_type,
            'post_status' => $existing_translation ? get_post_status($existing_translation) : 'draft',
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

        if ($existing_translation) {
            $translation_id = wp_update_post($translation, true);
        } else {
            $translation_id = wp_insert_post($translation);
        }

        if (is_wp_error($translation_id)) {
            $this->log_error('Ошибка создания перевода страницы: ' . $translation_id->get_error_message());
            return;
        }

        if (!empty($context['job_manager']) && !empty($context['job_id'])) {
            $context['job_manager']->add_entity($context['job_id'], 'post', $translation_id);
        }

        pll_set_post_language($translation_id, $target_language);
        $translations[$target_language] = $translation_id;
        pll_save_post_translations($translations);

        $this->copy_featured_image($page->ID, $translation_id);
        $this->sync_taxonomies($page->ID, $translation_id, $target_language);
        $this->copy_post_meta($page->ID, $translation_id, $source_language, $target_language, $context);

        if ($this->acf_service) {
            $sync_keys = $this->get_synced_meta_keys();
            $context['synced_meta'] = $sync_keys;
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
            if (is_wp_error($translated)) {
                $this->log_error($translated->get_error_message());
                return $fields;
            }
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

    private function copy_post_meta($from_post_id, $to_post_id, $source_language, $target_language, $context = array())
    {
        $meta = get_post_meta($from_post_id);
        $blocked = array('_edit_lock', '_edit_last');
        $synced_meta = $this->get_synced_meta_keys();

        $translate_candidates = array();
        $translate_keys = array();

        foreach ($meta as $key => $values) {
            if (in_array($key, $blocked, true)) {
                continue;
            }

            if (in_array($key, $synced_meta, true)) {
                delete_post_meta($to_post_id, $key);
                foreach ($values as $value) {
                    add_post_meta($to_post_id, $key, maybe_unserialize($value));
                }
                continue;
            }

            foreach ($values as $value) {
                $maybe_value = maybe_unserialize($value);
                if (is_string($maybe_value) && '' !== trim($maybe_value)) {
                    $translate_candidates[] = $maybe_value;
                    $translate_keys[] = $key;
                } else {
                    delete_post_meta($to_post_id, $key);
                    add_post_meta($to_post_id, $key, $maybe_value);
                }
            }
        }

        if (!empty($translate_candidates) && $this->batcher) {
            $translated = $this->batcher->translate_batch($translate_candidates, $target_language, $source_language);
            if (!is_wp_error($translated)) {
                foreach ($translated as $index => $translated_value) {
                    $key = $translate_keys[$index];
                    delete_post_meta($to_post_id, $key);
                    add_post_meta($to_post_id, $key, $translated_value);
                }
            }
        }
    }

    private function sync_taxonomies($source_post_id, $target_post_id, $target_language)
    {
        $taxonomies = get_object_taxonomies(get_post_type($source_post_id));
        if (empty($taxonomies)) {
            return;
        }

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($source_post_id, $taxonomy);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $target_terms = array();

            foreach ($terms as $term) {
                $translated_term_id = function_exists('pll_get_term') ? pll_get_term($term->term_id, $target_language) : 0;
                if ($translated_term_id) {
                    $target_terms[] = (int) $translated_term_id;
                    continue;
                }

                $inserted = wp_insert_term($term->name, $taxonomy, array('slug' => $term->slug));
                if (!is_wp_error($inserted) && !empty($inserted['term_id'])) {
                    $new_term_id = (int) $inserted['term_id'];
                    if (function_exists('pll_set_term_language')) {
                        pll_set_term_language($new_term_id, $target_language);
                    }

                    if (function_exists('pll_save_term_translations')) {
                        $translations = function_exists('pll_get_term_translations') ? pll_get_term_translations($term->term_id) : array();
                        $translations[$target_language] = $new_term_id;
                        pll_save_term_translations($translations);
                    }

                    $target_terms[] = $new_term_id;
                }
            }

            if (!empty($target_terms)) {
                wp_set_object_terms($target_post_id, $target_terms, $taxonomy);
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

    private function get_enabled_post_types()
    {
        $types = array();
        if (!empty($this->options['translate_posts'])) {
            $types[] = 'post';
        }
        if (!empty($this->options['translate_pages'])) {
            $types[] = 'page';
        }

        // Always limit to post and page
        return array_values(array_intersect($types, array('post', 'page')));
    }

    private function is_supported_post_type($post_type)
    {
        return in_array($post_type, $this->get_enabled_post_types(), true);
    }

    private function get_synced_meta_keys()
    {
        $synced = apply_filters('pll_copy_post_metas', array());
        return array_map('sanitize_key', (array) $synced);
    }
}
