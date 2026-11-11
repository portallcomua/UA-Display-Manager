<?php
/**
 * Plugin Name: UA Display Manager
 * Plugin URI: https://uaserver.pp.ua
 * Description: Керування відображенням шорткодів, віджетів WordPress та окремих віджетів Elementor на вибраних сторінках.
 * Version: 1.0.1
 * Author: UA Tools
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UA_Display_Manager {

    const VERSION = '1.0.1';
    const OPTION = 'ua_display_manager_rules';
    const MENU = 'ua-display-manager';

    private $rules = null;

    public function __construct() {

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Shortcodes, including those executed by Elementor.
        add_filter(
            'pre_do_shortcode_tag',
            [$this, 'filter_shortcode'],
            10,
            4
        );

        // Classic WordPress widgets.
        add_filter(
            'widget_display_callback',
            [$this, 'filter_widget'],
            10,
            3
        );

        // Individual Elementor widgets.
        add_filter(
            'elementor/frontend/widget/should_render',
            [$this, 'filter_elementor_widget'],
            10,
            2
        );
    }

    /* -----------------------------------------
     * SETTINGS
     * -------------------------------------- */

    public function admin_menu() {

        add_menu_page(
            'UA Display Manager',
            'Display Manager',
            'manage_options',
            self::MENU,
            [$this, 'render_admin'],
            'dashicons-visibility',
            59
        );
    }

    public function register_settings() {

        register_setting(
            'ua_dm_settings_group',
            self::OPTION,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_rules'],
                'default' => [],
            ]
        );
    }

    public function sanitize_rules($input) {

        $result = [];

        if (!is_array($input)) {
            return $result;
        }

        foreach ($input as $key => $rule) {

            if (!is_string($key) || !is_array($rule)) {
                continue;
            }

            if (!preg_match(
                '/^(shortcode|widget|elementor):[a-zA-Z0-9_:\-]+$/',
                $key
            )) {
                continue;
            }

            $mode = isset($rule['mode'])
                ? sanitize_key($rule['mode'])
                : 'all';

            if (!in_array($mode, ['all', 'only', 'exclude'], true)) {
                $mode = 'all';
            }

            $pages = [];

            if (isset($rule['pages']) && is_array($rule['pages'])) {

                foreach ($rule['pages'] as $page_id) {

                    $page_id = absint($page_id);

                    if ($page_id && get_post($page_id)) {
                        $pages[] = $page_id;
                    }
                }
            }

            $result[$key] = [
                'mode' => $mode,
                'pages' => array_values(array_unique($pages)),
            ];
        }

        return $result;
    }

    private function get_rules() {

        if ($this->rules !== null) {
            return $this->rules;
        }

        $rules = get_option(self::OPTION, []);

        $this->rules = is_array($rules) ? $rules : [];

        return $this->rules;
    }

    private function get_rule($key) {

        $rules = $this->get_rules();

        return $rules[$key] ?? [
            'mode' => 'all',
            'pages' => [],
        ];
    }

    /* -----------------------------------------
     * VISIBILITY ENGINE
     * -------------------------------------- */

    private function is_allowed($key, $post_id = 0) {

        if (is_admin() || wp_doing_ajax()) {
            return true;
        }

        $rule = $this->get_rule($key);

        $mode = $rule['mode'] ?? 'all';
        $pages = isset($rule['pages']) && is_array($rule['pages'])
            ? array_map('intval', $rule['pages'])
            : [];

        if ($mode === 'all' || empty($pages)) {
            return true;
        }

        if (!$post_id) {
            $post_id = get_queried_object_id();
        }

        $selected = in_array(
            (int) $post_id,
            $pages,
            true
        );

        if ($mode === 'only') {
            return $selected;
        }

        if ($mode === 'exclude') {
            return !$selected;
        }

        return true;
    }

    /* -----------------------------------------
     * SHORTCODES
     * -------------------------------------- */

    public function filter_shortcode($return, $tag, $attr, $match) {

        $key = 'shortcode:' . sanitize_key($tag);

        if (!$this->is_allowed($key)) {
            return '';
        }

        return $return;
    }

    private function get_shortcodes() {

        global $shortcode_tags;

        $items = [];

        if (!is_array($shortcode_tags)) {
            return $items;
        }

        $tags = array_keys($shortcode_tags);

        sort($tags);

        foreach ($tags as $tag) {

            $items[] = [
                'key' => 'shortcode:' . sanitize_key($tag),
                'name' => '[' . $tag . ']',
                'type' => 'shortcode',
                'description' => 'Зареєстрований шорткод',
            ];
        }

        return $items;
    }

    /* -----------------------------------------
     * CLASSIC WORDPRESS WIDGETS
     * -------------------------------------- */

    public function filter_widget($instance, $widget, $args) {

        if (!is_object($widget) || empty($widget->id)) {
            return $instance;
        }

        $key = 'widget:' . sanitize_key($widget->id);

        if (!$this->is_allowed($key)) {
            return false;
        }

        return $instance;
    }

    private function get_widgets() {

        global $wp_widget_factory;

        $items = [];
        $sidebars = wp_get_sidebars_widgets();

        if (!is_array($sidebars)) {
            return $items;
        }

        foreach ($sidebars as $sidebar => $widget_ids) {

            if (!is_array($widget_ids)) {
                continue;
            }

            foreach ($widget_ids as $widget_id) {

                if (!is_string($widget_id)) {
                    continue;
                }

                if (!preg_match(
                    '/^(.+)-([0-9]+)$/',
                    $widget_id,
                    $matches
                )) {
                    continue;
                }

                $id_base = $matches[1];
                $widget_object = null;

                if (
                    isset($wp_widget_factory->widgets) &&
                    is_array($wp_widget_factory->widgets)
                ) {
                    foreach ($wp_widget_factory->widgets as $registered) {

                        if (
                            isset($registered->id_base) &&
                            $registered->id_base === $id_base
                        ) {
                            $widget_object = $registered;
                            break;
                        }
                    }
                }

                $name = $widget_object
                    ? $widget_object->name
                    : $id_base;

                $items[] = [
                    'key' => 'widget:' . sanitize_key($widget_id),
                    'name' => $name,
                    'type' => 'widget',
                    'description' => $widget_id . ' / ' . $sidebar,
                ];
            }
        }

        return $items;
    }

    /* -----------------------------------------
     * ELEMENTOR
     * -------------------------------------- */

    public function filter_elementor_widget($should_render, $widget) {

        if (!$should_render || is_admin() || wp_doing_ajax()) {
            return $should_render;
        }

        if (!is_object($widget)) {
            return $should_render;
        }

        if (
            !method_exists($widget, 'get_id') ||
            !method_exists($widget, 'get_name')
        ) {
            return $should_render;
        }

        $element_id = sanitize_key($widget->get_id());
        $post_id = get_queried_object_id();

        if (!$element_id || !$post_id) {
            return $should_render;
        }

        $key = 'elementor:' . absint($post_id) . ':' . $element_id;

        return $this->is_allowed($key, $post_id);
    }

    /**
     * Recursively extract Elementor widgets from page data.
     */
    private function parse_elementor_elements(
        $elements,
        $post_id,
        &$items
    ) {

        if (!is_array($elements)) {
            return;
        }

        foreach ($elements as $element) {

            if (!is_array($element)) {
                continue;
            }

            if (
                isset($element['elType']) &&
                $element['elType'] === 'widget' &&
                !empty($element['id']) &&
                !empty($element['widgetType'])
            ) {

                $element_id = sanitize_key($element['id']);
                $widget_type = sanitize_key($element['widgetType']);

                $key = 'elementor:' . absint($post_id) . ':' . $element_id;

                $items[] = [
                    'key' => $key,
                    'name' => $this->elementor_widget_label($widget_type),
                    'type' => 'elementor',
                    'description' =>
                        'Сторінка ID: ' . $post_id .
                        ' | Віджет: ' . $widget_type .
                        ' | Element ID: ' . $element_id,
                ];
            }

            if (!empty($element['elements'])) {
                $this->parse_elementor_elements(
                    $element['elements'],
                    $post_id,
                    $items
                );
            }
        }
    }

    private function elementor_widget_label($type) {

        $labels = [
            'shortcode' => 'Elementor: Shortcode',
            'html' => 'Elementor: HTML',
            'form' => 'Elementor: Form',
            'text-editor' => 'Elementor: Text Editor',
            'heading' => 'Elementor: Heading',
            'image' => 'Elementor: Image',
            'button' => 'Elementor: Button',
            'video' => 'Elementor: Video',
            'icon' => 'Elementor: Icon',
            'image-box' => 'Elementor: Image Box',
            'icon-box' => 'Elementor: Icon Box',
        ];

        return $labels[$type] ?? 'Elementor: ' . $type;
    }

    private function get_elementor_widgets() {

        if (!did_action('elementor/loaded')) {
            return [];
        }

        $items = [];

        $posts = get_posts([
            'post_type' => ['page', 'post', 'product'],
            'post_status' => ['publish', 'private'],
            'posts_per_page' => 500,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => '_elementor_data',
        ]);

        foreach ($posts as $post) {

            $data = get_post_meta(
                $post->ID,
                '_elementor_data',
                true
            );

            if (is_string($data)) {
                $data = json_decode($data, true);
            }

            if (!is_array($data)) {
                continue;
            }

            $this->parse_elementor_elements(
                $data,
                $post->ID,
                $items
            );
        }

        return $items;
    }

    /* -----------------------------------------
     * PAGE LIST
     * -------------------------------------- */

    private function get_available_pages() {

        $types = get_post_types(
            ['public' => true],
            'names'
        );

        unset($types['attachment']);

        $posts = get_posts([
            'post_type' => array_values($types),
            'post_status' => [
                'publish',
                'private',
                'draft',
            ],
            'posts_per_page' => 1000,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        return $posts;
    }

    /* -----------------------------------------
     * ADMIN ROW
     * -------------------------------------- */

    private function render_page_selector($key, $selected) {

        $posts = $this->get_available_pages();

        echo '<select multiple size="5" class="ua-dm-pages" ';
        echo 'name="' . esc_attr(self::OPTION) . '[' .
            esc_attr($key) . '][pages][]" ';
        echo 'style="width:100%;max-width:400px;">';

        foreach ($posts as $post) {

            $is_selected = in_array(
                (int) $post->ID,
                array_map('intval', (array) $selected),
                true
            );

            $title = $post->post_title
                ? $post->post_title
                : '(Без назви)';

            echo '<option value="' . esc_attr($post->ID) . '" ';
            selected($is_selected, true);
            echo '>';

            echo esc_html(
                '[' . $post->post_type . '] ' .
                $title .
                ' #' . $post->ID
            );

            echo '</option>';
        }

        echo '</select>';

        echo '<p class="description">';
        echo 'Вибір декількох: Ctrl (Windows) або Shift.';
        echo '</p>';
    }

    private function render_item($item) {

        $key = $item['key'];
        $rule = $this->get_rule($key);

        $mode = $rule['mode'] ?? 'all';
        $pages = $rule['pages'] ?? [];

        echo '<tr data-ua-dm-search="' .
            esc_attr(mb_strtolower(
                $item['name'] . ' ' .
                $item['description'] . ' ' .
                $key
            )) . '">';

        echo '<td>';
        echo '<strong>' . esc_html($item['name']) . '</strong>';
        echo '<br><small>' . esc_html($item['description']) . '</small>';
        echo '<br><code>' . esc_html($key) . '</code>';
        echo '</td>';

        echo '<td>';

        echo '<select name="' . esc_attr(self::OPTION) . '[' .
            esc_attr($key) . '][mode]" style="width:100%;">';

        echo '<option value="all" ' .
            selected($mode, 'all', false) .
            '>Всі сторінки</option>';

        echo '<option value="only" ' .
            selected($mode, 'only', false) .
            '>Тільки вибрані</option>';

        echo '<option value="exclude" ' .
            selected($mode, 'exclude', false) .
            '>Всі, крім вибраних</option>';

        echo '</select>';

        echo '</td>';

        echo '<td>';

        $this->render_page_selector($key, $pages);

        echo '</td>';

        echo '</tr>';
    }

    /* -----------------------------------------
     * ADMIN PAGE
     * -------------------------------------- */

    public function render_admin() {

        if (!current_user_can('manage_options')) {
            return;
        }

        $shortcodes = $this->get_shortcodes();
        $widgets = $this->get_widgets();
        $elementor = $this->get_elementor_widgets();

        echo '<div class="wrap ua-dm-wrap">';

        echo '<h1>UA Display Manager <small>v' .
            esc_html(self::VERSION) . '</small></h1>';

        echo '<p>Центральне керування відображенням об’єктів WordPress.</p>';

        echo '<div class="ua-dm-toolbar">';

        echo '<input type="search" id="ua-dm-search" ';
        echo 'placeholder="Знайти віджет або шорткод..." ';

        echo 'style="width:100%;max-width:400px;">';

        echo '<span id="ua-dm-counter"></span>';

        echo '</div>';

        echo '<form method="post" action="options.php">';

        settings_fields('ua_dm_settings_group');

        $this->render_section(
            'Шорткоди',
            'shortcodes',
            $shortcodes
        );

        $this->render_section(
            'Віджети WordPress',
            'widgets',
            $widgets
        );

        if (did_action('elementor/loaded')) {

            $this->render_section(
                'Віджети Elementor',
                'elementor',
                $elementor
            );

        } else {

            echo '<div class="notice notice-info inline">';
            echo '<p>Elementor не активний. Його інтеграція стане доступною після активації Elementor.</p>';
            echo '</div>';
        }

        submit_button('Зберегти всі налаштування');

        echo '</form>';

        echo '<div class="ua-dm-help">';

        echo '<h2>Інструкція</h2>';

        echo '<ol>';
        echo '<li>Знайди потрібний об’єкт через пошук.</li>';
        echo '<li>Вибери режим відображення.</li>';
        echo '<li>Вибери одну або декілька сторінок.</li>';
        echo '<li>Натисни «Зберегти всі налаштування».</li>';
        echo '</ol>';

        echo '<p><strong>Всі сторінки:</strong> об’єкт показується скрізь.</p>';
        echo '<p><strong>Тільки вибрані:</strong> об’єкт показується тільки на зазначених сторінках.</p>';
        echo '<p><strong>Всі, крім вибраних:</strong> об’єкт приховується на зазначених сторінках.</p>';

        echo '</div>';

        echo '</div>';

        $this->admin_assets();
    }

    private function render_section($title, $id, $items) {

        echo '<div class="ua-dm-section">';

        echo '<h2>' . esc_html($title) . ' ';
        echo '<span class="ua-dm-count">' . count($items) . '</span>';
        echo '</h2>';

        echo '<table class="widefat striped ua-dm-table" id="ua-dm-' .
            esc_attr($id) . '">';

        echo '<thead><tr>';
        echo '<th>Об’єкт</th>';
        echo '<th style="width:180px;">Режим</th>';
        echo '<th>Сторінки показу</th>';
        echo '</tr></thead>';

        echo '<tbody>';

        if (empty($items)) {

            echo '<tr><td colspan="3">Об’єктів не знайдено.</td></tr>';

        } else {

            foreach ($items as $item) {
                $this->render_item($item);
            }
        }

        echo '</tbody>';
        echo '</table>';

        echo '</div>';
    }

    /* -----------------------------------------
     * ADMIN CSS / JAVASCRIPT
     * -------------------------------------- */

    private function admin_assets() {
        ?>

        <style>
            .ua-dm-wrap {
                max-width: 1400px;
            }

            .ua-dm-toolbar {
                display: flex;
                align-items: center;
                gap: 15px;
                margin: 20px 0;
            }

            .ua-dm-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                margin: 20px 0;
                padding: 15px;
                border-radius: 5px;
            }

            .ua-dm-section h2 {
                margin-top: 0;
            }

            .ua-dm-section td {
                vertical-align: top;
                padding: 12px;
            }

            .ua-dm-section td small {
                color: #646970;
            }

            .ua-dm-section code {
                display: inline-block;
                margin-top: 5px;
            }

            .ua-dm-pages {
                min-height: 100px;
            }

            .ua-dm-count {
                display: inline-block;
                background: #2271b1;
                color: #fff;
                padding: 2px 9px;
                border-radius: 12px;
                font-size: 12px;
            }

            .ua-dm-help {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-left: 4px solid #2271b1;
                padding: 15px;
                margin-top: 25px;
            }

            @media(max-width:782px) {
                .ua-dm-section table,
                .ua-dm-section tbody,
                .ua-dm-section tr,
                .ua-dm-section td {
                    display: block;
                    width: auto !important;
                }

                .ua-dm-section thead {
                    display: none;
                }

                .ua-dm-section tr {
                    border-bottom: 2px solid #ddd;
                    padding: 10px 0;
                }

                .ua-dm-toolbar {
                    display: block;
                }
            }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {

            const search = document.getElementById('ua-dm-search');
            const counter = document.getElementById('ua-dm-counter');

            if (!search) return;

            function filterRows() {

                const query = search.value.toLowerCase().trim();

                let visible = 0;

                document.querySelectorAll(
                    '.ua-dm-table tbody tr[data-ua-dm-search]'
                ).forEach(function(row) {

                    const text = row.getAttribute(
                        'data-ua-dm-search'
                    ) || '';

                    const show = text.includes(query);

                    row.style.display = show ? '' : 'none';

                    if (show) visible++;
                });

                if (counter) {
                    counter.textContent = 'Знайдено: ' + visible;
                }
            }

            search.addEventListener('input', filterRows);

            filterRows();
        });
        </script>

        <?php
    }
}

new UA_Display_Manager();
