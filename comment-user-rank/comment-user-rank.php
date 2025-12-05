<?php
/*
Plugin Name: 评论用户等级显示
Plugin URI: https://www.lifengdi.com/
Description: 评论用户等级标签，管理员显示「管理」标签，友情链接显示「友」标签
Version: 1.3
Author: Dylan Li
Author URI: https://www.lifengdi.com/
License: GPLv2 or later
Text Domain: comment-user-rank
*/

// 防止直接访问文件
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 定义插件常量
 */
define('CUR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CUR_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * 统计评论用户的有效评论数（仅审核通过的评论）
 * @param string $email 评论用户邮箱
 * @param string $name 评论用户名称
 * @param string $url 评论用户网站URL
 * @return int 评论数
 */
function cur_get_comment_count($email, $name, $url) {
    global $wpdb;
    
    // 优先用邮箱统计（唯一标识）
    if (!empty($email)) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_approved = '1'",
            $email
        ));
    } 
    // 无邮箱时，用名称+URL组合统计（减少重复）
    elseif (!empty($url)) {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author = %s AND comment_author_url = %s AND comment_approved = '1'",
            $name, $url
        ));
    } 
    // 仅用户名（可能重复）
    else {
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author = %s AND comment_approved = '1'",
            $name
        ));
    }
    
    return intval($count);
}

/**
 * 12级等级规则：评论数区间 | 等级名称 | CSS类名
 */
function cur_get_user_rank($count) {
    $rank_rules = array(
        array('min' => 0,  'max' => 2,   'name' => '黑铁', 'class' => 'rank-1'),
        array('min' => 3,  'max' => 5,   'name' => '青铜', 'class' => 'rank-2'),
        array('min' => 6,  'max' => 10,  'name' => '白银', 'class' => 'rank-3'),
        array('min' => 11, 'max' => 20,  'name' => '黄金', 'class' => 'rank-4'),
        array('min' => 21, 'max' => 50,  'name' => '铂金', 'class' => 'rank-5'),
        array('min' => 51, 'max' => 150,  'name' => '钻石', 'class' => 'rank-6'),
        array('min' => 151, 'max' => 300,  'name' => '大师', 'class' => 'rank-7'),
        array('min' => 301, 'max' => 700,  'name' => '超凡大师', 'class' => 'rank-8'),
        array('min' => 701, 'max' => 1500,  'name' => '宗师', 'class' => 'rank-9'),
        array('min' => 1501, 'max' => 4000,  'name' => '傲世宗师', 'class' => 'rank-10'),
        array('min' => 4001, 'max' => 9999, 'name' => '王者', 'class' => 'rank-11'),
        array('min' => 10000,'max' => 99999,'name' => '最强王者', 'class' => 'rank-12')
    );
    
    // 匹配等级
    foreach ($rank_rules as $rank) {
        if ($count >= $rank['min'] && $count <= $rank['max']) {
            return $rank;
        }
    }
    
    // 默认等级（兜底）
    return $rank_rules[0];
}

/**
 * 检查评论用户是否为网站管理员
 */
function cur_is_admin_comment($comment) {
    // 1. 已登录管理员评论
    if ($comment->user_id > 0) {
        $user = get_user_by('id', $comment->user_id);
        if ($user && in_array('administrator', $user->roles)) {
            return true;
        }
    }
    
    // 2. 未登录但邮箱匹配管理员
    $admin_emails = array();
    $admins = get_users(array('role' => 'administrator'));
    foreach ($admins as $admin) {
        $admin_emails[] = $admin->user_email;
    }
    
    if (!empty($comment->comment_author_email) && in_array($comment->comment_author_email, $admin_emails)) {
        return true;
    }
    
    return false;
}

/**
 * 检查评论用户URL是否在友情链接表中
 */
function cur_is_friend_link($url) {
    if (empty($url)) return false;
    
    global $wpdb;
    $url = trim($url);
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_url = %s",
        $url
    ));
    
    return intval($exists) > 0;
}

/**
 * 构建等级标签HTML
 */
function cur_build_rank_tag($rank) {
    return sprintf(
        '<span class="comment-user-rank %s">%s</span>',
        esc_attr($rank['class']),
        esc_html($rank['name'])
    );
}

/**
 * 构建管理员标签HTML
 */
function cur_build_admin_tag() {
    return '<span class="comment-user-admin">管理</span>';
}

/**
 * 构建友情链接标签HTML
 */
function cur_build_friend_tag() {
    return '<span class="comment-user-friend">友</span>';
}

/**
 * 标记当前是否为小工具渲染上下文
 */
function init_widget_render_flag() {
    global $is_widget_rendering;
    $is_widget_rendering = false; // 初始化默认值

    // 拦截所有小工具的渲染，标记状态
    add_filter('widget_display_callback', function($instance, $widget, $args) {
        global $is_widget_rendering;
        $is_widget_rendering = true; // 进入小工具渲染，标记为 true
        return $instance;
    }, 1, 3);

    // 小工具渲染结束后，重置标记（避免影响后续逻辑）
    add_action('dynamic_sidebar_after', function() {
        global $is_widget_rendering;
        $is_widget_rendering = false;
    });
}
add_action('init', 'init_widget_render_flag');

/**
 * 为评论作者添加标签
 */
function cur_add_tags_to_author_text($author, $comment_id) {

    if (is_admin()) {
        return $author;
    }
    if (strpos($author, 'comment-user-') !== false) {
        return $author;
    }

    global $is_widget_rendering;
	if ($is_widget_rendering) {
        return $author;
    }
    
    $comment = get_comment($comment_id);
    if (!$comment) return $author;
    
    // 管理员判断
    if (cur_is_admin_comment($comment)) {
        $author .= cur_build_admin_tag();
        $url = $comment->comment_author_url;
        if (cur_is_friend_link($url)) {
            $author .= cur_build_friend_tag();
        }
        return $author;
    }
    
    // 非管理员显示等级
    $email = $comment->comment_author_email;
    $name = $comment->comment_author;
    $url = $comment->comment_author_url;
    
    $count = cur_get_comment_count($email, $name, $url);
    $rank = cur_get_user_rank($count);
    $is_friend = cur_is_friend_link($url);
    
    $author .= cur_build_rank_tag($rank);
    if ($is_friend) {
        $author .= cur_build_friend_tag();
    }
    
    return $author;
}
add_filter('get_comment_author', 'cur_add_tags_to_author_text', 10, 2);

/**
 * 加载外部CSS文件
 */
function cur_enqueue_custom_styles() {
    wp_enqueue_style(
        'comment-user-rank-style',
        CUR_PLUGIN_URL . 'css/comment-rank.css',
        array(),
        '1.3',
        'all'
    );
}
add_action('wp_enqueue_scripts', 'cur_enqueue_custom_styles');