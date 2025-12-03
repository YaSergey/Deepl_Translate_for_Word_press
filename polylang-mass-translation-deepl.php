<?php
/**
 * Plugin Name: Polylang Mass Translation with DeepL
 * Description: Добавляет кнопку для массового создания переводов постов через Polylang с автоматическим переводом через DeepL API
 * Version: 5.1
 * Author: Sergey Yasnetsky
 */

// Предотвращаем прямой доступ
if (!defined('ABSPATH')) {
    exit;
}

class PolylangMassTranslation
{

    private $option_name = 'pmt_settings';

    public function __construct()
    {
        add_action('admin_init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }

    public function toLog($text)
    {

        file_put_contents(plugin_dir_path(__FILE__) . 'log.txt', json_encode($text, JSON_PRETTY_PRINT));
    }

    public function settings_section_callback()
    {
        echo '<p>Настройте параметры для автоматического перевода через DeepL API</p>';
    }

    public function deepl_api_key_render()
    {
        $options = get_option($this->option_name);
        echo '<input type="password" name="' . $this->option_name . '[deepl_api_key]" value="' . esc_attr($options['deepl_api_key'] ?? '') . '" size="50">';
        echo '<p class="description">Получите API ключ на <a href="https://www.deepl.com/pro-api" target="_blank">DeepL Pro API</a></p>';
    }

    public function deepl_api_url_render()
    {
        $options = get_option($this->option_name);
        echo '<input type="url" name="' . $this->option_name . '[deepl_api_url]" value="' . esc_attr($options['deepl_api_url'] ?? 'https://api-free.deepl.com/v2/translate') . '" size="50">';
        echo '<p class="description">Для Pro аккаунта используйте: https://api.deepl.com/v2/translate</p>';
    }

    public function translate_options_render()
    {
        $options = get_option($this->option_name);

        echo '<label><input type="checkbox" name="' . $this->option_name . '[translate_title]" value="1" ' . checked(1, $options['translate_title'] ?? 1, false) . '> Переводить заголовки</label><br>';
        echo '<label><input type="checkbox" name="' . $this->option_name . '[translate_content]" value="1" ' . checked(1, $options['translate_content'] ?? 1, false) . '> Переводить содержимое</label><br>';
        echo '<label><input type="checkbox" name="' . $this->option_name . '[translate_excerpt]" value="1" ' . checked(1, $options['translate_excerpt'] ?? 1, false) . '> Переводить краткое описание</label>';
    }

    public function post_status_render()
    {
        $options = get_option($this->option_name);
        $statuses = array(
            'draft' => 'Черновик',
            'publish' => 'Опубликован',
            'private' => 'Приватный'
        );

        echo '<select name="' . $this->option_name . '[post_status]">';
        foreach ($statuses as $status => $label) {
            echo '<option value="' . $status . '" ' . selected($status, $options['post_status'] ?? 'draft', false) . '>' . $label . '</option>';
        }
        echo '</select>';
    }

    public function sanitize_settings($input)
    {
        $sanitized = array();

        $sanitized['deepl_api_key'] = sanitize_text_field($input['deepl_api_key'] ?? '');
        $sanitized['deepl_api_url'] = esc_url_raw($input['deepl_api_url'] ?? 'https://api-free.deepl.com/v2/translate');
        $sanitized['translate_title'] = isset($input['translate_title']) ? 1 : 0;
        $sanitized['translate_content'] = isset($input['translate_content']) ? 1 : 0;
        $sanitized['translate_excerpt'] = isset($input['translate_excerpt']) ? 1 : 0;
        $sanitized['post_status'] = sanitize_text_field($input['post_status'] ?? 'draft');

        return $sanitized;
    }

    public function activate()
    {
        // Создаем настройки по умолчанию
        add_option($this->option_name, array(
            'deepl_api_key' => '',
            'deepl_api_url' => 'https://api-free.deepl.com/v2/translate',
            'translate_content' => true,
            'translate_title' => true,
            'translate_excerpt' => true,
            'post_status' => 'draft'
        ));
    }

    public function init()
    {
        // Проверяем, активен ли Polylang
        if (!function_exists('pll_languages_list')) {
            add_action('admin_notices', array($this, 'polylang_missing_notice'));
            return;
        }

        // Добавляем хуки только если Polylang активен
        add_action('admin_footer-edit.php', array($this, 'add_bulk_translate_button'));
        add_action('wp_ajax_bulk_translate_posts', array($this, 'handle_bulk_translate'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_admin_menu()
    {
        add_options_page(
            'Polylang Mass Translation',
            'Mass Translation',
            'manage_options',
            'polylang-mass-translation',
            array($this, 'options_page')
        );
    }

    public function settings_init()
    {
        register_setting('pmt_settings_group', $this->option_name, array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));

        add_settings_section(
            $this->option_name . '_section',
            'Настройки DeepL API',
            array($this, 'settings_section_callback'),
            $this->option_name
        );

        add_settings_field(
            'deepl_api_key',
            'DeepL API Key',
            array($this, 'deepl_api_key_render'),
            $this->option_name,
            $this->option_name . '_section'
        );

        add_settings_field(
            'deepl_api_url',
            'DeepL API URL',
            array($this, 'deepl_api_url_render'),
            $this->option_name,
            $this->option_name . '_section'
        );

        add_settings_field(
            'translate_options',
            'Что переводить',
            array($this, 'translate_options_render'),
            $this->option_name,
            $this->option_name . '_section'
        );

        add_settings_field(
            'post_status',
            'Статус созданных постов',
            array($this, 'post_status_render'),
            $this->option_name,
            $this->option_name . '_section'
        );
    }


    public function options_page()
    {
        ?>
        <div class="wrap">
            <h1>Polylang Mass Translation Settings</h1>

            <div class="notice notice-info">
                <p><strong>Инструкция:</strong></p>
                <ul>
                    <li>1. Получите API ключ DeepL на сайте <a href="https://www.deepl.com/pro-api"
                            target="_blank">deepl.com</a></li>
                    <li>2. Для бесплатного аккаунта используйте URL: https://api-free.deepl.com/v2/translate</li>
                    <li>3. Для Pro аккаунта используйте URL: https://api.deepl.com/v2/translate</li>
                </ul>
            </div>

            <?php
            // Отладочная информация
            if (WP_DEBUG) {
                echo '<div class="notice notice-warning"><p>DEBUG: Настройки загружены: ' . (get_option($this->option_name) ? 'ДА' : 'НЕТ') . '</p></div>';
            }
            ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('pmt_settings_group');
                do_settings_sections($this->option_name);
                submit_button('Сохранить настройки');
                ?>
            </form>
            </form>

            <div class="postbox" style="margin-top: 20px;">
                <h3 style="padding: 10px;">Тест API подключения</h3>
                <div style="padding: 10px;">
                    <button type="button" id="test-deepl-connection" class="button">Проверить подключение к DeepL</button>
                    <div id="test-result" style="margin-top: 10px;"></div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('#test-deepl-connection').click(function () {
                    var button = $(this);
                    var resultDiv = $('#test-result');

                    button.prop('disabled', true).text('Проверка...');
                    resultDiv.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_deepl_connection',
                            nonce: '<?php echo wp_create_nonce('test_deepl_nonce'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                resultDiv.html('<div class="notice notice-success inline"><p>✅ Подключение успешно! Доступные символы: ' + response.data.character_limit + '</p></div>');
                            } else {
                                resultDiv.html('<div class="notice notice-error inline"><p>❌ Ошибка: ' + response.data + '</p></div>');
                            }
                        },
                        error: function () {
                            resultDiv.html('<div class="notice notice-error inline"><p>❌ Ошибка соединения</p></div>');
                        },
                        complete: function () {
                            button.prop('disabled', false).text('Проверить подключение к DeepL');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function polylang_missing_notice()
    {
        echo '<div class="notice notice-error"><p>Polylang Mass Translation требует активации плагина Polylang.</p></div>';
    }

    public function enqueue_scripts($hook)
    {
        if ('edit.php' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'polylang-mass-translation',
            plugin_dir_url(__FILE__) . 'polylang-mass-translation.js',
            array('jquery'),
            '2.0',
            true
        );

        wp_localize_script('polylang-mass-translation', 'pmt_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bulk_translate_nonce'),
            'translating_text' => 'Создание переводов...',
            'success_text' => 'Переводы созданы успешно!',
            'error_text' => 'Произошла ошибка при создании переводов.'
        ));
    }

    public function add_bulk_translate_button()
    {
        global $post_type;

        // Проверяем, поддерживает ли тип поста мультиязычность
        if (!pll_is_translated_post_type($post_type)) {
            return;
        }

        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Добавляем кнопки в bulk actions
                $('<option>').val('bulk_translate').text('Создать переводы (копировать)').appendTo('select[name="action"]');
                $('<option>').val('bulk_translate').text('Создать переводы (копировать)').appendTo('select[name="action2"]');
                $('<option>').val('bulk_translate_deepl').text('Создать переводы (DeepL)').appendTo('select[name="action"]');
                $('<option>').val('bulk_translate_deepl').text('Создать переводы (DeepL)').appendTo('select[name="action2"]');

                // Добавляем отдельные кнопки
                var buttonsHtml = '<div style="margin-top: 5px;">' +
                    '<input type="button" id="bulk-translate-button" class="button action" value="Создать переводы (копировать)" style="margin-right: 5px;">' +
                    '<input type="button" id="bulk-translate-deepl-button" class="button action" value="Создать переводы (DeepL)" style="margin-right: 5px;">' +
                    '</div>';
                $('.tablenav.top .alignleft.actions').first().append(buttonsHtml);
            });
        </script>
        <?php
    }

    public function handle_bulk_translate()
    {
        // Проверяем nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bulk_translate_nonce')) {
            wp_die('Неверный nonce');
        }

        // Проверяем права доступа
        if (!current_user_can('edit_posts')) {
            wp_die('Недостаточно прав');
        }

        $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : array();
        $use_deepl = isset($_POST['use_deepl']) ? (bool) $_POST['use_deepl'] : false;

        if (empty($post_ids)) {
            wp_send_json_error('Не выбраны посты для перевода');
        }

        $results = array();
        $languages = pll_languages_list();

        foreach ($post_ids as $post_id) {
            $result = $this->create_translations_for_post($post_id, $languages, $use_deepl);
            $results[] = $result;
        }

        wp_send_json_success($results);
    }

    private function create_translations_for_post($post_id, $languages, $use_deepl = false)
    {
        $original_post = get_post($post_id);

        if (!$original_post) {
            return array('post_id' => $post_id, 'status' => 'error', 'message' => 'Пост не найден');
        }

        // Получаем язык исходного поста
        $original_language = pll_get_post_language($post_id);
        $created_translations = array();

        foreach ($languages as $language) {
            // Пропускаем язык исходного поста
            if ($language === $original_language) {
                continue;
            }

            // Проверяем, существует ли уже перевод
            $existing_translation = pll_get_post($post_id, $language);
            if ($existing_translation) {
                $created_translations[$language] = array(
                    'id' => $existing_translation,
                    'status' => 'exists'
                );
                continue;
            }

            // Создаем перевод
            $translation_id = $this->create_post_translation($original_post, $language, $use_deepl, $original_language);

            if ($translation_id) {
                // Связываем переводы
                pll_set_post_language($translation_id, $language);

                // Получаем все переводы исходного поста
                $translations = pll_get_post_translations($post_id);
                $translations[$language] = $translation_id;

                // Устанавливаем связи между всеми переводами
                pll_save_post_translations($translations);

                // Копируем таксономии
                $this->copy_post_taxonomies($original_post, $translation_id, $language, $use_deepl, $original_language);

                $created_translations[$language] = array(
                    'id' => $translation_id,
                    'status' => 'created'
                );
            } else {
                $created_translations[$language] = array(
                    'id' => null,
                    'status' => 'error'
                );
            }
        }

        return array(
            'post_id' => $post_id,
            'status' => 'success',
            'translations' => $created_translations
        );
    }

    private function create_post_translation($original_post, $target_language, $use_deepl = false, $source_language = 'auto')
    {
        $options = get_option($this->option_name);
        $postType = $original_post->post_type;




        // Подготавливаем данные для нового поста
        $post_data = array(
            'post_title' => $original_post->post_title,
            'post_name' => $original_post->post_name,
            'post_content' => $original_post->post_content,
            'post_excerpt' => $original_post->post_excerpt,
            'post_status' => $options['post_status'] ?? 'draft',
            'post_type' => $original_post->post_type,
            'post_author' => $original_post->post_author,
            'menu_order' => $original_post->menu_order,
            'comment_status' => $original_post->comment_status,
            'ping_status' => $original_post->ping_status,
        );

        $base_slug = $original_post->post_name . "-" . $target_language;

        // Если используем DeepL, переводим контент
        if ($use_deepl && !empty($options['deepl_api_key'])) {
            if ($options['translate_title'] ?? true) {
                $translated_title = $this->translate_with_deepl($original_post->post_title, $source_language, $target_language);
                if ($translated_title) {
                    $post_data['post_title'] = $translated_title;
                }
            }

            if ($options['translate_content'] ?? true) {
                $translated_content = $this->translate_with_deepl($original_post->post_content, $source_language, $target_language);
                if ($translated_content) {
                    $post_data['post_content'] = $translated_content;
                }
            }

            if (($options['translate_excerpt'] ?? true) && !empty($original_post->post_excerpt)) {
                $translated_excerpt = $this->translate_with_deepl($original_post->post_excerpt, $source_language, $target_language);
                if ($translated_excerpt) {
                    $post_data['post_excerpt'] = $translated_excerpt;
                }
            }

            $base_slug = $this->transliterate_to_slug($post_data['post_title'], $target_language);
        }

        // Создаем пост
        $translation_id = wp_insert_post($post_data);

        if (is_wp_error($translation_id)) {
            return false;
        }

        

        //Если товар
        if ($postType == 'product') {

            $product_id = $original_post->ID;
            $product_sku = get_post_meta($product_id, '_sku', true);
            $regular_price = get_post_meta($product_id, '_regular_price', true);
            $sale_price = get_post_meta($product_id, '_sale_price', true);
            $price = get_post_meta($product_id, '_price', true);
            $stock_status = get_post_meta($product_id, '_stock_status', true);
            $stock = get_post_meta($product_id, '_stock', true);
            $manage_stock = get_post_meta($product_id, '_manage_stock', true);
            $short_description = get_post_meta($product_id, '_short_description', true);


            if ($use_deepl && !empty($options['deepl_api_key'])) {

                $translated_short_description = $this->translate_with_deepl($short_description, $source_language, $target_language);
                $short_description = $translated_short_description;

            }


            // Update prices
            if ($regular_price !== '') {
                update_post_meta($translation_id, '_regular_price', $regular_price);
            }

            if ($sale_price !== '') {
                update_post_meta($translation_id, '_sale_price', $sale_price);
            }

            update_post_meta($translation_id, '_price', $price);
            update_post_meta($translation_id, '_sku', $product_sku);
            update_post_meta($translation_id, '_stock_status', $stock_status);
            update_post_meta($translation_id, '_stock', $stock);
            update_post_meta($translation_id, '_manage_stock', $manage_stock);
            update_post_meta($translation_id, '_short_description', $short_description);

            // Копируем и переводим атрибуты продукта
            if ($options['translate_attributes'] ?? true) {
                $this->copy_product_attributes($product_id, $translation_id, $use_deepl, $source_language, $target_language);
            }

            // Копируем и переводим вариации продукта
            if ($options['translate_variations'] ?? true) {
                $this->copy_product_variations($product_id, $translation_id, $use_deepl, $source_language, $target_language);
            }
            
            //$post_data['_regular_price'] = $regular_price;
        }

        /*if ($postType == 'ct_template') {

            $json_data = get_post_meta($original_post->ID, '_ct_builder_json', true);
            $ct_val = get_post_meta($original_post->ID, '_ct_template_categories', true);
            $ct_template_taxonomies = get_post_meta($original_post->ID, '_ct_template_taxonomies', true);
            $ct_template_post_of_parents = get_post_meta($original_post->ID, '_ct_template_post_of_parents', true);
            $ct_builder_shortcodes = get_post_meta($original_post->ID, '_ct_builder_shortcodes', true);

            update_post_meta($translation_id, '_ct_builder_json', $json_data);
            update_post_meta($translation_id, '_ct_template_categories', $ct_val);
            update_post_meta($translation_id, '_ct_template_tags', $ct_val);
            update_post_meta($translation_id, '_ct_template_custom_taxonomies', $ct_val);
            update_post_meta($translation_id, '_ct_template_archive_among_taxonomies', $ct_val);
            update_post_meta($translation_id, '_ct_template_archive_post_types', $ct_val);
            update_post_meta($translation_id, '_ct_template_authors_archives', $ct_val);
            update_post_meta($translation_id, '_ct_template_post_types', $ct_val);
            update_post_meta($translation_id, '_ct_template_taxonomies', $ct_template_taxonomies);
            update_post_meta($translation_id, '_ct_template_post_of_parents', $ct_template_post_of_parents);
            update_post_meta($translation_id, '_ct_builder_shortcodes', $ct_builder_shortcodes);
            update_post_meta($translation_id, '_ct_template_order', 0);


        }*/

        $unique_slug = $this->make_slug_unique($base_slug, $original_post->post_type);

        // Обновляем пост с новым slug
        wp_update_post(array(
            'ID' => $translation_id,
            'post_name' => $unique_slug
        ));

        // Копируем произвольные поля
        $this->copy_post_meta($original_post->ID, $translation_id);

        // Копируем миниатюру
        $thumbnail_id = get_post_thumbnail_id($original_post->ID);
        if ($thumbnail_id) {
            set_post_thumbnail($translation_id, $thumbnail_id);
        }

        if (class_exists('RankMath')) {
            $rank_math_focus_keyword = get_post_meta($original_post->ID, 'rank_math_focus_keyword', true);
            $rank_math_title = get_post_meta($original_post->ID, 'rank_math_title', true);
            $rank_math_description = get_post_meta($original_post->ID, 'rank_math_description', true);

            update_post_meta($translation_id, 'rank_math_focus_keyword', $rank_math_focus_keyword);
            update_post_meta($translation_id, 'rank_math_title', $rank_math_title);
            update_post_meta($translation_id, 'rank_math_description', $rank_math_description);

            if ($use_deepl && !empty($options['deepl_api_key'])) {

                

                $translated_rank_math_focus_keyword = $this->translate_with_deepl($rank_math_focus_keyword, $source_language, $target_language);
                update_post_meta($translation_id, 'rank_math_focus_keyword', $translated_rank_math_focus_keyword);

                $translated_rank_math_title = $this->translate_with_deepl($rank_math_title, $source_language, $target_language);
                update_post_meta($translation_id, 'rank_math_title', $translated_rank_math_title);

                $translated_rank_math_description = $this->translate_with_deepl($rank_math_description, $source_language, $target_language);
                update_post_meta($translation_id, 'rank_math_description', $translated_rank_math_description);


                $text = $translation_id.' - ' . $rank_math_title . ' - ' . $translated_rank_math_title;
                $this->toLog($text);

            }

        }

        return $translation_id;
    }

    /**
     * Копирование атрибутов продукта с переводом
     */
    private function copy_product_attributes($original_product_id, $translation_id, $use_deepl = false, $source_language = 'auto', $target_language = '')
    {
        $product_attributes = get_post_meta($original_product_id, '_product_attributes', true);

        if (!$product_attributes || !is_array($product_attributes)) {
            return;
        }

        $translated_attributes = array();

        foreach ($product_attributes as $attribute_name => $attribute_data) {
            $translated_attribute_data = $attribute_data;

            // Für globale Attribute (pa_*)
            if (strpos($attribute_name, 'pa_') === 0) {
                $taxonomy = $attribute_name;

                // Stelle sicher, dass die Taxonomie existiert
                if (taxonomy_exists($taxonomy)) {
                    $this->sync_global_attribute_terms($original_product_id, $translation_id, $taxonomy, $target_language, $use_deepl, $source_language);
                }
            } else {
                // Für lokale Attribute
                if ($use_deepl && !empty($attribute_data['name'])) {
                    $translated_name = $this->translate_with_deepl($attribute_data['name'], $source_language, $target_language);
                    if ($translated_name) {
                        $translated_attribute_data['name'] = $translated_name;
                    }
                }

                // Übersetze Attributwerte
                if (!empty($attribute_data['value'])) {
                    $attribute_values = explode(' | ', $attribute_data['value']);
                    $translated_values = array();

                    foreach ($attribute_values as $value) {
                        if ($use_deepl) {
                            $translated_value = $this->translate_with_deepl($value, $source_language, $target_language);
                            $translated_values[] = $translated_value ? $translated_value : $value;
                        } else {
                            $translated_values[] = $value;
                        }
                    }

                    $translated_attribute_data['value'] = implode(' | ', $translated_values);
                }
            }

            $translated_attributes[$attribute_name] = $translated_attribute_data;
        }

        update_post_meta($translation_id, '_product_attributes', $translated_attributes);

        // WICHTIG: Synchronisiere mit existierenden Variationen
        $this->sync_variations_with_attributes($translation_id);
    }

    private function sync_global_attribute_terms($original_product_id, $translation_id, $taxonomy, $target_language, $use_deepl = false, $source_language = 'auto')
    {
        // Hole Original-Terme
        $original_terms = get_the_terms($original_product_id, $taxonomy);

        if (!$original_terms || is_wp_error($original_terms)) {
            return;
        }

        $translated_term_ids = array();

        foreach ($original_terms as $term) {
            // Prüfe auf existierende Übersetzung
            $translated_term_id = pll_get_term($term->term_id, $target_language);

            if ($translated_term_id) {
                $translated_term_ids[] = $translated_term_id;
            } else {
                // Erstelle neue Übersetzung
                $new_term_id = $this->create_translated_attribute_term($term, $taxonomy, $target_language, $use_deepl, $source_language);
                if ($new_term_id) {
                    $translated_term_ids[] = $new_term_id;
                }
            }
        }

        // Setze die übersetzten Terme für das Produkt
        if (!empty($translated_term_ids)) {
            wp_set_object_terms($translation_id, $translated_term_ids, $taxonomy);
        }
    }

    /**
     * Копирование вариаций продукта с переводом
     */
    private function copy_product_variations($original_product_id, $translation_id, $use_deepl = false, $source_language = 'auto', $target_language = '')
    {
        // Получаем все вариации исходного продукта
        $variations = get_children(array(
            'post_parent' => $original_product_id,
            'post_type' => 'product_variation',
            'numberposts' => -1,
            'post_status' => 'any'
        ));

        if (!$variations) {
            return;
        }

        foreach ($variations as $variation) {
            $this->create_variation_translation($variation, $translation_id, $use_deepl, $source_language, $target_language);
        }
    }

    /**
     * Создание перевода вариации продукта
     */
    private function create_variation_translation($original_variation, $parent_translation_id, $use_deepl = false, $source_language = 'auto', $target_language = '')
    {
        $options = get_option($this->option_name);

        // Подготавливаем данные для новой вариации
        $variation_data = array(
            'post_title' => $original_variation->post_title,
            'post_content' => $original_variation->post_content,
            'post_excerpt' => $original_variation->post_excerpt,
            'post_status' => $original_variation->post_status,
            'post_type' => 'product_variation',
            'post_parent' => $parent_translation_id,
            'menu_order' => $original_variation->menu_order
        );

        // Переводим содержимое вариации, если включено
        if ($use_deepl && !empty($options['deepl_api_key'])) {
            if (!empty($original_variation->post_content)) {
                $translated_content = $this->translate_with_deepl($original_variation->post_content, $source_language, $target_language);
                if ($translated_content) {
                    $variation_data['post_content'] = $translated_content;
                }
            }

            if (!empty($original_variation->post_excerpt)) {
                $translated_excerpt = $this->translate_with_deepl($original_variation->post_excerpt, $source_language, $target_language);
                if ($translated_excerpt) {
                    $variation_data['post_excerpt'] = $translated_excerpt;
                }
            }
        }

        // Создаем вариацию
        $variation_translation_id = wp_insert_post($variation_data);

        if (is_wp_error($variation_translation_id)) {
            return false;
        }

        // Копируем все метаданные вариации
        $this->copy_variation_meta($original_variation->ID, $variation_translation_id, $use_deepl, $source_language, $target_language);

        // Устанавливаем язык для вариации
        pll_set_post_language($variation_translation_id, $target_language);

        // Связываем переводы вариаций
        $variation_translations = pll_get_post_translations($original_variation->ID);
        $variation_translations[$target_language] = $variation_translation_id;
        pll_save_post_translations($variation_translations);

        return $variation_translation_id;
    }

    /**
     * Копирование метаданных вариации
     */
    private function copy_variation_meta($original_variation_id, $variation_translation_id, $use_deepl = false, $source_language = 'auto', $target_language = '')
    {
        // Основные метаданные вариации для копирования
        $meta_keys_to_copy = array(
            '_regular_price',
            '_sale_price',
            '_price',
            '_sku',
            '_stock_status',
            '_stock',
            '_manage_stock',
            '_weight',
            '_length',
            '_width',
            '_height',
            '_download_limit',
            '_download_expiry',
            '_downloadable',
            '_virtual',
            '_sold_individually',
            '_purchase_note',
            '_variation_description'
        );

        foreach ($meta_keys_to_copy as $meta_key) {
            $meta_value = get_post_meta($original_variation_id, $meta_key, true);

            if ($meta_value !== '') {
                // Переводим описание вариации, если включено
                if ($meta_key === '_variation_description' && $use_deepl && !empty($meta_value)) {
                    $translated_description = $this->translate_with_deepl($meta_value, $source_language, $target_language);
                    $meta_value = $translated_description ? $translated_description : $meta_value;
                }

                // Переводим заметку о покупке, если включено
                if ($meta_key === '_purchase_note' && $use_deepl && !empty($meta_value)) {
                    $translated_note = $this->translate_with_deepl($meta_value, $source_language, $target_language);
                    $meta_value = $translated_note ? $translated_note : $meta_value;
                }

                update_post_meta($variation_translation_id, $meta_key, $meta_value);
            }
        }

        // Копируем атрибуты вариации
        $this->copy_variation_attributes($original_variation_id, $variation_translation_id, $target_language);

        // Копируем файлы для загрузки
        $downloadable_files = get_post_meta($original_variation_id, '_downloadable_files', true);
        if ($downloadable_files && is_array($downloadable_files)) {
            $translated_files = array();

            foreach ($downloadable_files as $file_id => $file_data) {
                $translated_file_data = $file_data;

                // Переводим название файла, если включено
                if ($use_deepl && !empty($file_data['name'])) {
                    $translated_name = $this->translate_with_deepl($file_data['name'], $source_language, $target_language);
                    if ($translated_name) {
                        $translated_file_data['name'] = $translated_name;
                    }
                }

                $translated_files[$file_id] = $translated_file_data;
            }

            update_post_meta($variation_translation_id, '_downloadable_files', $translated_files);
        }

        // Копируем изображение вариации
        $variation_image_id = get_post_meta($original_variation_id, '_thumbnail_id', true);
        if ($variation_image_id) {
            update_post_meta($variation_translation_id, '_thumbnail_id', $variation_image_id);
        }
    }

    /**
     * Копирование атрибутов вариации
     */
    private function copy_variation_attributes($original_variation_id, $variation_translation_id, $target_language)
    {
        global $wpdb;

        // Hole Parent-Produkt IDs
        $original_parent = wp_get_post_parent_id($original_variation_id);
        $translated_parent = wp_get_post_parent_id($variation_translation_id);

        if (!$original_parent || !$translated_parent) {
            return;
        }

        // Hole Variation-Attribute
        $attributes = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id = %d 
            AND meta_key LIKE 'attribute_%'",
            $original_variation_id
        ));

