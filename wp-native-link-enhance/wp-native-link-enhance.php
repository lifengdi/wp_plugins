<?php
/*
Plugin Name: 原生友情链接增强
Plugin URI: https://www.lifengdi.com/
Description: 适配WP6.8.3+PHP8.4+原生link_rss字段的友情链接+RSS聚合功能
Version: 1.4
Author: Dylan Li
License: GPLv2
*/

// 防止直接访问
if (!defined('ABSPATH')) exit;

// ===================== 全局常量 =====================
define('WNLE_TABLE', $GLOBALS['wpdb']->prefix . 'lra_rss_data');
define('WNLE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WNLE_PLUGIN_URL', plugin_dir_url(__FILE__));

// ===================== 工具函数：安全日志记录（适配PHP8.4） =====================
function wnle_write_log($message) {
    // 1. 仅在 DEBUG 模式或管理员触发时记录（避免生产环境冗余日志）
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        // 允许通过常量强制开启日志（生产环境临时排查问题用）
        if (!defined('WNLE_FORCE_LOG') || !WNLE_FORCE_LOG) {
            return;
        }
    }

    // 2. 日志基础配置
    $log_dir = WP_CONTENT_DIR . '/logs/'; // 日志存储目录（wp-content/logs/）
    $log_file = $log_dir . 'wnle_rss.log'; // 日志文件名
    $max_log_size = 5 * 1024 * 1024; // 日志最大容量（5MB，避免文件过大）

    // 3. 处理日志内容（适配PHP8.4严格类型，避免Notice）
    // 若为数组/对象，自动序列化；其他类型转字符串
    if (is_array($message) || is_object($message)) {
        // PHP8.4 推荐使用 json_encode 替代 serialize，更安全易读
        $log_content = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // 序列化失败时降级处理
        if ($log_content === false) {
            $log_content = var_export($message, true);
        }
    } else {
        // 强制转字符串（处理布尔值、NULL等）
        $log_content = (string)$message;
    }

    // 4. 日志格式：时间戳 + 来源 + 内容（便于排查）
    $timestamp = date('Y-m-d H:i:s'); // 本地时间戳
    $log_entry = sprintf(
        "[%s] [WNLE-Plugin] %s\n",
        $timestamp,
        $log_content
    );

    // 5. 确保日志目录存在且可写（适配PHP8.4权限校验）
    try {
        // 检查目录是否存在，不存在则创建（递归创建多级目录）
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true); // 0755 权限：所有者读写执行，其他读执行
            // 写入目录索引文件（防止目录被浏览）
            file_put_contents($log_dir . 'index.html', '<!DOCTYPE html><html><head><title>Logs</title></head><body></body></html>');
        }

        // 检查目录可写性（PHP8.4 更严格的权限检测）
        if (!is_writable($log_dir)) {
            // 尝试修复权限（仅本地环境有效，服务器可能限制）
            chmod($log_dir, 0755);
            if (!is_writable($log_dir)) {
                error_log('WNLE插件：日志目录不可写 - ' . $log_dir);
                return;
            }
        }

        // 6. 日志轮转（避免单个文件过大）
        if (file_exists($log_file) && filesize($log_file) >= $max_log_size) {
            // 重命名旧日志（添加时间戳）
            $backup_file = $log_dir . 'wnle_rss_' . date('YmdHis') . '.log';
            rename($log_file, $backup_file);
            // 压缩旧日志（PHP8.4 支持 gzcompress 直接压缩）
            $backup_content = file_get_contents($backup_file);
            if ($backup_content !== false) {
                file_put_contents($backup_file . '.gz', gzcompress($backup_content, 6));
                unlink($backup_file); // 删除原始旧日志
            }
        }

        // 7. 写入日志（加锁避免并发写入冲突，适配PHP8.4）
        $file_handle = fopen($log_file, 'a'); // 追加模式
        if ($file_handle) {
            flock($file_handle, LOCK_EX); // 独占锁，防止多进程同时写入
            fwrite($file_handle, $log_entry);
            flock($file_handle, LOCK_UN); // 释放锁
            fclose($file_handle);

            // 确保日志文件权限安全（仅所有者可写）
            chmod($log_file, 0644);
        } else {
            error_log('WNLE插件：无法打开日志文件 - ' . $log_file);
        }
    } catch (Throwable $e) {
        // PHP8.4 支持 Throwable 捕获所有异常和错误
        error_log(sprintf(
            'WNLE插件日志写入失败：%s（行号：%d）',
            $e->getMessage(),
            $e->getLine()
        ));
    }
}

