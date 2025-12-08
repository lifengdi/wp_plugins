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
        array('min' => 0,  'max' => 5,   'name' => '黑铁', 'class' => 'rank-1'),
        array('min' => 6,  'max' => 10,   'name' => '青铜', 'class' => 'rank-2'),
        array('min' => 11,  'max' => 20,  'name' => '白银', 'class' => 'rank-3'),
        array('min' => 21, 'max' => 35,  'name' => '黄金', 'class' => 'rank-4'),
        array('min' => 36, 'max' => 70,  'name' => '铂金', 'class' => 'rank-5'),
        array('min' => 71, 'max' => 150,  'name' => '钻石', 'class' => 'rank-6'),
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

function cur_add_tags_to_author_link($author_link, $author, $comment_id) {
    $comment = get_comment($comment_id);
    if (!$comment) return $author_link;

    // 管理员显示「管理」标签
    if (cur_is_admin_comment($comment)) {
        $author_link .= cur_build_admin_tag();
        // 管理员显示「友」标签
        $url = $comment->comment_author_url;
        if (cur_is_friend_link($url)) {
            $author_link .= cur_build_friend_tag();
        }
        return $author_link;
    }

    // 非管理员显示12级等级标签
    $email = $comment->comment_author_email;
    $name = $comment->comment_author;
    $url = $comment->comment_author_url;

    $count = cur_get_comment_count($email, $name, $url);
    $rank = cur_get_user_rank($count);
    $is_friend = cur_is_friend_link($url);

    $author_link .= cur_build_rank_tag($rank);
    if ($is_friend) {
        $author_link .= cur_build_friend_tag();
    }

    return $author_link;
}
add_filter('get_comment_author_link', 'cur_add_tags_to_author_link', 10, 3);

/**
 * 为无链接的评论作者添加标签
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
// add_filter('get_comment_author', 'cur_add_tags_to_author_text', 10, 2);

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

/**
 * 评论等级排行榜短码
 * 短码用法：[comment_rank_top num="10" avatar_size="64" columns="3" title="评论达人榜"]
 * 参数说明：
 * - title: 排行榜标题（支持自定义，默认"评论等级排行榜"）
 * - columns: 列数（1~4，默认3）
 * - num: 显示数量（默认10，最大50）
 * - avatar_size: 头像尺寸（默认64px，最小32px）
 */
function cur_render_rank_top($atts) {


    // 2. 短码参数处理 - 新增 title 参数
    $atts = shortcode_atts(array(
        'num'         => 10,
        'avatar_size' => 64,
        'columns'     => 3,
        'title'       => '评论等级排行榜', // 新增：默认标题
    ), $atts, 'comment_rank_top');

    // 参数安全处理
    $limit = min(intval($atts['num']), 50);
    $avatar_size = intval($atts['avatar_size']);
    $avatar_size = $avatar_size < 32 ? 32 : $avatar_size;
    $columns = intval($atts['columns']);
    $columns = $columns < 1 ? 1 : ($columns > 4 ? 4 : $columns);
    $custom_title = esc_html($atts['title']); // 安全处理自定义标题（防XSS）

    global $wpdb;
    $table_prefix = $wpdb->prefix;

    // 3. 查询逻辑（不变）
    $top_users = $wpdb->get_results($wpdb->prepare(
        "SELECT
            COUNT(*) as comment_count,
            comment_author_email,
            comment_author,
            comment_author_url
         FROM {$table_prefix}comments
         WHERE comment_approved = '1' AND user_id != 1
           AND comment_type NOT IN ('pingback', 'trackback')
         GROUP BY comment_author
         ORDER BY comment_count DESC
         LIMIT %d",
        $limit
    ), ARRAY_A);

    if (empty($top_users)) {
        // 无数据时也显示自定义标题
        return sprintf(
            '<div class="comment-rank-top">
                <h3 class="rank-top-title">%s</h3>
                <p class="rank-top-empty">暂无符合条件的评论数据</p>
            </div>',
            $custom_title
        );
    }

    // 4. 构建HTML - 动态输出自定义标题
    $html = '<div class="comment-rank-top">';
    $html .= sprintf('<h3 class="rank-top-title">%s</h3>', $custom_title); // 替换固定标题为变量
    $html .= sprintf('<div class="rank-top-flex" style="--columns: %d;">', $columns);

    // 循环渲染卡片（不变，仅保留Top3类名逻辑）
    foreach ($top_users as $index => $user) {
        $comment_count = intval($user['comment_count']);
        $email = esc_attr($user['comment_author_email']);
        $name = esc_html($user['comment_author']);
        $url = esc_url($user['comment_author_url']);
        $rank = cur_get_user_rank($comment_count);

        // Top3专属类名
        $card_class = '';
        if ($index === 0) {
            $card_class = 'rank-card-1st';
            $rank_badge = '<span class="rank-top-badge rank-1st">🏆 第1名</span>';
        } elseif ($index === 1) {
            $card_class = 'rank-card-2nd';
            $rank_badge = '<span class="rank-top-badge rank-2nd">🥈 第2名</span>';
        } elseif ($index === 2) {
            $card_class = 'rank-card-3rd';
            $rank_badge = '<span class="rank-top-badge rank-3rd">🥉 第3名</span>';
        } else {
            $rank_badge = sprintf('<span class="rank-top-badge">第%d名</span>', $index + 1);
        }

        $avatar = get_avatar($email ?: $name, $avatar_size, '', $name, array(
            'class' => 'rank-top-avatar',
            'alt'   => $name . '的头像'
        ));

        $user_name_html = $url ? sprintf('<a href="%s" target="_blank" rel="nofollow">%s</a>', $url, $name) : $name;
        $rank_tag = sprintf('<span class="comment-user-rank %s">%s</span>', esc_attr($rank['class']), esc_html($rank['name']));

        // 单个用户卡片
        $html .= sprintf(
            '<div class="rank-top-card %s">
                %s
                <div class="rank-top-card-inner">
                    <div class="rank-top-avatar-wrap" style="width: %dpx; height: %dpx;">%s</div>
                    <div class="rank-top-name">%s</div>
                    <div class="rank-top-meta">
                        %s
                        <span class="rank-top-count">评论数：%d</span>
                    </div>
                </div>
            </div>',
            $card_class,
            $rank_badge,
            $avatar_size,
            $avatar_size,
            $avatar,
            $user_name_html,
            $rank_tag,
            $comment_count
        );
    }

    $html .= '</div></div>';

    return $html;
}

add_shortcode('comment_rank_top', 'cur_render_rank_top');