        foreach ($attributes as $attribute) {
            $attribute_name = str_replace('attribute_', '', $attribute->meta_key);
            $attribute_value = $attribute->meta_value;

            // Für globale Attribute
            if (strpos($attribute_name, 'pa_') === 0) {
                $taxonomy = $attribute_name;

                // Finde den übersetzten Term
                $original_term = get_term_by('slug', $attribute_value, $taxonomy);
                if ($original_term) {
                    $translated_term_id = pll_get_term($original_term->term_id, $target_language);
                    if ($translated_term_id) {
                        $translated_term = get_term($translated_term_id, $taxonomy);
                        if ($translated_term && !is_wp_error($translated_term)) {
                            $attribute_value = $translated_term->slug;
                        }
                    }
                }
            } else {
                // Für lokale Attribute: Suche im übersetzten Parent-Produkt
                $parent_attributes = get_post_meta($translated_parent, '_product_attributes', true);
                if (isset($parent_attributes[$attribute_name]) && !empty($parent_attributes[$attribute_name]['value'])) {
                    $parent_values = explode(' | ', $parent_attributes[$attribute_name]['value']);
                    $original_parent_values = explode(' | ', get_post_meta($original_parent, '_product_attributes', true)[$attribute_name]['value'] ?? '');

                    // Finde entsprechenden übersetzten Wert
                    $value_index = array_search($attribute_value, $original_parent_values);
                    if ($value_index !== false && isset($parent_values[$value_index])) {
                        $attribute_value = $parent_values[$value_index];
                    }
                }
            }

            update_post_meta($variation_translation_id, $attribute->meta_key, $attribute_value);
        }