// ===================== 工具函数：重建数据表（适配MySQL10.5） =====================
function wnle_recreate_table() {
    global $wpdb;
    $table = WNLE_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category VARCHAR(100) NOT NULL DEFAULT '',
        title TEXT NOT NULL,
        link VARCHAR(500) NOT NULL DEFAULT '',
        description TEXT NOT NULL,
        publish_date BIGINT NOT NULL,
        source_name VARCHAR(200) NOT NULL,
        source_url VARCHAR(500) NOT NULL,
        logo VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_link_source (link, source_name),
        KEY idx_category_date (category, publish_date)
    ) $charset ENGINE=InnoDB;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    wnle_write_log('数据表重建完成：' . $table);
}

// ===================== 1. 激活/停用插件 =====================
register_activation_hook(__FILE__, 'wnle_activate');
function wnle_activate() {
    global $wpdb;
    $table = WNLE_TABLE;

    wnle_recreate_table();

    if (!get_option('wp_native_link_default_style')) {
        update_option('wp_native_link_default_style', 'list');
    }

    wp_clear_scheduled_hook('wnle_rss_fetch');
    if (!wp_next_scheduled('wnle_rss_fetch')) {
        wp_schedule_event(time(), 'wnle_30min', 'wnle_rss_fetch');
    }
    wnle_write_log('插件激活完成，定时任务已添加');
}

register_deactivation_hook(__FILE__, 'wnle_deactivate');
function wnle_deactivate() {
    wp_clear_scheduled_hook('wnle_rss_fetch');
    wnle_write_log('插件停用，定时任务已清理');
}

add_filter('cron_schedules', 'wnle_add_cron_schedule');
function wnle_add_cron_schedule($schedules) {
    $schedules['wnle_30min'] = array(
        'interval' => 1800,
        'display'  => 'Every 30 Minutes'
    );
    return $schedules;
}

// ===================== 2. 后台设置页面 =====================
add_action('admin_menu', 'wnle_add_admin_page');
function wnle_add_admin_page() {
    add_options_page(
        '友情链接增强设置',
        '友情链接设置',
        'manage_options',
        'wnle-link-settings',
        'wnle_render_admin_page'
    );
}

add_action('admin_init', 'wnle_register_settings');
function wnle_register_settings() {
    register_setting(
        'wnle_link_group',
        'wp_native_link_default_style',
        array('sanitize_callback' => 'wnle_sanitize_style')
    );

    add_settings_section(
        'wnle_link_display_section',
        '展示样式设置',
        null,
        'wnle-link-settings'
    );

    add_settings_field(
        'wnle_default_style',
        '默认展示样式',
        'wnle_render_style_field',
        'wnle-link-settings',
        'wnle_link_display_section'
    );
}

function wnle_sanitize_style($input) {
    $valid = array('list', 'grid', 'card');
    $input = (string) $input;
    return in_array($input, $valid) ? $input : 'list';
}

function wnle_render_style_field() {
    $value = get_option('wp_native_link_default_style', 'list');
    ?>
    <select name="wp_native_link_default_style" id="wnle-default-style">
        <option value="list" <?php selected($value, 'list'); ?>>列表式</option>
        <option value="grid" <?php selected($value, 'grid'); ?>>网格式</option>
        <option value="card" <?php selected($value, 'card'); ?>>卡片式</option>
    </select>
    <p class="description">设置友情链接简码/小工具的默认展示样式</p>
    <?php
}

function wnle_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('无权限访问此页面');
    }
    ?>
    <div class="wrap">
        <h1>友情链接增强设置</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('wnle_link_group');
            do_settings_sections('wnle-link-settings');
            submit_button('保存设置');
            ?>
        </form>
        <div style="margin-top:20px;padding:15px;background:#f5f5f5;border-radius:4px;">
            <h3>使用帮助</h3>
            <p>1. 友情链接简码示例：<br>
               <code>[wp_native_links style="grid" number="12" show_logo="yes" show_desc="yes" title="合作伙伴" show_category="yes" orderby="link_name" order="ASC"]</code>
            </p>
            <p>2. RSS聚合简码示例：<br>
               <code>[link_rss_aggregator category="tech" limit="10" default_logo="<?php echo WNLE_PLUGIN_URL; ?>images/default-logo.png"]</code>
            </p>
            <p>3. 测试RSS抓取：<br>
               <code><a href="<?php echo home_url('?test_wnle_rss=1'); ?>" target="_blank">点击测试RSS抓取（仅管理员可见）</a></code>
            </p>
        </div>
    </div>
    <?php
}

