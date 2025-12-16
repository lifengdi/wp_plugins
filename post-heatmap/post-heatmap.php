<?php
/*
Plugin Name: 文章热力图
Plugin URI: https://www.lifengdi.com/
Description: 生成 GitHub 风格的文章发布热力图，支持自定义文章类型和时间范围
Version: 1.0
Author: Dylan Li
Author URI: https://www.lifengdi.com/
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit;
}

// 加载资源
function ph_heatmap_enqueue_assets() {
    wp_enqueue_style(
        'ph-heatmap-style',
        plugins_url('assets/css/heatmap.css', __FILE__),
        array(),
        '1.0',
        'all'
    );

    wp_enqueue_script(
        'ph-heatmap-script',
        plugins_url('assets/js/heatmap.js', __FILE__),
        array('jquery'),
        '1.0',
        true
    );
	// 本地化AJAX地址
    wp_localize_script('ph-heatmap-script', 'phHeatmap', array(
        'ajaxUrl' => admin_url('admin-ajax.php')
    ));

    // 仅在有热力图简码时加载
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'post_heatmap')) {
        wp_enqueue_style('ph-heatmap-style');
        wp_enqueue_script('ph-heatmap-script');
    }
}
add_action('wp_enqueue_scripts', 'ph_heatmap_enqueue_assets');


/**
 * 文章发布热力图功能
 * 支持按年份选择/默认显示最近一年数据 + 右侧年份标签 + 统计信息
 */

// 注册热力图简码
add_shortcode('post_heatmap', 'ph_heatmap_shortcode');
function ph_heatmap_shortcode($atts) {
    // 简码参数默认值
    $atts = shortcode_atts(
        array(
            'post_type'   => 'post',   // 要统计的文章类型
            'year'        => '',       // 年份（空则显示最近一年）
            'time_range'  => 365,      // 最近N天（默认365）
            'width'       => '100%',   // 热力图宽度
            'title'       => '热力图' // 标题
        ),
        $atts,
        'post_heatmap'
    );

    // 安全过滤参数
    $post_type    = sanitize_key($atts['post_type']);
    $selected_year = $atts['year'] ? absint($atts['year']) : null;
    $time_range   = absint($atts['time_range']);
    $width        = esc_attr($atts['width']);
    $title        = sanitize_text_field($atts['title']);
    $heatmap_id   = 'ph-heatmap-' . uniqid(); // 唯一ID

    // 生成年份选择器选项（近10年 + 最近一年）
    $current_year = date('Y');
    $start_year   = $current_year - 9;
    $years        = range($start_year, $current_year);

    // 获取热力图数据（含统计信息）
    $heatmap_data = ph_heatmap_get_data($post_type, $selected_year, $time_range);

    // 输出HTML结构
    ob_start();
    ?>
    <div class="ph-heatmap-container"
         data-post-type="<?php echo esc_attr($post_type); ?>"
         data-time-range="<?php echo esc_attr($time_range); ?>"
         style="width: <?php echo $width; ?>; max-width: 100%; overflow-x: auto;">

        <!-- 头部：标题 + 年份选择器 -->
        <div class="ph-heatmap-header">
            <h3 class="ph-heatmap-title"><?php echo esc_html($title); ?></h3>
            <div class="ph-heatmap-year-selector">
                <select class="ph-year-select" data-heatmap-id="<?php echo esc_attr($heatmap_id); ?>">
                    <option value="" <?php selected($selected_year, ''); ?>>最近一年</option>
                    <?php foreach ($years as $year) : ?>
                        <option value="<?php echo $year; ?>" <?php selected($selected_year, $year); ?>>
                            <?php echo $year; ?>年
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- 热力图容器 -->
        <div id="<?php echo esc_attr($heatmap_id); ?>" class="ph-heatmap"></div>

        <!-- 初始数据（JSON）- 包含stats -->
        <script type="application/json" class="ph-heatmap-data-<?php echo esc_attr($heatmap_id); ?>">
            <?php echo wp_json_encode(array(
                'data'       => $heatmap_data['raw_data'],
                'stats'      => $heatmap_data['stats'], // 确保传递统计信息
                'year'       => $selected_year,
                'time_range' => $time_range
            )); ?>
        </script>
    </div>
    <?php
    return ob_get_clean();
}

