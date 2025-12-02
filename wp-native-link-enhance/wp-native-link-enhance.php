<?php
/*
Plugin Name: 原生友情链接增强
Plugin URI: https://www.lifengdi.com/
Description: 基于WordPress原生链接功能，支持按分类显示标题、多样式布局（列表/网格/卡片）、图标左对齐，含小工具和简码。小工具与简码配置完全独立。
Version: 1.7
Author: Dylan Li
Author URI: https://www.lifengdi.com/
License: GPLv2 or later
Text Domain: wp-native-link-enhance
*/

if (!defined('ABSPATH')) {
    exit;
}

class WP_Native_Link_Enhance {
    public function __construct() {
        // 核心钩子注册
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('widgets_init', [$this, 'register_link_widget']);
        add_shortcode('wp_native_links', [$this, 'render_links_shortcode']);
        
        // 后台设置
        add_action('admin_menu', [$this, 'add_admin_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * 前台加载样式
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'wp-native-link-styles',
            plugins_url('css/link-styles.css', __FILE__),
            [],
            '1.7',
            'all'
        );
    }

    /**
     * 注册小工具
     */
    public function register_link_widget() {
        register_widget('WP_Native_Link_Widget');
    }

    /**
     * 简码渲染函数
     */
    public function render_links_shortcode($atts) {
        $atts = shortcode_atts([
            'style'         => get_option('wp_native_link_default_style', 'list'),
            'number'        => 10,
            'show_logo'     => 'yes',
            'show_desc'     => 'yes',
            'title'         => '',
            'orderby'       => 'link_name',
            'order'         => 'ASC',
            'show_category' => 'no'
        ], $atts);

        $params = $this->sanitize_params($atts);
        return $this->render_links_content($params);
    }

    /**
     * 公共参数验证和清理函数（改为public，供简码使用）
     */
    public function sanitize_params($atts) {
        $valid_styles = ['list', 'grid', 'card'];
        $valid_orderbys = ['link_name', 'link_id', 'link_rating', 'link_updated'];

        return [
            'style'         => in_array($atts['style'], $valid_styles) ? $atts['style'] : 'list',
            'number'        => intval($atts['number']) > 0 ? intval($atts['number']) : 10,
            'show_logo'     => strtolower($atts['show_logo']) === 'no' ? 'no' : 'yes',
            'show_desc'     => strtolower($atts['show_desc']) === 'no' ? 'no' : 'yes',
            'title'         => sanitize_text_field($atts['title']),
            'orderby'       => in_array($atts['orderby'], $valid_orderbys) ? $atts['orderby'] : 'link_name',
            'order'         => strtoupper($atts['order']) === 'DESC' ? 'DESC' : 'ASC',
            'show_category' => strtolower($atts['show_category']) === 'yes' ? true : false
        ];
    }

    /**
     * 简码专用链接内容渲染函数（改为public）
     */
    public function render_links_content($params) {
        extract($params);
        $output = '';

        if (!empty($title)) {
            $output .= '<h3 class="wp-native-link-main-title">' . esc_html($title) . '</h3>';
        }

        if ($show_category) {
            $link_cats = get_terms([
                'taxonomy'   => 'link_category',
                'hide_empty' => true,
                'orderby'    => 'name',
                'order'      => 'ASC'
            ]);

            if (!empty($link_cats) && !is_wp_error($link_cats)) {
                foreach ($link_cats as $cat) {
                    $links = get_bookmarks([
                        'category'        => $cat->term_id,
                        'orderby'         => $orderby,
                        'order'           => $order,
                        'number'          => $number,
                        'hide_invisible'  => true
                    ]);

                    if (!empty($links)) {
                        $output .= '<h4 class="wp-native-link-category-title">' . esc_html($cat->name) . '</h4>';
                        $output .= '<div class="wp-native-link-container wp-native-link-' . esc_attr($style) . '">';
                        foreach ($links as $link) {
                            $output .= $this->render_single_link($link, $params);
                        }
                        $output .= '</div>';
                    }
                }
            } else {
                $output .= '<div class="wp-native-link-empty">' . __('暂无分类或分类下无链接', 'wp-native-link-enhance') . '</div>';
            }
        } else {
            $links = get_bookmarks([
                'orderby'         => $orderby,
                'order'           => $order,
                'number'          => $number,
                'hide_invisible'  => true
            ]);

            if (empty($links)) {
                $output .= '<div class="wp-native-link-empty">' . __('暂无友情链接', 'wp-native-link-enhance') . '</div>';
            } else {
                $output .= '<div class="wp-native-link-container wp-native-link-' . esc_attr($style) . '">';
                foreach ($links as $link) {
                    $output .= $this->render_single_link($link, $params);
                }
                $output .= '</div>';
            }
        }

        return $output;
    }

    /**
     * 渲染单条链接（改为public）
     */
    public function render_single_link($link, $params) {
        $link_url = esc_url($link->link_url);
        $link_name = esc_html($link->link_name);
        $link_logo = !empty($link->link_image) ? esc_url($link->link_image) : '';
        $link_desc = esc_html($link->link_description);
        $link_target = esc_attr($link->link_target ?: '_blank');
        $link_rel = esc_attr($link->link_rel ?: 'noopener noreferrer');

        $html = '<a href="' . $link_url . '" target="' . $link_target . '" rel="' . $link_rel . '" class="wp-native-link-item">';
        
        if ($params['show_logo'] === 'yes') {
            $html .= '<div class="wp-native-link-logo-container">';
            if ($link_logo) {
                $html .= '<img src="' . $link_logo . '" alt="' . $link_name . '" class="wp-native-link-logo">';
            }
            $html .= '</div>';
        }
        
        $html .= '<div class="wp-native-link-content">';
        $html .= '<span class="wp-native-link-name">' . $link_name . '</span>';
        if ($params['show_desc'] === 'yes' && !empty($link_desc)) {
            $html .= '<span class="wp-native-link-desc">' . $link_desc . '</span>';
        }
        $html .= '</div></a>';

        return $html;
    }

    // --- 后台设置相关方法 ---
    public function add_admin_settings_page() {
        add_options_page(
            __('友情链接增强设置', 'wp-native-link-enhance'),
            __('友情链接设置', 'wp-native-link-enhance'),
            'manage_options',
            'wp-native-link-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            'wp_native_link_group',
            'wp_native_link_default_style',
            ['sanitize_callback' => [$this, 'sanitize_style_setting']]
        );
        add_settings_section(
            'wp_native_link_display_section',
            __('展示样式设置', 'wp-native-link-enhance'),
            null,
            'wp-native-link-settings'
        );
        add_settings_field(
            'default_style',
            __('默认展示样式', 'wp-native-link-enhance'),
            [$this, 'render_style_field'],
            'wp-native-link-settings',
            'wp_native_link_display_section'
        );
    }

    public function sanitize_style_setting($input) {
        return in_array($input, ['list', 'grid', 'card']) ? $input : 'list';
    }

    public function render_style_field() {
        $default_style = get_option('wp_native_link_default_style', 'list');
        ?>
        <select name="wp_native_link_default_style" id="wp-native-link-style">
            <option value="list" <?php selected($default_style, 'list'); ?>><?php _e('列表式', 'wp-native-link-enhance'); ?></option>
            <option value="grid" <?php selected($default_style, 'grid'); ?>><?php _e('网格式', 'wp-native-link-enhance'); ?></option>
            <option value="card" <?php selected($default_style, 'card'); ?>><?php _e('卡片式', 'wp-native-link-enhance'); ?></option>
        </select>
        <p class="description"><?php _e('设置小工具和简码的默认展示样式', 'wp-native-link-enhance'); ?></p>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('友情链接增强设置', 'wp-native-link-enhance'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wp_native_link_group');
                do_settings_sections('wp-native-link-settings');
                submit_button(__('保存设置', 'wp-native-link-enhance'));
                ?>
            </form>
            <div class="wp-native-link-help" style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                <h3><?php _e('使用帮助', 'wp-native-link-enhance'); ?></h3>
                <p><?php _e('小工具和简码的配置现在是完全独立的。', 'wp-native-link-enhance'); ?></p>
                <p><?php _e('1. 小工具：在「外观 → 小工具」中添加并配置。', 'wp-native-link-enhance'); ?></p>
                <p><?php _e('2. 简码调用示例：', 'wp-native-link-enhance'); ?></p>
                <pre style="padding: 10px; background: #fff; border: 1px solid #eee; margin: 10px 0;">[wp_native_links style="grid" number="12" show_logo="yes" show_desc="no" title="合作伙伴"]</pre>
            </div>
        </div>
        <?php
    }
}

/**
 * 友情链接小工具类（完全独立处理，不依赖主类私有/保护方法）
 */
class WP_Native_Link_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'wp_native_link_widget',
            __('原生友情链接', 'wp-native-link-enhance'),
            [
                'description' => __('展示WordPress原生友情链接，支持分类标题、图标左对齐和多样式', 'wp-native-link-enhance'),
                'classname'   => 'wp-native-link-widget'
            ]
        );
    }

    /**
     * 小工具前台渲染（完全独立逻辑）
     */
    public function widget($args, $instance) {
        echo $args['before_widget'];

        // 小工具标题
        $title = apply_filters('widget_title', $instance['title'] ?? __('友情链接', 'wp-native-link-enhance'));
        if (!empty($title)) {
            echo $args['before_title'] . $title . $args['after_title'];
        }
        
        // 读取小工具设置并验证
        $params = $this->widget_sanitize_params($instance);
        // 渲染小工具内容
        echo $this->widget_render_content($params);
        
        echo $args['after_widget'];
    }

    /**
     * 小工具专用参数验证
     */
    private function widget_sanitize_params($instance) {
        $valid_styles = ['list', 'grid', 'card'];
        $valid_orderbys = ['link_name', 'link_id', 'link_rating', 'link_updated'];

        return [
            'style'         => in_array($instance['style'] ?? '', $valid_styles) ? $instance['style'] : get_option('wp_native_link_default_style', 'list'),
            'number'        => intval($instance['number'] ?? 10) > 0 ? intval($instance['number']) : 10,
            'show_logo'     => strtolower($instance['show_logo'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'show_desc'     => strtolower($instance['show_desc'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'orderby'       => in_array($instance['orderby'] ?? '', $valid_orderbys) ? $instance['orderby'] : 'link_name',
            'order'         => strtoupper($instance['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC',
            'show_category' => strtolower($instance['show_category'] ?? 'no') === 'yes' ? true : false
        ];
    }

    /**
     * 小工具专用内容渲染
     */
    private function widget_render_content($params) {
        extract($params);
        $output = '';

        if ($show_category) {
            $link_cats = get_terms([
                'taxonomy'   => 'link_category',
                'hide_empty' => true,
                'orderby'    => 'name',
                'order'      => 'ASC'
            ]);

            if (!empty($link_cats) && !is_wp_error($link_cats)) {
                foreach ($link_cats as $cat) {
                    $links = get_bookmarks([
                        'category'        => $cat->term_id,
                        'orderby'         => $orderby,
                        'order'           => $order,
                        'number'          => $number,
                        'hide_invisible'  => true
                    ]);

                    if (!empty($links)) {
                        $output .= '<h4 class="wp-native-link-category-title">' . esc_html($cat->name) . '</h4>';
                        $output .= '<div class="wp-native-link-container wp-native-link-' . esc_attr($style) . '">';
                        foreach ($links as $link) {
                            $output .= $this->widget_render_single_link($link, $params);
                        }
                        $output .= '</div>';
                    }
                }
            } else {
                $output .= '<div class="wp-native-link-empty">' . __('暂无分类或分类下无链接', 'wp-native-link-enhance') . '</div>';
            }
        } else {
            $links = get_bookmarks([
                'orderby'         => $orderby,
                'order'           => $order,
                'number'          => $number,
                'hide_invisible'  => true
            ]);

            if (empty($links)) {
                $output .= '<div class="wp-native-link-empty">' . __('暂无友情链接', 'wp-native-link-enhance') . '</div>';
            } else {
                $output .= '<div class="wp-native-link-container wp-native-link-' . esc_attr($style) . '">';
                foreach ($links as $link) {
                    $output .= $this->widget_render_single_link($link, $params);
                }
                $output .= '</div>';
            }
        }

        return $output;
    }

    /**
     * 小工具专用单条链接渲染
     */
    private function widget_render_single_link($link, $params) {
        $link_url = esc_url($link->link_url);
        $link_name = esc_html($link->link_name);
        $link_logo = !empty($link->link_image) ? esc_url($link->link_image) : '';
        $link_desc = esc_html($link->link_description);
        $link_target = esc_attr($link->link_target ?: '_blank');
        $link_rel = esc_attr($link->link_rel ?: 'noopener noreferrer');

        $html = '<a href="' . $link_url . '" target="' . $link_target . '" rel="' . $link_rel . '" class="wp-native-link-item">';
        
        if ($params['show_logo'] === 'yes') {
            $html .= '<div class="wp-native-link-logo-container">';
            if ($link_logo) {
                $html .= '<img src="' . $link_logo . '" alt="' . $link_name . '" class="wp-native-link-logo">';
            }
            $html .= '</div>';
        }
        
        $html .= '<div class="wp-native-link-content">';
        $html .= '<span class="wp-native-link-name">' . $link_name . '</span>';
        if ($params['show_desc'] === 'yes' && !empty($link_desc)) {
            $html .= '<span class="wp-native-link-desc">' . $link_desc . '</span>';
        }
        $html .= '</div></a>';

        return $html;
    }

    /**
     * 小工具后台表单
     */
    public function form($instance) {
        $instance = wp_parse_args((array)$instance, [
            'title'         => __('友情链接', 'wp-native-link-enhance'),
            'style'         => get_option('wp_native_link_default_style', 'list'),
            'number'        => 10,
            'show_logo'     => 'yes',
            'show_desc'     => 'yes',
            'orderby'       => 'link_name',
            'order'         => 'ASC',
            'show_category' => 'no'
        ]);
        ?>
        <!-- 标题设置 -->
        <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('标题：', 'wp-native-link-enhance'); ?></label><input type="text" class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($instance['title']); ?>"></p>
        <!-- 样式选择 -->
        <p><label for="<?php echo $this->get_field_id('style'); ?>"><?php _e('展示样式：', 'wp-native-link-enhance'); ?></label><select class="widefat" id="<?php echo $this->get_field_id('style'); ?>" name="<?php echo $this->get_field_name('style'); ?>"><option value="list" <?php selected($instance['style'], 'list'); ?>><?php _e('列表式', 'wp-native-link-enhance'); ?></option><option value="grid" <?php selected($instance['style'], 'grid'); ?>><?php _e('网格式', 'wp-native-link-enhance'); ?></option><option value="card" <?php selected($instance['style'], 'card'); ?>><?php _e('卡片式', 'wp-native-link-enhance'); ?></option></select></p>
        <!-- 显示数量 -->
        <p><label for="<?php echo $this->get_field_id('number'); ?>"><?php _e('显示数量：', 'wp-native-link-enhance'); ?></label><input type="number" class="small-text" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" value="<?php echo intval($instance['number']); ?>" min="1" max="50"></p>
        <!-- 是否显示图标 -->
        <p><label for="<?php echo $this->get_field_id('show_logo'); ?>"><?php _e('显示图标：', 'wp-native-link-enhance'); ?></label><select class="widefat" id="<?php echo $this->get_field_id('show_logo'); ?>" name="<?php echo $this->get_field_name('show_logo'); ?>"><option value="yes" <?php selected($instance['show_logo'], 'yes'); ?>><?php _e('是', 'wp-native-link-enhance'); ?></option><option value="no" <?php selected($instance['show_logo'], 'no'); ?>><?php _e('否', 'wp-native-link-enhance'); ?></option></select></p>
        <!-- 是否显示描述 -->
        <p><label for="<?php echo $this->get_field_id('show_desc'); ?>"><?php _e('显示描述：', 'wp-native-link-enhance'); ?></label><select class="widefat" id="<?php echo $this->get_field_id('show_desc'); ?>" name="<?php echo $this->get_field_name('show_desc'); ?>"><option value="yes" <?php selected($instance['show_desc'], 'yes'); ?>><?php _e('是', 'wp-native-link-enhance'); ?></option><option value="no" <?php selected($instance['show_desc'], 'no'); ?>><?php _e('否', 'wp-native-link-enhance'); ?></option></select></p>
        <!-- 按分类显示标题 -->
        <p><label for="<?php echo $this->get_field_id('show_category'); ?>"><?php _e('按分类显示标题：', 'wp-native-link-enhance'); ?></label><select class="widefat" id="<?php echo $this->get_field_id('show_category'); ?>" name="<?php echo $this->get_field_name('show_category'); ?>"><option value="no" <?php selected($instance['show_category'], 'no'); ?>><?php _e('否（不分组）', 'wp-native-link-enhance'); ?></option><option value="yes" <?php selected($instance['show_category'], 'yes'); ?>><?php _e('是（按分类分组）', 'wp-native-link-enhance'); ?></option></select></p>
        <!-- 排序字段 -->
        <p><label for="<?php echo $this->get_field_id('orderby'); ?>"><?php _e('排序字段：', 'wp-native-link-enhance'); ?></label><select class="widefat" id="<?php echo $this->get_field_id('orderby'); ?>" name="<?php echo $this->get_field_name('orderby'); ?>"><option value="link_name" <?php selected($instance['orderby'], 'link_name'); ?>><?php _e('链接名称', 'wp-native-link-enhance'); ?></option><option value="link_id" <?php selected($instance['orderby'], 'link_id'); ?>><?php _e('链接ID', 'wp-native-link-enhance'); ?></option><option value="link_rating" <?php selected($instance['orderby'], 'link_rating'); ?>><?php _e('评分', 'wp-native-link-enhance'); ?></option><option value="link_updated" <?php selected($instance['orderby'], 'link_updated'); ?>><?php _e('更新时间', 'wp-native-link-enhance'); ?></option></select></p>
        <!-- 排序方向 -->
        <p><label for="<?php echo $this->get_field_id('order'); ?>"><?php _e('排序方向：', 'wp-native-link-enhance'); ?></label><select class="widefat" id="<?php echo $this->get_field_id('order'); ?>" name="<?php echo $this->get_field_name('order'); ?>"><option value="ASC" <?php selected($instance['order'], 'ASC'); ?>><?php _e('升序', 'wp-native-link-enhance'); ?></option><option value="DESC" <?php selected($instance['order'], 'DESC'); ?>><?php _e('降序', 'wp-native-link-enhance'); ?></option></select></p>
        <?php
    }

    /**
     * 保存小工具设置
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title']);
        $instance['style'] = in_array($new_instance['style'], ['list', 'grid', 'card']) ? $new_instance['style'] : 'list';
        $instance['number'] = intval($new_instance['number']) ?: 10;
        $instance['show_logo'] = in_array($new_instance['show_logo'], ['yes', 'no']) ? $new_instance['show_logo'] : 'yes';
        $instance['show_desc'] = in_array($new_instance['show_desc'], ['yes', 'no']) ? $new_instance['show_desc'] : 'yes';
        $instance['orderby'] = in_array($new_instance['orderby'], ['link_name', 'link_id', 'link_rating', 'link_updated']) ? $new_instance['orderby'] : 'link_name';
        $instance['order'] = strtoupper($new_instance['order']) === 'DESC' ? 'DESC' : 'ASC';
        $instance['show_category'] = in_array($new_instance['show_category'], ['yes', 'no']) ? $new_instance['show_category'] : 'no';
        return $instance;
    }
}

// 初始化插件
new WP_Native_Link_Enhance();