// ===================== 3. 友情链接核心功能 =====================
add_shortcode('wp_native_links', 'wnle_link_shortcode');
function wnle_link_shortcode($atts) {
    $default_atts = array(
        'style'         => get_option('wp_native_link_default_style', 'list'),
        'number'        => 10,
        'show_logo'     => 'yes',
        'show_desc'     => 'yes',
        'title'         => '',
        'orderby'       => 'link_name',
        'order'         => 'ASC',
        'show_category' => 'no'
    );
    $atts = shortcode_atts($default_atts, $atts, 'wp_native_links');

    $style = wnle_sanitize_style($atts['style']);
    $number = max(1, (int) $atts['number']);
    $show_logo = strtolower((string) $atts['show_logo']) === 'no' ? false : true;
    $show_desc = strtolower((string) $atts['show_desc']) === 'no' ? false : true;
    $title = sanitize_text_field($atts['title']);
    $orderby = in_array((string) $atts['orderby'], array('link_name', 'link_id', 'link_rating', 'link_updated')) ? $atts['orderby'] : 'link_name';
    $order = strtoupper((string) $atts['order']) === 'DESC' ? 'DESC' : 'ASC';
    $show_category = strtolower((string) $atts['show_category']) === 'yes' ? true : false;

    $output = '';

    if (!empty($title)) {
        $output .= '<h3 class="wp-native-link-main-title">' . esc_html($title) . '</h3>';
    }

    if ($show_category) {
        $link_cats = get_terms(array(
            'taxonomy'   => 'link_category',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC'
        ));

        if (!empty($link_cats) && !is_wp_error($link_cats)) {
            foreach ($link_cats as $cat) {
                $links = get_bookmarks(array(
                    'category'        => $cat->term_id,
                    'orderby'         => $orderby,
                    'order'           => $order,
                    'number'          => $number,
                    'hide_invisible'  => true
                ));

                if (!empty($links)) {
                    $output .= '<h4 class="wp-native-link-category-title">' . esc_html($cat->name) . '</h4>';
                    $output .= '<div class="wp-native-link-container wp-native-link-' . esc_attr($style) . '">';
                    foreach ($links as $link) {
                        $output .= wnle_render_single_link($link, $show_logo, $show_desc);
                    }
                    $output .= '</div>';
                }
            }
        } else {
            $output .= '<div class="wp-native-link-empty">暂无分类或分类下无链接</div>';
        }
    } else {
        $links = get_bookmarks(array(
            'orderby'         => $orderby,
            'order'           => $order,
            'number'          => $number,
            'hide_invisible'  => true
        ));

        if (empty($links)) {
            $output .= '<div class="wp-native-link-empty">暂无友情链接</div>';
        } else {
            $output .= '<div class="wp-native-link-container wp-native-link-' . esc_attr($style) . '">';
            foreach ($links as $link) {
                $output .= wnle_render_single_link($link, $show_logo, $show_desc);
            }
            $output .= '</div>';
        }
    }

    return $output;
}

function wnle_render_single_link($link, $show_logo, $show_desc) {
    // PHP8.4严格类型检查
    $link_url = esc_url((string) $link->link_url);
    $link_name = esc_html((string) $link->link_name);
    $link_logo = !empty($link->link_image) ? esc_url((string) $link->link_image) : '';
    $link_desc = esc_html((string) $link->link_description);
    $link_target = esc_attr((string) ($link->link_target ?: '_blank'));
    $link_rel = esc_attr((string) ($link->link_rel ?: 'noopener noreferrer'));

    $html = '<a href="' . $link_url . '" target="' . $link_target . '" rel="' . $link_rel . '" class="wp-native-link-item">';

    if ($show_logo) {
        $html .= '<div class="wp-native-link-logo-container">';
        // ========== 核心修改：统一Logo缺失处理逻辑 ==========
        if (!empty($link_logo)) {
            $html .= '<img src="' . $link_logo . '" alt="' . $link_name . '" class="wp-native-link-logo">';
        } else {
            // 无Logo时显示首字母占位符（与RSS逻辑一致）
            $initials = mb_substr($link_name, 0, 1, 'UTF-8');
            $initials = strtoupper((string) $initials);
            $html .= '<div class="wp-native-link-logo-placeholder">' . $initials . '</div>';
        }
        // ===================================================
        $html .= '</div>';
    }

    $html .= '<div class="wp-native-link-content">';
    $html .= '<span class="wp-native-link-name">' . $link_name . '</span>';
    if ($show_desc && !empty($link_desc)) {
        $html .= '<span class="wp-native-link-desc">' . $link_desc . '</span>';
    }
    $html .= '</div>';
    $html .= '</a>';

    return $html;
}

