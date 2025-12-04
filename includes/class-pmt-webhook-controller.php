<?php
if (!defined('ABSPATH')) {
    exit;
}

class PMT_Webhook_Controller
{
    private $page_translator;
    private $template_translator;
    private $logger;

    public function __construct(PMT_Page_Translator $page_translator, PMT_Template_Translation_Service $template_translator, ?callable $logger = null)
    {
        $this->page_translator = $page_translator;
        $this->template_translator = $template_translator;
        $this->logger = $logger;

        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route('deepl/v1', '/translate', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_translate'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
    }

    public function handle_translate(WP_REST_Request $request)
    {
        $target_lang = sanitize_text_field($request->get_param('lang'));
        $pages = (array) $request->get_param('page_ids');
        $templates = (array) $request->get_param('template_ids');

        if (empty($target_lang)) {
            return new WP_Error('pmt_missing_lang', __('Укажите язык перевода', 'polylang-mass-translation-deepl'));
        }

        foreach ($pages as $page_id) {
            $page = get_post((int) $page_id);
            if ($page) {
                $this->page_translator->translate_single_page($page, $target_lang);
            }
        }

        foreach ($templates as $template_id) {
            $template = get_post((int) $template_id);
            if ($template) {
                $this->template_translator->translate_templates($target_lang);
            }
        }

        return rest_ensure_response(array('status' => 'ok'));
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
