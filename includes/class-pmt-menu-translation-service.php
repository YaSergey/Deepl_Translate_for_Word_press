<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_Menu_Translation_Service
{
    private $batcher;
    private $logger;

    public function __construct(PMT_Translation_Batcher $batcher, ?callable $logger = null)
    {
        $this->batcher = $batcher;
        $this->logger = $logger;
    }

    public function translate_menus($target_language, $source_language, $context = array())
    {
        $menus = wp_get_nav_menus();
        foreach ($menus as $menu) {
            $this->translate_single_menu($menu, $target_language, $source_language, $context);
        }
    }

    private function translate_single_menu($menu, $target_language, $source_language, $context = array())
    {
        $items = wp_get_nav_menu_items($menu->term_id, array('post_status' => 'publish'));
        if (empty($items)) {
            return;
        }

        $labels = array();
        foreach ($items as $item) {
            $labels[] = $item->title;
        }

        $translated_labels = $this->batcher->translate_batch($labels, $target_language, $source_language);

        if (!empty($context['preview']) && !empty($context['job_manager']) && !empty($context['job_id'])) {
            foreach ($items as $index => $item) {
                $context['job_manager']->add_entity($context['job_id'], 'menu_preview', $menu->term_id, array(
                    'label' => $item->title,
                    'translated' => $translated_labels[$index] ?? $item->title,
                ));
            }
            return;
        }

        $new_menu_name = sprintf('%s (%s)', $menu->name, strtoupper($target_language));
        $new_menu_id = wp_create_nav_menu($new_menu_name);

        $relation_map = array();
        foreach ($items as $index => $item) {
            $linked_object_id = $item->object_id;
            if (function_exists('pll_get_post')) {
                $translated_post = pll_get_post($linked_object_id, $target_language);
                if ($translated_post) {
                    $linked_object_id = $translated_post;
                }
            }

            $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, array(
                'menu-item-title' => $translated_labels[$index] ?? $item->title,
                'menu-item-object-id' => $linked_object_id,
                'menu-item-object' => $item->object,
                'menu-item-type' => $item->type,
                'menu-item-status' => 'publish',
                'menu-item-parent-id' => 0,
            ));

            $relation_map[$item->ID] = $new_item_id;
        }

        // Assign parent relationships preserving structure
        foreach ($items as $item) {
            if ($item->menu_item_parent && isset($relation_map[$item->menu_item_parent], $relation_map[$item->ID])) {
                wp_update_nav_menu_item($new_menu_id, $relation_map[$item->ID], array(
                    'menu-item-parent-id' => $relation_map[$item->menu_item_parent],
                ));
            }
        }

        if (!empty($context['job_manager']) && !empty($context['job_id'])) {
            $snapshot = array();
            foreach ($items as $item) {
                $snapshot[$item->ID] = $item->title;
            }
            $context['job_manager']->add_backup($context['job_id'], 'menu', $new_menu_id, array(
                'menu_id' => $menu->term_id,
                'items' => $snapshot,
            ));
            $context['job_manager']->add_entity($context['job_id'], 'menu', $new_menu_id);
        }

        do_action('deepl_on_menu_translated', $menu->term_id, $new_menu_id, $target_language);
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