// ===================== 4. RSS核心功能（修正：读取原生link_rss字段） =====================
add_action('wnle_rss_fetch', 'wnle_fetch_rss');
function wnle_fetch_rss() {
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        wnle_write_log('致命错误：$wpdb 未初始化');
        return;
    }

    $table = WNLE_TABLE;
    wnle_write_log('开始执行RSS抓取任务');

    // 1. 验证数据表存在性
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
    if (!$table_exists) {
        wnle_write_log('数据表不存在，尝试重建：' . $table);
        wnle_recreate_table();
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
        if (!$table_exists) {
            wnle_write_log('数据表重建失败，终止抓取');
            return;
        }
    }

    // 2. 加载Feed依赖库
    if (!function_exists('fetch_feed')) {
        require_once ABSPATH . WPINC . '/feed.php';
        wnle_write_log('Feed依赖库已加载');
    }

    // 3. 获取友情链接（读取原生link_rss字段）
    $links = get_bookmarks(array(
        'hide_invisible' => true,
        'orderby' => 'name',
        'order' => 'ASC'
    ));
    wnle_write_log('获取到友情链接数量：' . count($links));

    $valid_links = array();
    foreach ($links as $link) {
        // 核心修正：直接读取wp_links表的link_rss字段（不再查linkmeta）
        $rss_url = trim((string) $link->link_rss);
        wnle_write_log('友情链接【' . $link->link_name . '】RSS地址：' . (empty($rss_url) ? '空' : $rss_url));

        if (!empty($rss_url)) {
            $link->rss_url = $rss_url;
            $valid_links[] = $link;
        }
    }
    wnle_write_log('有效RSS地址的友情链接数量：' . count($valid_links));

    if (empty($valid_links)) {
        wnle_write_log('未找到带有效RSS地址的友情链接');
        return;
    }

    // 4. 遍历抓取RSS
    foreach ($valid_links as $link) {
        $link_name = $link->link_name;
        $rss_url = $link->rss_url;
        wnle_write_log('开始抓取【' . $link_name . '】的RSS：' . $rss_url);

        try {
            // 抓取Feed（禁用SSL验证）
            add_filter('https_ssl_verify', '__return_false');
            add_filter('http_request_timeout', function() { return 15; });
            $feed = fetch_feed($rss_url);
            remove_filter('https_ssl_verify', '__return_false');
            remove_filter('http_request_timeout', '__return_15');

            if (is_wp_error($feed)) {
                $error_msg = $feed->get_error_message();
                wnle_write_log('抓取失败【' . $link_name . '】：' . $error_msg);
                continue;
            }

//             $feed->set_timeout(15);
//             $feed->set_useragent('WordPress-WNLE-RSS/1.4 (PHP8.4; WP6.8.3)');

            // 检查Feed条目
            $feed_items_count = $feed->get_item_quantity();
            wnle_write_log('【' . $link_name . '】Feed返回条目数：' . $feed_items_count);

            if ($feed_items_count <= 0) {
                wnle_write_log('【' . $link_name . '】Feed无有效条目');
                unset($feed);
                continue;
            }

            // 获取前5条数据
            $items = $feed->get_items(0, 10);
            wnle_write_log('【' . $link_name . '】实际处理条目数：' . count($items));

            // 准备基础数据
            $link_logo = !empty($link->link_image) ? esc_url_raw((string) $link->link_image) : '';
            $link_name_sql = esc_sql((string) $link->link_name);
            $link_url_sql = esc_url_raw((string) $link->link_url);
            // 若需要分类，可在wp_links表新增link_rss_category字段，或用link_notes存储
            $category = sanitize_text_field(trim((string) $link->link_notes)); // 临时用notes存分类

            // 遍历条目插入数据库
            foreach ($items as $index => $item) {
                $title = trim(wp_strip_all_tags((string) $item->get_title()));
                $title = mb_convert_encoding($title, 'UTF-8', 'GBK,GB2312,UTF-8,ASCII');

                $article_link = trim(esc_url((string) $item->get_permalink()));

                $description = trim(wp_kses_post((string) $item->get_description()));
                $description = mb_convert_encoding($description, 'UTF-8', 'GBK,GB2312,UTF-8,ASCII');

                $publish_date = (int) $item->get_date('U');
                if ($publish_date <= 0) {
                    $publish_date = time();
                }

                wnle_write_log('【' . $link_name . '-条目' . $index . '】标题：' . $title . ' | 链接：' . $article_link);

                if (empty($title) && empty($article_link)) {
                    wnle_write_log('【' . $link_name . '-条目' . $index . '】标题和链接均为空，跳过');
                    continue;
                }

                $data = array(
                    'category'     => $category ?: '',
                    'title'        => $title ?: '无标题',
                    'link'         => $article_link ?: '',
                    'description'  => $description ?: '',
                    'publish_date' => $publish_date,
                    'source_name'  => $link_name_sql,
                    'source_url'   => $link_url_sql,
                    'logo'         => $link_logo ?: ''
                );

                $format = array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s');
                $insert_result = $wpdb->insert($table, $data, $format);

                if ($insert_result) {
                    wnle_write_log('【' . $link_name . '-条目' . $index . '】插入成功，ID：' . $wpdb->insert_id);
                } else {
                    $last_error = isset($wpdb->last_error) ? $wpdb->last_error : '未知错误';
                    wnle_write_log('【' . $link_name . '-条目' . $index . '】插入失败，错误：' . $last_error);
                }
            }

            unset($feed);
        } catch (Throwable $e) {
            wnle_write_log('【' . $link_name . '】处理异常：' . $e->getMessage() . ' | 行号：' . $e->getLine());
            continue;
        }
    }

    // 5. 清理过期数据
    $expire_time = time() - (365 * 86400);
    $delete_count = $wpdb->delete($table, array('publish_date <' => $expire_time), array('%d'));
    wnle_write_log('RSS抓取完成，清理过期数据：' . $delete_count . '条');
}