        // Wichtig: Cache leeren
        wc_delete_product_transients($translated_parent);
    }

    /**
     * Создание или получение перевода глобального атрибута
     */
    private function get_or_create_translated_attribute($attribute_name, $target_language, $use_deepl = false, $source_language = 'auto')
    {
        // Проверяем, существует ли уже перевод атрибута
        $translated_attribute = pll_get_term_by('slug', $attribute_name, 'pa_' . $attribute_name, $target_language);

        if ($translated_attribute) {
            return $translated_attribute;
        }

        // Если перевода нет, создаем новый глобальный атрибут
        $original_attribute = wc_get_attribute_taxonomy_name($attribute_name);

        if (!$original_attribute || !taxonomy_exists($original_attribute)) {
            return false;
        }

        // Получаем данные оригинального атрибута
        global $wpdb;
        $attribute_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
            $attribute_name
        ));

        if (!$attribute_data) {
            return false;
        }

        $translated_label = $attribute_data->attribute_label;

        // Переводим метку атрибута
        if ($use_deepl) {
            $translated_label = $this->translate_with_deepl($attribute_data->attribute_label, $source_language, $target_language);
            if (!$translated_label) {
                $translated_label = $attribute_data->attribute_label;
            }
        }

        // Создаем новый атрибут (это требует особой обработки в WooCommerce)
        $new_attribute_name = $attribute_name . '_' . $target_language;

        $insert_result = $wpdb->insert(
            $wpdb->prefix . 'woocommerce_attribute_taxonomies',
            array(
                'attribute_name' => $new_attribute_name,
                'attribute_label' => $translated_label,
                'attribute_type' => $attribute_data->attribute_type,
                'attribute_orderby' => $attribute_data->attribute_orderby,
                'attribute_public' => $attribute_data->attribute_public
            ),
            array('%s', '%s', '%s', '%s', '%d')
        );

        if ($insert_result) {
            // Очищаем кэш атрибутов
            delete_transient('wc_attribute_taxonomies');

            // Регистрируем новую таксономию
            $taxonomy_name = wc_attribute_taxonomy_name($new_attribute_name);
            register_taxonomy($taxonomy_name, 'product', array(
                'labels' => array('name' => $translated_label),
                'hierarchical' => true,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
            ));

            return $taxonomy_name;
        }

        return false;
    }

    // Zusätzliche Helper-Methoden
    private function create_translated_attribute_term($original_term, $taxonomy, $target_language, $use_deepl = false, $source_language = 'auto')
    {
        $term_name = $original_term->name;
        $term_description = $original_term->description;

        if ($use_deepl) {
            $translated_name = $this->translate_with_deepl($original_term->name, $source_language, $target_language);
            if ($translated_name) {
                $term_name = $translated_name;
            }

            if (!empty($original_term->description)) {
                $translated_description = $this->translate_with_deepl($original_term->description, $source_language, $target_language);
                if ($translated_description) {
                    $term_description = $translated_description;
                }
            }
        }

        $term_slug = $this->transliterate_to_slug($term_name, $target_language);
        $unique_slug = $this->make_term_slug_unique($term_slug, $taxonomy);

        $new_term = wp_insert_term(
            $term_name,
            $taxonomy,
            array(
                'description' => $term_description,
                'slug' => $unique_slug,
                'parent' => $this->get_translated_parent_term($original_term->parent, $taxonomy, $target_language, $use_deepl, $source_language)
            )
        );

        if (!is_wp_error($new_term)) {
            pll_set_term_language($new_term['term_id'], $target_language);

            $term_translations = pll_get_term_translations($original_term->term_id);
            $term_translations[$target_language] = $new_term['term_id'];
            pll_save_term_translations($term_translations);

            return $new_term['term_id'];
        }

        return false;
    }

    private function sync_variations_with_attributes($product_id)
    {
        // Force WooCommerce to resync variation attributes
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            // Lösche den Cache
            wc_delete_product_transients($product_id);

            // Synchronisiere Variationen
            WC_Product_Variable::sync_attributes($product_id);
        }
    }
    private function translate_with_deepl($text, $source_lang, $target_lang)
    {
        $options = get_option($this->option_name);
        $api_key = $options['deepl_api_key'] ?? '';
        $api_url = $options['deepl_api_url'] ?? 'https://api-free.deepl.com/v2/translate';

        if (empty($api_key) || empty($text)) {
            return false;
        }

        // Удаляем HTML теги для лучшего перевода, но сохраняем их структуру
        $clean_text = wp_strip_all_tags($text);
        if (empty(trim($clean_text))) {
            return $text; // Возвращаем оригинал, если только HTML
        }

        // Преобразуем коды языков Polylang в коды DeepL
        $deepl_source = $this->convert_to_deepl_lang($source_lang);
        $deepl_target = $this->convert_to_deepl_lang($target_lang);

        $body = array(
            'text' => $text,
            'source_lang' => $deepl_source,
            'target_lang' => $deepl_target,
            'tag_handling' => 'html',
            'preserve_formatting' => '1'
        );

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => http_build_query($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['translations'][0]['text'])) {
            return $data['translations'][0]['text'];
        }

        return false;
    }

    private function convert_to_deepl_lang($polylang_code)
    {
        $lang_map = array(
            'en' => 'EN',
            'de' => 'DE',
            'fr' => 'FR',
            'it' => 'IT',
            'ja' => 'JA',
            'es' => 'ES',
            'nl' => 'NL',
            'pl' => 'PL',
            'pt' => 'PT',
            'ru' => 'RU',
            'zh' => 'ZH',
            'bg' => 'BG',
            'cs' => 'CS',
            'da' => 'DA',
            'el' => 'EL',
            'et' => 'ET',
            'fi' => 'FI',
            'hu' => 'HU',
            'id' => 'ID',
            'lv' => 'LV',
            'lt' => 'LT',
            'ro' => 'RO',
            'sk' => 'SK',
            'sl' => 'SL',
            'sv' => 'SV',
            'tr' => 'TR',
            'uk' => 'UK'
        );

        return $lang_map[$polylang_code] ?? strtoupper($polylang_code);
    }

    private function transliterate_to_slug($text, $language = '')
    {
        // Приводим к нижнему регистру
        $text = mb_strtolower($text, 'UTF-8');

        // Специальные символы для разных языков
        $transliteration_map = array(
            // Русский
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ё' => 'yo',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'kh',
            'ц' => 'ts',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sch',
            'ь' => '',
            'ы' => 'y',
            'ъ' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',

            // Украинский
            'і' => 'i',
            'ї' => 'yi',
            'є' => 'ye',
            'ґ' => 'g',

            // Немецкий
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',

            // Французский
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
            'ñ' => 'n',
            'ç' => 'c',

            // Польский
            'ą' => 'a',
            'ć' => 'c',
            'ę' => 'e',
            'ł' => 'l',
            'ń' => 'n',
            'ó' => 'o',
            'ś' => 's',
            'ź' => 'z',
            'ż' => 'z',

            // Чешский и словацкий
            'č' => 'c',
            'ď' => 'd',
            'ě' => 'e',
            'ň' => 'n',
            'ř' => 'r',
            'š' => 's',
            'ť' => 't',
            'ů' => 'u',
            'ž' => 'z',

            // Литовский
            'ą' => 'a',
            'č' => 'c',
            'ę' => 'e',
            'ė' => 'e',
            'į' => 'i',
            'š' => 's',
            'ų' => 'u',
            'ū' => 'u',
            'ž' => 'z',

            // Латышский
            'ā' => 'a',
            'č' => 'c',
            'ē' => 'e',
            'ģ' => 'g',
            'ī' => 'i',
            'ķ' => 'k',
            'ļ' => 'l',
            'ņ' => 'n',
            'š' => 's',
            'ū' => 'u',
            'ž' => 'z',

            // Эстонский
            'ä' => 'a',
            'ö' => 'o',
            'ü' => 'u',
            'õ' => 'o',

            // Венгерский
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ö' => 'o',
            'ő' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ű' => 'u',

            // Румынский
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ț' => 't',

            // Болгарский
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'h',
            'ц' => 'c',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sht',
            'ъ' => 'a',
            'ь' => 'y',
            'ю' => 'yu',
            'я' => 'ya',

            // Греческий
            'α' => 'a',
            'β' => 'v',
            'γ' => 'g',
            'δ' => 'd',
            'ε' => 'e',
            'ζ' => 'z',
            'η' => 'i',
            'θ' => 'th',
            'ι' => 'i',
            'κ' => 'k',
            'λ' => 'l',
            'μ' => 'm',
            'ν' => 'n',
            'ξ' => 'x',
            'ο' => 'o',
            'π' => 'p',
            'ρ' => 'r',
            'σ' => 's',
            'ς' => 's',
            'τ' => 't',
            'υ' => 'y',
            'φ' => 'f',
            'χ' => 'ch',
            'ψ' => 'ps',
            'ω' => 'o',

            // Турецкий
            'ç' => 'c',
            'ğ' => 'g',
            'ı' => 'i',
            'ö' => 'o',
            'ş' => 's',
            'ü' => 'u',

            // Арабский (базовые символы)
            'ا' => 'a',
            'ب' => 'b',
            'ت' => 't',
            'ث' => 'th',
            'ج' => 'j',
            'ح' => 'h',
            'خ' => 'kh',
            'د' => 'd',
            'ذ' => 'dh',
            'ر' => 'r',
            'ز' => 'z',
            'س' => 's',
            'ش' => 'sh',
            'ص' => 's',
            'ض' => 'd',
            'ط' => 't',
            'ظ' => 'z',
            'ع' => 'a',
            'غ' => 'gh',
            'ف' => 'f',
            'ق' => 'q',
            'ك' => 'k',
            'ل' => 'l',
            'م' => 'm',
            'ن' => 'n',
            'ه' => 'h',
            'و' => 'w',
            'ي' => 'y',

            // Китайский (пиньин) - основные тона
            'ā' => 'a',
            'á' => 'a',
            'ǎ' => 'a',
            'à' => 'a',
            'ē' => 'e',
            'é' => 'e',
            'ě' => 'e',
            'è' => 'e',
            'ī' => 'i',
            'í' => 'i',
            'ǐ' => 'i',
            'ì' => 'i',
            'ō' => 'o',
            'ó' => 'o',
            'ǒ' => 'o',
            'ò' => 'o',
            'ū' => 'u',
            'ú' => 'u',
            'ǔ' => 'u',
            'ù' => 'u',
            'ü' => 'v',
            'ǖ' => 'v',
            'ǘ' => 'v',
            'ǚ' => 'v',
            'ǜ' => 'v',

            // Японский (хирагана основные)
            'あ' => 'a',
            'い' => 'i',
            'う' => 'u',
            'え' => 'e',
            'お' => 'o',
            'か' => 'ka',
            'き' => 'ki',
            'く' => 'ku',
            'け' => 'ke',
            'こ' => 'ko',
            'さ' => 'sa',
            'し' => 'shi',
            'す' => 'su',
            'せ' => 'se',
            'そ' => 'so',
            'た' => 'ta',
            'ち' => 'chi',
            'つ' => 'tsu',
            'て' => 'te',
            'と' => 'to',
            'な' => 'na',
            'に' => 'ni',
            'ぬ' => 'nu',
            'ね' => 'ne',
            'の' => 'no',
            'は' => 'ha',
            'ひ' => 'hi',
            'ふ' => 'fu',
            'へ' => 'he',
            'ほ' => 'ho',
            'ま' => 'ma',
            'み' => 'mi',
            'む' => 'mu',
            'め' => 'me',
            'も' => 'mo',
            'や' => 'ya',
            'ゆ' => 'yu',
            'よ' => 'yo',
            'ら' => 'ra',
            'り' => 'ri',
            'る' => 'ru',
            'れ' => 're',
            'ろ' => 'ro',
            'わ' => 'wa',
            'ゐ' => 'wi',
            'ゑ' => 'we',
            'を' => 'wo',
            'ん' => 'n'
        );

        // Применяем транслитерацию
        $text = strtr($text, $transliteration_map);

        // Удаляем HTML теги
        $text = strip_tags($text);

        // Заменяем все не-буквенно-цифровые символы на дефисы
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        // Убираем дефисы в начале и конце
        $text = trim($text, '-');

        // Убираем множественные дефисы
        $text = preg_replace('/-+/', '-', $text);

        // Ограничиваем длину до 200 символов
        if (strlen($text) > 200) {
            $text = substr($text, 0, 200);
            // Обрезаем по последнему дефису, чтобы не разорвать слово
            $last_dash = strrpos($text, '-');
            if ($last_dash !== false) {
                $text = substr($text, 0, $last_dash);
            }
        }

        // Если результат пустой, создаем случайный slug
        if (empty($text)) {
            $text = 'post-' . wp_generate_password(8, false);
        }

        return $text;
    }

    private function make_slug_unique($slug, $post_type)
    {
        global $wpdb;

        $original_slug = $slug;
        $counter = 1;

        // Проверяем, существует ли slug
        while ($this->slug_exists($slug, $post_type)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;

            // Предотвращаем бесконечный цикл
            if ($counter > 1000) {
                $slug = $original_slug . '-' . wp_generate_password(5, false);
                break;
            }
        }

        return $slug;
    }

    private function slug_exists($slug, $post_type)
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status != 'trash'",
            $slug,
            $post_type
        ));

        return !empty($result);
    }

    private function make_term_slug_unique($slug, $taxonomy)
    {
        $original_slug = $slug;
        $counter = 1;

        // Проверяем, существует ли slug термина
        while ($this->term_slug_exists($slug, $taxonomy)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;

            // Предотвращаем бесконечный цикл
            if ($counter > 1000) {
                $slug = $original_slug . '-' . wp_generate_password(5, false);
                break;
            }
        }

        return $slug;
    }

    private function term_slug_exists($slug, $taxonomy)
    {
        $term = get_term_by('slug', $slug, $taxonomy);
        return !empty($term);
    }

    private function get_translated_parent_term($parent_term_id, $taxonomy, $language, $use_deepl = false, $source_language = 'auto')
    {
        if (empty($parent_term_id)) {
            return 0;
        }

        // Получаем перевод родительского термина
        $translated_parent_id = pll_get_term($parent_term_id, $language);

        if ($translated_parent_id) {
            return $translated_parent_id;
        }

        // Если перевода родительского термина нет, создаем его
        $parent_term = get_term($parent_term_id, $taxonomy);

        if (is_wp_error($parent_term) || !$parent_term) {
            return 0;
        }

        $parent_name = $parent_term->name;
        $parent_description = $parent_term->description;

        // Переводим родительский термин через DeepL, если включено
        if ($use_deepl) {
            $translated_parent_name = $this->translate_with_deepl($parent_term->name, $source_language, $language);
            if ($translated_parent_name) {
                $parent_name = $translated_parent_name;
            }

            if (!empty($parent_term->description)) {
                $translated_parent_description = $this->translate_with_deepl($parent_term->description, $source_language, $language);
                if ($translated_parent_description) {
                    $parent_description = $translated_parent_description;
                }
            }
        }

        // Создаем slug для родительского термина
        $parent_slug = $this->transliterate_to_slug($parent_name, $language);
        $unique_parent_slug = $this->make_term_slug_unique($parent_slug, $taxonomy);

        $new_parent_term = wp_insert_term(
            $parent_name,
            $taxonomy,
            array(
                'description' => $parent_description,
                'slug' => $unique_parent_slug,
                'parent' => $this->get_translated_parent_term($parent_term->parent, $taxonomy, $language, $use_deepl, $source_language)
            )
        );

        if (!is_wp_error($new_parent_term)) {
            // Устанавливаем язык для нового родительского термина
            pll_set_term_language($new_parent_term['term_id'], $language);

            // Связываем переводы терминов
            $parent_translations = pll_get_term_translations($parent_term_id);
            $parent_translations[$language] = $new_parent_term['term_id'];
            pll_save_term_translations($parent_translations);

            return $new_parent_term['term_id'];
        }

        return 0;
    }

    // Остальные методы остаются без изменений
    private function copy_post_meta($original_id, $translation_id)
    {
        $meta_keys = get_post_custom_keys($original_id);

        if (!$meta_keys) {
            return;
        }

        foreach ($meta_keys as $meta_key) {
            // Пропускаем служебные мета-поля
            if (substr($meta_key, 0, 1) === '_' && !in_array($meta_key, array('_thumbnail_id'))) {
                continue;
            }

            $meta_values = get_post_custom_values($meta_key, $original_id);
            foreach ($meta_values as $meta_value) {
                add_post_meta($translation_id, $meta_key, $meta_value);
            }
        }
    }

    private function copy_post_taxonomies($original_post, $translation_id, $language, $use_deepl = false, $source_language = 'auto')
    {
        $taxonomies = get_object_taxonomies($original_post->post_type);

        foreach ($taxonomies as $taxonomy) {
            // Пропускаем таксономии языков и переводов Polylang
            if (in_array($taxonomy, array('language', 'post_translations'))) {
                continue;
            }

            // Проверяем, поддерживает ли таксономия мультиязычность
            if (!pll_is_translated_taxonomy($taxonomy)) {
                // Если таксономия не переводится, просто копируем термины
                $terms = wp_get_post_terms($original_post->ID, $taxonomy, array('fields' => 'ids'));
                if (!empty($terms) && !is_wp_error($terms)) {
                    wp_set_post_terms($translation_id, $terms, $taxonomy);
                }
                continue;
            }

            // Получаем термины исходного поста
            $original_terms = wp_get_post_terms($original_post->ID, $taxonomy);

            if (empty($original_terms) || is_wp_error($original_terms)) {
                continue;
            }

            $translated_term_ids = array();

            foreach ($original_terms as $term) {
                // Получаем перевод термина
                $translated_term_id = pll_get_term($term->term_id, $language);

                if ($translated_term_id) {
                    $translated_term_ids[] = $translated_term_id;
                } else {
                    // Если перевода термина нет, создаем его
                    $term_name = $term->name;
                    $term_description = $term->description;

                    // Переводим название и описание термина через DeepL, если включено
                    if ($use_deepl) {
                        $translated_name = $this->translate_with_deepl($term->name, $source_language, $language);
                        if ($translated_name) {
                            $term_name = $translated_name;
                        }

                        if (!empty($term->description)) {
                            $translated_description = $this->translate_with_deepl($term->description, $source_language, $language);
                            if ($translated_description) {
                                $term_description = $translated_description;
                            }
                        }
                    }

                    // Создаем slug для нового термина
                    $term_slug = $term->slug . "-" . $language;



                    if ($use_deepl) {
                        $term_slug = $this->transliterate_to_slug($term_name, $language);
                    }

                    $unique_term_slug = $this->make_term_slug_unique($term_slug, $taxonomy);

                    $new_term = wp_insert_term(
                        $term_name,
                        $taxonomy,
                        array(
                            'description' => $term_description,
                            'slug' => $unique_term_slug,
                            'parent' => $this->get_translated_parent_term($term->parent, $taxonomy, $language, $use_deepl, $source_language)
                        )
                    );

                    if (!is_wp_error($new_term)) {
                        // Устанавливаем язык для нового термина
                        pll_set_term_language($new_term['term_id'], $language);

                        // Связываем переводы терминов
                        $term_translations = pll_get_term_translations($term->term_id);
                        $term_translations[$language] = $new_term['term_id'];
                        pll_save_term_translations($term_translations);

                        $translated_term_ids[] = $new_term['term_id'];
                    }
                }
            }

            // Назначаем переведенные термины посту
            if (!empty($translated_term_ids)) {
                wp_set_post_terms($translation_id, $translated_term_ids, $taxonomy);
            }
        }
    }
}

