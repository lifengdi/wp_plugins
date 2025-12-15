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
}
add_action('wp_enqueue_scripts', 'ph_heatmap_enqueue_assets');

// 获取热力图数据
function ph_heatmap_get_data($post_type = 'post', $time_range = 365) {
    global $wpdb;
    $start_date = date('Y-m-d', strtotime("-$time_range days"));

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

    $data = array();
    foreach ($results as $row) {
        $data[$row['date']] = (int)$row['count'];
    }

    return $data;
}

// 热力图简码
function ph_heatmap_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'post_type'   => 'post',
            'time_range'  => 365,
            'width'       => '100%',
            'title'       => '热力图'
        ),
        $atts,
        'post_heatmap'
    );

    $post_type = sanitize_key($atts['post_type']);
    $time_range = absint($atts['time_range']);
    $width = esc_attr($atts['width']);
    $title = sanitize_text_field($atts['title']);

    $time_range = $time_range < 30 ? 30 : ($time_range > 3650 ? 3650 : $time_range);
    $heatmap_data = ph_heatmap_get_data($post_type, $time_range);
    $heatmap_id = 'ph-heatmap-' . uniqid();

    ob_start();
    ?>
    <div class="ph-heatmap-container" style="width: <?php echo $width; ?>;">
        <h3 class="ph-heatmap-title"><?php echo esc_html($title); ?></h3>
        <div id="<?php echo esc_attr($heatmap_id); ?>" class="ph-heatmap"></div>
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