// ===================== 5. RSS简码 =====================
add_shortcode('link_rss_aggregator', 'wnle_rss_shortcode');
function wnle_rss_shortcode($atts) {
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        return '<div class="link-rss-container"><div class="lra-error">数据库连接异常</div></div>';
    }

    $default_atts = array(
        'category'     => '',
        'limit'        => 5,
        'default_logo' => '',
        'pagination'   => 'yes' // 新增：控制是否显示分页
    );
    $atts = shortcode_atts($default_atts, $atts, 'link_rss_aggregator');

    // 处理参数（重点：使用独立分页参数 rss_page，避免冲突）
    $category = sanitize_text_field((string) $atts['category']);
    $limit = max(1, min(50, (int) $atts['limit'])); // 限制最大50条/页
    $default_logo = esc_url((string) $atts['default_logo']);
    $show_pagination = strtolower((string) $atts['pagination']) === 'no' ? false : true;

    // 获取当前页码（从URL参数 rss_page 读取，默认第1页）
    $current_page = isset($_GET['rss_page']) ? max(1, (int) $_GET['rss_page']) : 1;
    $offset = ($current_page - 1) * $limit;

    // 1. 构建查询条件
    $where = '';
    $where_args = array();
    if (!empty($category)) {
        $where = 'WHERE category = %s';
        $where_args[] = $category;
    }

    // 2. 查询总记录数（用于计算总页数）
    $count_sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM " . WNLE_TABLE . " $where",
        $where_args
    );
    $total_items = (int) $wpdb->get_var($count_sql);
    $total_pages = $show_pagination ? ceil($total_items / $limit) : 1;

    // 3. 查询当前页数据
    $data_sql = $wpdb->prepare(
        "SELECT * FROM " . WNLE_TABLE . " $where ORDER BY publish_date DESC LIMIT %d OFFSET %d",
        array_merge($where_args, array($limit, $offset))
    );
    $items = $wpdb->get_results($data_sql);

    // 空数据处理
    if (empty($items)) {
        $error_msg = $current_page > 1 ? '当前页没有数据' : '暂无RSS文章数据';
        return '<div class="link-rss-container"><div class="lra-error">' . $error_msg . '</div></div>';
    }

    // 4. 输出RSS列表
    $output = '<div class="link-rss-container">';
    $output .= '<ul class="link-rss-aggregator">';

    foreach ($items as $item) {
        $logo = !empty($item->logo) ? esc_url((string) $item->logo) : $default_logo;
        $source_name = esc_html((string) $item->source_name);
        $source_url = esc_url((string) $item->source_url);
        $timestamp = (int) $item->publish_date;
        $wp_timezone = wp_timezone();
        $date = new DateTime('@' . $timestamp);
        $date->setTimezone($wp_timezone);
        $publish_date = esc_html($date->format('Y-m-d H:i'));
        $title = esc_html((string) $item->title);
        $article_link = esc_url((string) $item->link);
        $description = wp_kses_post((string) $item->description);

        // 首字母占位符逻辑
        $initials = '';
        $avatar_class = 'lra-avatar';
        if (empty($logo)) {
            $avatar_class .= ' lra-avatar-placeholder';
            $initials = mb_substr($source_name ?: '无', 0, 1, 'UTF-8');
            $initials = strtoupper((string) $initials);
        }

        // 单个RSS条目HTML
        $item_html = '<li class="lra-card">';
        $item_html .= '<div class="lra-header">';
        $item_html .= '<div class="' . $avatar_class . '">';
        if (!empty($logo)) {
            $item_html .= '<img src="' . $logo . '" alt="' . $source_name . '" class="lra-logo">';
        } else {
            $item_html .= '<span>' . $initials . '</span>';
        }
        $item_html .= '</div>';
        $item_html .= '<div class="lra-text-group">';
        $item_html .= '<div class="lra-source"><a href="' . $source_url . '" target="_blank">' . $source_name . '</a></div>';
        $item_html .= '<div class="lra-date">' . $publish_date . '</div>';
        $item_html .= '</div>';
        $item_html .= '</div>';
        $item_html .= '<div class="lra-content">';
        $item_html .= '<h3 class="lra-title"><a href="' . $article_link . '" target="_blank">' . $title . '</a></h3>';
        if (!empty($description)) {
            $item_html .= '<p class="lra-description">' . $description . '</p>';
        }
        $item_html .= '</div>';
        $item_html .= '</li>';

        $output .= $item_html;
    }

    $output .= '</ul>';

    // 5. 分页导航（关键修复：确保链接正确传递所有参数）
    if ($show_pagination && $total_pages > 1) {
        $output .= '<div class="lra-pagination">';
        $output .= '<div class="lra-pagination-info">共 ' . $total_items . ' 条，' . $total_pages . ' 页</div>';
        $output .= '<nav class="lra-pagination-links">';

        // 构建当前页面URL（保留所有原有参数）
        $current_url = add_query_arg(array(), $_SERVER['REQUEST_URI']);
        // 移除URL中已有的 rss_page 参数（避免重复）
        $current_url = remove_query_arg('rss_page', $current_url);

        // 上一页
        if ($current_page > 1) {
            $prev_url = add_query_arg('rss_page', $current_page - 1, $current_url);
            $output .= '<a href="' . esc_url($prev_url) . '" class="lra-pagination-prev">上一页</a>';
        }

        // 页码链接（显示当前页±2页，避免页码过多）
        $start_page = max(1, $current_page - 2);
        $end_page = min($total_pages, $current_page + 2);

        // 显示第一页（如果当前页>3）
        if ($start_page > 1) {
            $first_url = add_query_arg('rss_page', 1, $current_url);
            $output .= '<a href="' . esc_url($first_url) . '" class="lra-pagination-page">1</a>';
            if ($start_page > 2) {
                $output .= '<span class="lra-pagination-ellipsis">...</span>';
            }
        }

        // 中间页码
        for ($i = $start_page; $i <= $end_page; $i++) {
            $page_class = 'lra-pagination-page';
            if (abs($i - $current_page) <= 1) {
                $page_class .= ' current-adjacent';
            }
            if ($i == $current_page) {
                $output .= '<span class="lra-pagination-current">' . $i . '</span>'; // 当前页高亮
            } else {
                $page_url = add_query_arg('rss_page', $i, $current_url);
                $output .= '<a href="' . esc_url($page_url) . '" class="' . $page_class . '">' . $i . '</a>';
            }
        }

        // 显示最后一页（如果当前页<总页数-2）
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                $output .= '<span class="lra-pagination-ellipsis">...</span>';
            }
            $last_url = add_query_arg('rss_page', $total_pages, $current_url);
            $output .= '<a href="' . esc_url($last_url) . '" class="lra-pagination-page">' . $total_pages . '</a>';
        }

        // 下一页
        if ($current_page < $total_pages) {
            $next_url = add_query_arg('rss_page', $current_page + 1, $current_url);
            $output .= '<a href="' . esc_url($next_url) . '" class="lra-pagination-next">下一页</a>';
        }

        $output .= '</nav></div>';
    }

    $output .= '</div>';

    return $output;
}

