<?php
if (!defined('ABSPATH')) {
    exit;
}

interface PMT_Translation_Provider_Interface
{
    public function get_key();

    public function get_label();

    public function translate_text($text, $target_language, $source_language = null, $options = array());

    public function translate_batch(array $items, $target_language, $source_language = null, $options = array());
}
