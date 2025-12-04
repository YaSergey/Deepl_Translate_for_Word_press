<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_ACF_Translation_Service
{
    private $batcher;
    private $logger;

    private $text_field_types = array('text', 'textarea', 'wysiwyg');

    public function __construct(PMT_Translation_Batcher $batcher, ?callable $logger = null)
    {
        $this->batcher = $batcher;

    }

    public function translate_fields($post_id, $target_language, $source_language, $context = array())
    {
        if (!function_exists('get_field_objects')) {
            return;
        }

        $fields = get_field_objects($post_id);
        if (empty($fields)) {
            return;
        }


        $synced_keys = !empty($context['synced_meta']) ? (array) $context['synced_meta'] : array();

        foreach ($fields as $key => $field) {
            if (!empty($context['rule_engine']) && $context['rule_engine']->should_skip_acf_key($field['key'])) {
                continue;
            }

            if (in_array($field['key'], $synced_keys, true)) {
                continue;
            }

            $this->collect_field_texts($field, $texts);
            $field_map[] = $field['key'];
        }

        if (empty($texts)) {
            return;
        }

        $translated = $this->batcher->translate_batch($texts, $target_language, $source_language);

        if (!empty($context['preview']) && !empty($context['job_manager']) && !empty($context['job_id'])) {
            foreach ($translated as $index => $value) {
                $context['job_manager']->add_entity($context['job_id'], 'acf_preview', $post_id, array(
                    'field_key' => $field_map[$index] ?? '',
                    'original' => $texts[$index] ?? '',
                    'translated' => $value,
                ));
            }
            return;
        }

        $this->apply_translations($post_id, $fields, $translated, $context);
    }

    private function collect_field_texts($field, array &$texts)
    {
        if (in_array($field['type'], $this->text_field_types, true)) {
            $texts[] = $field['value'];
            return;
        }

        if ('repeater' === $field['type'] && is_array($field['value'])) {
            foreach ($field['value'] as $row) {
                foreach ($field['sub_fields'] as $sub_field) {
                    $sub_field['value'] = $row[$sub_field['name']] ?? '';
                    $this->collect_field_texts($sub_field, $texts);
                }
            }
        }
    }

    private function apply_translations($post_id, array $fields, array $translations, $context = array())
    {
        $index = 0;
        $synced_keys = !empty($context['synced_meta']) ? (array) $context['synced_meta'] : array();
        foreach ($fields as $field) {
            if (in_array($field['key'], $synced_keys, true)) {
                if (!empty($context['job_manager']) && !empty($context['job_id'])) {
                    $context['job_manager']->add_backup($context['job_id'], 'post', $post_id, array(
                        'meta' => array($field['key'] => $field['value']),
                    ));
                }
                update_field($field['key'], $field['value'], $post_id);
                continue;
            }

            if (in_array($field['type'], $this->text_field_types, true)) {
                $translated_value = $translations[$index] ?? $field['value'];
                if (!empty($context['job_manager']) && !empty($context['job_id'])) {
                    $context['job_manager']->add_backup($context['job_id'], 'post', $post_id, array(
                        'meta' => array($field['key'] => $field['value']),
                    ));
                }
                update_field($field['key'], $translated_value, $post_id);
                $index++;
                continue;
            }

            if ('repeater' === $field['type'] && is_array($field['value'])) {
                $new_rows = array();
                foreach ($field['value'] as $row) {
                    $new_row = array();
                    foreach ($field['sub_fields'] as $sub_field) {
                        if (in_array($sub_field['type'], $this->text_field_types, true)) {
                            $new_row[$sub_field['name']] = $translations[$index] ?? ($row[$sub_field['name']] ?? '');
                            $index++;
                        } else {
                            $new_row[$sub_field['name']] = $row[$sub_field['name']] ?? '';
                        }
                    }
                    $new_rows[] = $new_row;
                }
                if (!empty($context['job_manager']) && !empty($context['job_id'])) {
                    $context['job_manager']->add_backup($context['job_id'], 'post', $post_id, array(
                        'meta' => array($field['key'] => $field['value']),
                    ));
                }
                update_field($field['key'], $new_rows, $post_id);
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