// ===================== 6. 友情链接小工具 =====================
add_action('widgets_init', 'wnle_register_link_widget');
function wnle_register_link_widget() {
    register_widget('WNLE_Link_Widget');
}

class WNLE_Link_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'wnle_link_widget',
            '原生友情链接',
            array('description' => '展示友情链接（支持原样式/分类/排序）')
        );
    }

    public function widget($args, $instance) {
        $args = (array) $args;
        $instance = (array) $instance;

        echo $args['before_widget'];

        $title = apply_filters('widget_title', isset($instance['title']) ? (string) $instance['title'] : '友情链接');
        if (!empty($title)) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        $atts = array(
            'style'         => isset($instance['style']) ? (string) $instance['style'] : get_option('wp_native_link_default_style', 'list'),
            'number'        => isset($instance['number']) ? (int) $instance['number'] : 10,
            'show_logo'     => isset($instance['show_logo']) ? (string) $instance['show_logo'] : 'yes',
            'show_desc'     => isset($instance['show_desc']) ? (string) $instance['show_desc'] : 'yes',
            'orderby'       => isset($instance['orderby']) ? (string) $instance['orderby'] : 'link_name',
            'order'         => isset($instance['order']) ? (string) $instance['order'] : 'ASC',
            'show_category' => isset($instance['show_category']) ? (string) $instance['show_category'] : 'no'
        );

        echo wnle_link_shortcode($atts);

        echo $args['after_widget'];
    }

    public function form($instance) {
        $instance = wp_parse_args((array) $instance, array(
            'title'         => '友情链接',
            'style'         => get_option('wp_native_link_default_style', 'list'),
            'number'        => 10,
            'show_logo'     => 'yes',
            'show_desc'     => 'yes',
            'orderby'       => 'link_name',
            'order'         => 'ASC',
            'show_category' => 'no'
        ));
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">标题：</label>
            <input type="text" class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($instance['title']); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('style'); ?>">展示样式：</label>
            <select class="widefat" id="<?php echo $this->get_field_id('style'); ?>" name="<?php echo $this->get_field_name('style'); ?>">
                <option value="list" <?php selected($instance['style'], 'list'); ?>>列表式</option>
                <option value="grid" <?php selected($instance['style'], 'grid'); ?>>网格式</option>
                <option value="card" <?php selected($instance['style'], 'card'); ?>>卡片式</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('number'); ?>">显示数量：</label>
            <input type="number" class="small-text" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" value="<?php echo intval($instance['number']); ?>" min="1" max="50">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show_logo'); ?>">显示Logo：</label>
            <select class="widefat" id="<?php echo $this->get_field_id('show_logo'); ?>" name="<?php echo $this->get_field_name('show_logo'); ?>">
                <option value="yes" <?php selected($instance['show_logo'], 'yes'); ?>>是</option>
                <option value="no" <?php selected($instance['show_logo'], 'no'); ?>>否</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show_desc'); ?>">显示描述：</label>
            <select class="widefat" id="<?php echo $this->get_field_id('show_desc'); ?>" name="<?php echo $this->get_field_name('show_desc'); ?>">
                <option value="yes" <?php selected($instance['show_desc'], 'yes'); ?>>是</option>
                <option value="no" <?php selected($instance['show_desc'], 'no'); ?>>否</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show_category'); ?>">按分类显示标题：</label>
            <select class="widefat" id="<?php echo $this->get_field_id('show_category'); ?>" name="<?php echo $this->get_field_name('show_category'); ?>">
                <option value="no" <?php selected($instance['show_category'], 'no'); ?>>否</option>
                <option value="yes" <?php selected($instance['show_category'], 'yes'); ?>>是</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('orderby'); ?>">排序字段：</label>
            <select class="widefat" id="<?php echo $this->get_field_id('orderby'); ?>" name="<?php echo $this->get_field_name('orderby'); ?>">
                <option value="link_name" <?php selected($instance['orderby'], 'link_name'); ?>>链接名称</option>
                <option value="link_id" <?php selected($instance['orderby'], 'link_id'); ?>>链接ID</option>
                <option value="link_rating" <?php selected($instance['orderby'], 'link_rating'); ?>>评分</option>
                <option value="link_updated" <?php selected($instance['orderby'], 'link_updated'); ?>>更新时间</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('order'); ?>">排序方向：</label>
            <select class="widefat" id="<?php echo $this->get_field_id('order'); ?>" name="<?php echo $this->get_field_name('order'); ?>">
                <option value="ASC" <?php selected($instance['order'], 'ASC'); ?>>升序</option>
                <option value="DESC" <?php selected($instance['order'], 'DESC'); ?>>降序</option>
            </select>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field((string) $new_instance['title']);
        $instance['style'] = wnle_sanitize_style((string) $new_instance['style']);
        $instance['number'] = max(1, min(50, (int) $new_instance['number']));
        $instance['show_logo'] = in_array((string) $new_instance['show_logo'], array('yes', 'no')) ? $new_instance['show_logo'] : 'yes';
        $instance['show_desc'] = in_array((string) $new_instance['show_desc'], array('yes', 'no')) ? $new_instance['show_desc'] : 'yes';
        $instance['orderby'] = in_array((string) $new_instance['orderby'], array('link_name', 'link_id', 'link_rating', 'link_updated')) ? $new_instance['orderby'] : 'link_name';
        $instance['order'] = in_array(strtoupper((string) $new_instance['order']), array('ASC', 'DESC')) ? strtoupper($new_instance['order']) : 'ASC';
        $instance['show_category'] = in_array((string) $new_instance['show_category'], array('yes', 'no')) ? $new_instance['show_category'] : 'no';
        return $instance;
    }
}

