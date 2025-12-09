<?php
/*
Plugin Name: 历史上的今天
Plugin URI: https://www.lifengdi.com/
Description: 展示中外历史上的今天发生的重大事件
Version: 1.0
Author: Dylan Li
Author URI: https://www.lifengdi.com/
License: GPL2
Text Domain: history-today
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 获取今日事件
function ht_get_today_events() {
    global $wpdb;
    $today = date('md');
    // 处理闰年2月29日，平年默认取2月28日
    if ($today == '0229' && !date('L')) {
        $today = '0228';
    }

    $table_name = $wpdb->prefix . 'history_today';
    $events = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE month_day = %s ORDER BY event_year DESC",
            $today
        ),
        ARRAY_A
    );

    return $events ?: [];
}

// 短代码处理函数
function ht_history_today_shortcode($atts) {
    $events = ht_get_today_events();
    $today = date('m月d日');

    // 构建HTML
    $html = '<div class="history-today-wrapper">';
    $html .= '<h3 class="history-today-title">历史上的今天（'.$today.'）</h3>';

    if (empty($events)) {
        $html .= '<p class="history-today-empty">暂无相关历史事件记录</p>';
    } else {
        // 分离中外事件
        $cn_events = array_filter($events, function($e) { return $e['type'] == '中'; });
        $en_events = array_filter($events, function($e) { return $e['type'] == '外'; });

        // 展示中国事件
        if (!empty($cn_events)) {
            $html .= '<div class="history-today-cn">';
            $html .= '<h4>中国历史事件</h4>';
            $html .= '<ul>';
            foreach ($cn_events as $event) {
                $html .= '<li><strong>'.$event['year'].'年</strong>：'.$event['event'].'</li>';
            }
            $html .= '</ul></div>';
        }

        // 展示外国事件
        if (!empty($en_events)) {
            $html .= '<div class="history-today-foreign">';
            $html .= '<h4>世界历史事件</h4>';
            $html .= '<ul>';
            foreach ($en_events as $event) {
                $html .= '<li><strong>'.$event['year'].'年</strong>：'.$event['event'].'</li>';
            }
            $html .= '</ul></div>';
        }
    }

    $html .= '</div>';
    return $html;
}
add_shortcode('history_today', 'ht_history_today_shortcode');

// 添加前端样式
function ht_enqueue_styles() {
    wp_enqueue_style(
        'history-today-style',
        plugins_url('css/style.css', __FILE__),
        [],
        '1.0',
        'all'
    );
}
add_action('wp_enqueue_scripts', 'ht_enqueue_styles');

register_deactivation_hook(__FILE__, 'ht_deactivate_plugin');
?>