// Добавляем AJAX обработчик для теста подключения
add_action('wp_ajax_test_deepl_connection', function () {
    if (!wp_verify_nonce($_POST['nonce'], 'test_deepl_nonce')) {
        wp_send_json_error('Неверный nonce');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Недостаточно прав');
    }

    $options = get_option('pmt_settings');
    $api_key = $options['deepl_api_key'] ?? '';
    $api_url = str_replace('/translate', '/usage', $options['deepl_api_url'] ?? 'https://api-free.deepl.com/v2/translate');

    if (empty($api_key)) {
        wp_send_json_error('API ключ не указан');
    }

    $response = wp_remote_get($api_url, array(
        'headers' => array(
            'Authorization' => 'DeepL-Auth-Key ' . $api_key,
        ),
        'timeout' => 10
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('Ошибка соединения: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['character_limit'])) {
        wp_send_json_success($data);
    } else {
        wp_send_json_error('Неверный API ключ или ошибка API');
    }
});

// Инициализируем плагин
new PolylangMassTranslation();

// Создаем обновленный JavaScript файл
if (!file_exists(plugin_dir_path(__FILE__) . 'polylang-mass-translation.js')) {
    $js_content = "
jQuery(document).ready(function($) {
    // Обработчик клика по кнопке массового перевода (копирование)
    $(document).on('click', '#bulk-translate-button', function(e) {
        e.preventDefault();
        handleBulkTranslate(0);
    });
    
    // Обработчик клика по кнопке массового перевода (DeepL)
    $(document).on('click', '#bulk-translate-deepl-button', function(e) {
        e.preventDefault();
        handleBulkTranslate(1);
    });
    
    function handleBulkTranslate(useDeepL) {
        var checkedPosts = $('input[name=\"post[]\"]').filter(':checked');
        
        if (checkedPosts.length === 0) {
            alert('Пожалуйста, выберите посты для перевода');
            return;
        }
        
        var postIds = [];
        checkedPosts.each(function() {
            postIds.push($(this).val());
        });
        
        var button = useDeepL ? $('#bulk-translate-deepl-button') : $('#bulk-translate-button');
        var originalText = button.val();
        
        button.val(pmt_ajax.translating_text).prop('disabled', true);
        
        $.ajax({
            url: pmt_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bulk_translate_posts',
                post_ids: postIds,
                use_deepl: useDeepL,
                nonce: pmt_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(pmt_ajax.success_text);
                    location.reload();
                } else {
                    alert(pmt_ajax.error_text + ' ' + response.data);
                }
            },
            error: function() {
                alert(pmt_ajax.error_text);
            },
            complete: function() {
                button.val(originalText).prop('disabled', false);
            }
        });
    }
    
    // Обработчик для dropdown bulk actions
    $('.tablenav select[name=\"action\"], .tablenav select[name=\"action2\"]').change(function() {
        var selectedAction = $(this).val();
        if (selectedAction === 'bulk_translate' || selectedAction === 'bulk_translate_deepl') {
            $(this).closest('.tablenav').find('#doaction, #doaction2').click(function(e) {
                e.preventDefault();
                if (selectedAction === 'bulk_translate_deepl') {
                    $('#bulk-translate-deepl-button').click();
                } else {
                    $('#bulk-translate-button').click();
                }
            });
        }
    });
});
";

    file_put_contents(plugin_dir_path(__FILE__) . 'polylang-mass-translation.js', $js_content);
}
?>