// ===================== 7. 样式加载 =====================
add_action('wp_enqueue_scripts', 'wnle_load_styles');
function wnle_load_styles() {
    wp_enqueue_style(
        'wp-native-link-styles',
        WNLE_PLUGIN_URL . 'css/link-styles.css',
        array(),
        '1.4',
        'all'
    );

    global $post;
    if (isset($post) && is_object($post) && has_shortcode((string) $post->post_content, 'link_rss_aggregator')) {
        wp_enqueue_style(
            'link-rss-styles',
            WNLE_PLUGIN_URL . 'css/rss-styles.css',
            array('wp-native-link-styles'),
            '1.4',
            'all'
        );
    }
}

// ===================== 8. 手动抓取+测试功能（修正读取原生link_rss） =====================
add_action('admin_bar_menu', 'wnle_add_admin_bar_item', 100);
function wnle_add_admin_bar_item($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $nonce = wp_create_nonce('wnle_rss_fetch_nonce');
    $wp_admin_bar->add_node(array(
        'id'    => 'wnle_fetch_rss',
        'title' => '手动抓取RSS',
        'href'  => admin_url('admin-ajax.php?action=wnle_manual_fetch_rss&_wpnonce=' . $nonce),
        'meta'  => array('target' => '_blank')
    ));
}

