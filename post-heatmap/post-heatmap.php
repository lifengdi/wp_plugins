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

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 加载插件资源（CSS/JS）
 */
function ph_heatmap_enqueue_assets() {
    // 加载样式
    wp_enqueue_style(
        'ph-heatmap-style',
        plugins_url('assets/css/heatmap.css', __FILE__),
        array(),
        '1.0',
        'all'
    );

    // 加载JS（依赖jQuery，底部加载）
    wp_enqueue_script(
        'ph-heatmap-script',
        plugins_url('assets/js/heatmap.js', __FILE__),
        array('jquery'),
        '1.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'ph_heatmap_enqueue_assets');

/**
 * 获取热力图数据（按日期统计文章数）
 * @param string $post_type 文章类型
 * @param int $time_range 时间范围（天）
 * @return array 格式化的日期-数量数组
 */
function ph_heatmap_get_data($post_type = 'post', $time_range = 365) {
    global $wpdb;

    // 计算起始日期（当前时间 - 时间范围）
    $start_date = date('Y-m-d', strtotime("-$time_range days"));

    // 原生SQL查询（高性能）
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(post_date) AS date, COUNT(ID) AS count
         FROM {$wpdb->posts}
         WHERE post_type = %s
         AND post_status = 'publish'
         AND DATE(post_date) >= %s
         GROUP BY DATE(post_date)
         ORDER BY date ASC",
        $post_type,
        $start_date
    ), ARRAY_A);

    // 格式化数据为 {日期: 数量} 格式
    $data = array();
    foreach ($results as $row) {
        $data[$row['date']] = (int)$row['count'];
    }

    return $data;
}

/**
 * 热力图简码核心函数
 * 简码参数：
 * - post_type: 文章类型（默认 post）
 * - time_range: 时间范围（天，默认 365）
 * - width: 热力图宽度（默认 100%）
 * - title: 热力图标题（默认 "文章发布热力图"）
 */
function ph_heatmap_shortcode($atts) {
    // 解析简码参数（设置默认值）
    $atts = shortcode_atts(
        array(
            'post_type'   => 'post',
            'time_range'  => 365,
            'width'       => '100%',
            'title'       => '文章发布热力图'
        ),
        $atts,
        'post_heatmap'
    );

    // 验证参数
    $post_type = sanitize_text_field($atts['post_type']);
    $time_range = absint($atts['time_range']);
    $width = sanitize_css_class($atts['width']);
    $title = sanitize_text_field($atts['title']);

    // 限制时间范围（最小30天，最大3650天）
    $time_range = $time_range < 30 ? 30 : ($time_range > 3650 ? 3650 : $time_range);

    // 获取热力图数据
    $heatmap_data = ph_heatmap_get_data($post_type, $time_range);

    // 生成唯一ID（避免多热力图冲突）
    $heatmap_id = 'ph-heatmap-' . uniqid();

    // 输出HTML（使用ob缓存避免输出错乱）
    ob_start();
    ?>
    <div class="ph-heatmap-container" style="width: <?php echo esc_attr($width); ?>; margin: 20px 0;">
        <!-- 热力图标题 -->
        <h3 class="ph-heatmap-title"><?php echo esc_html($title); ?></h3>

        <!-- 热力图容器 -->
        <div id="<?php echo esc_attr($heatmap_id); ?>" class="ph-heatmap"></div>

        <!-- 隐藏数据（传递给JS） -->
        <script type="application/json" class="ph-heatmap-data-<?php echo esc_attr($heatmap_id); ?>">
            <?php echo wp_json_encode(array(
                'data' => $heatmap_data,
                'time_range' => $time_range
            )); ?>
        </script>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('post_heatmap', 'ph_heatmap_shortcode');