<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_SEO_Translation_Service
{
    private $batcher;
    private $logger;

    public function __construct(PMT_Translation_Batcher $batcher, ?callable $logger = null)
    {
        $this->batcher = $batcher;
        $this->logger = $logger;
    }

    public function translate_seo_meta($post_id, $target_language, $source_language, $context = array())
    {
        $texts = array();
        $keys = array();

        // Yoast
        $yoast_fields = array('yoast_wpseo_title', 'yoast_wpseo_metadesc', 'yoast_wpseo_opengraph-title', 'yoast_wpseo_opengraph-description', 'yoast_wpseo_twitter-title', 'yoast_wpseo_twitter-description');
        foreach ($yoast_fields as $field) {
            $value = get_post_meta($post_id, '_' . $field, true);
            if ($value) {
                $texts[] = $value;
                $keys[] = '_' . $field;
            }
        }

        // RankMath
        $rank_fields = array('rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_twitter_title', 'rank_math_twitter_description');
        foreach ($rank_fields as $field) {
            $value = get_post_meta($post_id, $field, true);
            if ($value) {
                $texts[] = $value;
                $keys[] = $field;
            }
        }

        if (empty($texts)) {
            return;
        }

        $translated = $this->batcher->translate_batch($texts, $target_language, $source_language);

        foreach ($keys as $index => $meta_key) {
            $previous = get_post_meta($post_id, $meta_key, true);
            if (!empty($context['preview']) && !empty($context['job_manager']) && !empty($context['job_id'])) {
                $context['job_manager']->add_entity($context['job_id'], 'seo_preview', $post_id, array(
                    'meta_key' => $meta_key,
                    'original' => $previous,
                    'translated' => $translated[$index] ?? $previous,
                ));
                continue;
            }

            if (!empty($context['job_manager']) && !empty($context['job_id'])) {
                $context['job_manager']->add_backup($context['job_id'], 'post', $post_id, array(
                    'meta' => array($meta_key => $previous),
                ));
            }

            update_post_meta($post_id, $meta_key, $translated[$index] ?? $previous);
        }
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