add_action('wp_ajax_wnle_manual_fetch_rss', 'wnle_manual_fetch_rss');
function wnle_manual_fetch_rss() {
    check_admin_referer('wnle_rss_fetch_nonce', '_wpnonce');
    if (!current_user_can('manage_options')) {
        wp_die('无权限执行此操作');
    }

    ob_start();
    wnle_fetch_rss();
    $output = ob_get_clean();

    wnle_write_log('手动抓取执行结果：' . (empty($output) ? '无输出' : $output));

    wp_safe_redirect(admin_url('options-general.php?page=wnle-link-settings&fetch=success'));
    exit;
}

// 独立测试功能（修正读取原生link_rss字段）
add_action('init', 'wnle_test_rss_fetch');
function wnle_test_rss_fetch() {
    if (!isset($_GET['test_wnle_rss']) || (int) $_GET['test_wnle_rss'] !== 1) {
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_die('无权限访问测试页面');
    }

    wnle_write_log('开始执行独立测试（WP6.8.3+PHP8.4+原生link_rss字段）');

    global $wpdb;
    $table = WNLE_TABLE;

    // 1. 验证数据表
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
    if (!$table_exists) {
        wnle_write_log('测试失败：数据表不存在');
        wp_die('<h3>测试失败</h3><p>数据表不存在，已尝试重建，请查看日志。</p>');
    }

    // 2. 加载Feed库
    if (!function_exists('fetch_feed')) {
        require_once ABSPATH . WPINC . '/feed.php';
    }

    // 3. 查找测试链接（读取原生link_rss字段）
    $links = get_bookmarks(array('hide_invisible' => true));
    $test_rss_url = '';
    $test_link_name = '';
    $test_link = null;
    foreach ($links as $link) {
        // 核心修正：直接读取wp_links.link_rss
        $rss_url = trim((string) $link->link_rss);
        if (!empty($rss_url)) {
            $test_rss_url = $rss_url;
            $test_link_name = $link->link_name;
            $test_link = $link;
            break;
        }
    }

    if (empty($test_rss_url)) {
        wnle_write_log('测试失败：未找到带link_rss字段的友情链接');
        wp_die('<h3>测试失败</h3><p>未找到带有效RSS地址的友情链接，请：</p><ul><li>1. 编辑友情链接，在「RSS地址」字段填写有效RSS URL</li><li>2. 确保link_rss字段不为空</li></ul>');
    }

    // 4. 测试抓取
    add_filter('https_ssl_verify', '__return_false');
    $feed = fetch_feed($test_rss_url);
    remove_filter('https_ssl_verify', '__return_false');

    if (is_wp_error($feed)) {
        $error = $feed->get_error_message();
        wnle_write_log('测试失败：Feed抓取错误 - ' . $error);
        wp_die('<h3>测试失败</h3><p>Feed抓取错误：' . $error . '</p><p>请检查RSS地址是否有效，或服务器是否能访问外部网络。</p>');
    }

    // 5. 测试条目
    $items = $feed->get_items(0, 1);
    if (empty($items)) {
        wnle_write_log('测试失败：Feed无有效条目');
        wp_die('<h3>测试失败</h3><p>RSS源返回无有效条目，请检查RSS地址是否正确。</p>');
    }

    // 6. 测试插入
    $item = $items[0];
    $data = array(
        'category'     => '',
        'title'        => trim(wp_strip_all_tags((string) $item->get_title())) ?: '测试标题',
        'link'         => trim(esc_url((string) $item->get_permalink())) ?: '',
        'description'  => trim(wp_kses_post((string) $item->get_description())) ?: '',
        'publish_date' => (int) $item->get_date('U') ?: time(),
        'source_name'  => esc_sql((string) $test_link_name),
        'source_url'   => esc_url_raw((string) $test_link->link_url),
        'logo'         => ''
    );

    $insert = $wpdb->insert($table, $data, array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'));
    if ($insert) {
        $id = $wpdb->insert_id;
        wnle_write_log('测试成功：数据插入成功，ID=' . $id);
        wp_die('<h3>测试成功！</h3><p>数据已插入数据库，ID：' . $id . '</p><p>请查看 <code>wp-content/wnle_rss.log</code> 获取详细日志。</p>');
    } else {
        $error = $wpdb->last_error;
        wnle_write_log('测试失败：数据库插入错误 - ' . $error);
        wp_die('<h3>测试失败</h3><p>数据库插入错误：' . $error . '</p>');
    }
}