// 获取热力图数据（按年份/最近N天）+ 统计信息
function ph_heatmap_get_data($post_type = 'post', $year = null, $time_range = 365) {
    global $wpdb;

    // 初始化核心变量（修复未定义问题）
    $data = array();
    $total_count = 0;
    $max_daily = 0;
    $max_date = '';
    $monthly_data = array();
    $weekday_count = array_fill(0, 7, 0); // 0=周日，6=周六

    // 条件1：传年份 → 查全年；条件2：不传 → 查最近N天
    if ($year) {
        $start_date = "$year-01-01";
        $end_date   = "$year-12-31";
        $date_where = "DATE(post_date) BETWEEN %s AND %s";
        $date_args  = array($start_date, $end_date);
        $total_days = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
    } else {
        $start_date = date('Y-m-d', strtotime("-$time_range days"));
        $date_where = "DATE(post_date) >= %s";
        $date_args  = array($start_date);
        $total_days = $time_range;
    }

    // 查询发布文章数量（按日期分组）
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(post_date) AS date, COUNT(ID) AS count
         FROM {$wpdb->posts}
         WHERE post_type = %s
           AND post_status = 'publish'
           AND $date_where
         GROUP BY DATE(post_date)
         ORDER BY date ASC",
        array_merge(array($post_type), $date_args)
    ), ARRAY_A);

    // 整理基础数据（必须先初始化$data，再使用）
    foreach ($results as $row) {
        $count = (int)$row['count'];
        $data[$row['date']] = $count;
        $total_count += $count;

        // 最高单日发布
        if ($count > $max_daily) {
            $max_daily = $count;
            $max_date = $row['date'];
        }

        // 月度统计（按 Y-m 格式）
        if ($row['date']) {
            $month = date('Y-m', strtotime($row['date']));
            if (!isset($monthly_data[$month])) {
                $monthly_data[$month] = 0;
            }
            $monthly_data[$month] += $count;

            // 每周发布频次统计（新增）
            $weekday = date('w', strtotime($row['date'])); // 0=周日，6=周六
            $weekday_count[$weekday] += $count;
        }
    }

    // ===== 发布节奏分析（修复：$data已初始化后再使用）=====
    // 1. 最长断更天数
    $max_break_days = 0;
    $max_break_start = '';
    $date_list = array_keys($data); // 第134行：现在$data已定义
    sort($date_list);

    if (count($date_list) > 1) {
        for ($i = 1; $i < count($date_list); $i++) {
            $prev_date = strtotime($date_list[$i-1]);
            $curr_date = strtotime($date_list[$i]);
            $diff_days = ($curr_date - $prev_date) / 86400;
            if ($diff_days > $max_break_days) {
                $max_break_days = $diff_days;
                $max_break_start = $date_list[$i-1];
            }
        }
    }

    // 2. 高频发布周几
    $max_weekday = 0;
    $max_weekday_count = 0;
    foreach ($weekday_count as $weekday => $count) {
        if ($count > $max_weekday_count) {
            $max_weekday_count = $count;
            $max_weekday = $weekday;
        }
    }
    $weekday_names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
    $high_freq_weekday = $weekday_names[$max_weekday];

    // ===== 分类占比统计 =====
    $category_data = [];
    if ($post_type === 'post' && $total_count > 0) { // 仅对文章类型统计
        $category_results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name AS cat_name, COUNT(p.ID) AS cat_count
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND tt.taxonomy = 'category'
               AND $date_where
             GROUP BY t.name
             ORDER BY cat_count DESC
             LIMIT 5",
            array_merge(array($post_type), $date_args)
        ), ARRAY_A);

        $total_cat_count = array_sum(array_column($category_results, 'cat_count'));
        foreach ($category_results as $cat) {
            $category_data[] = [
                'name' => $cat['cat_name'],
                'count' => $cat['cat_count'],
                'percent' => $total_cat_count > 0 ? round(($cat['cat_count'] / $total_cat_count) * 100, 1) : 0
            ];
        }
    }

    // 计算日均发布量
    $daily_average = $total_days > 0 ? round($total_count / $total_days, 2) : 0;

    // 找出发布量最高的月份
    $max_month = '';
    $max_month_count = 0;
    foreach ($monthly_data as $month => $count) {
        if ($count > $max_month_count) {
            $max_month_count = $count;
            $max_month = $month;
        }
    }
    $max_month_name = $max_month ? date('Y-m', strtotime($max_month)) : '无';

    // 返回所有数据
    return array(
        'raw_data' => $data,
        'stats' => array(
            // 基础统计
            'total' => $total_count,
            'daily_avg' => $daily_average,
            'max_daily' => $max_daily,
            'max_daily_date' => $max_date,
            'max_month' => $max_month_name,
            'max_month_count' => $max_month_count,
			// 分类占比
//             'category_data' => $category_data,
            // 发布节奏
            'high_freq_weekday' => $high_freq_weekday,
            'max_break_days' => $max_break_days,
            'max_break_start' => $max_break_start
        )
    );
}

// AJAX：动态获取热力图数据
add_action('wp_ajax_ph_get_heatmap_data', 'ph_ajax_get_heatmap_data');
add_action('wp_ajax_nopriv_ph_get_heatmap_data', 'ph_ajax_get_heatmap_data');
function ph_ajax_get_heatmap_data() {
    // 接收参数并过滤
    $year       = isset($_GET['year']) && $_GET['year'] ? absint($_GET['year']) : null;
    $post_type  = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';
    $time_range = isset($_GET['time_range']) ? absint($_GET['time_range']) : 365;

    $heatmap_data = ph_heatmap_get_data($post_type, $year, $time_range);

    // 返回JSON数据
    wp_send_json(array(
        'data' => $heatmap_data['raw_data'],
        'stats' => $heatmap_data['stats'],
        'year' => $year,
        'time_range' => $time_range
    ));
}