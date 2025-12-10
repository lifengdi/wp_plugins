<?php
/*
Plugin Name: 历史上的今天
Plugin URI: https://www.lifengdi.com/
Description: 展示中外历史上的今天发生的重大事件（支持接口异步查询详情）
Version: 1.5.1
Author: Dylan Li
Author URI: https://www.lifengdi.com/
License: GPL2
Text Domain: history-today
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 注册REST API接口：根据事件ID查询详情
 */
function ht_register_event_detail_api() {
    register_rest_route(
        'history-today/v1',
        '/event/(?P<id>\d+)',
        [
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'ht_get_event_detail_api_callback',
            'permission_callback' => '__return_true', // 公开访问（历史事件无需权限）
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]
    );
}
add_action('rest_api_init', 'ht_register_event_detail_api');

/**
 * 核心：Markdown转HTML（轻量级，处理常见标签）
 * @param string $md 原始Markdown内容
 * @return string 解析后的HTML
 */
function ht_markdown_to_html($md) {
    if (empty($md)) return '';

    // 2. 块级元素处理
    // 无序列表（- 开头）
    $md = preg_replace_callback('/^(\s*)- (.*)$/m', function($matches) {
        return $matches[1] . '<li>' . $matches[2] . '</li>';
    }, $md);
    // 包裹列表项为<ul>
    $md = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $md);

    // 标题（#/## 开头，降级为h4/h5避免破坏页面结构）
    $md = preg_replace('/^## (.*)$/m', '<h4>$1</h4>', $md);
    $md = preg_replace('/^# (.*)$/m', '<h3>$1</h3>', $md);

    // 3. 行内元素处理
    $md = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $md); // 加粗
    $md = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $md); // 斜体
    $md = preg_replace('/`(.*?)`/', '<code>$1</code>', $md); // 行内代码
    // 链接 [文本](链接) → <a>
    $md = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $md);

    // 4. 换行处理（保留单换行，合并多换行）
    $md = preg_replace('/\n{2,}/', '</p><p>', $md); // 空行分隔段落
    $md = preg_replace('/\n/', '<br>', $md); // 单换行
    $md = '<p>' . $md . '</p>'; // 包裹段落

    return $md;
}

/**
 * API回调函数：查询事件详情
 */
function ht_get_event_detail_api_callback($request) {
    global $wpdb;
    $event_id = intval($request['id']);
    $table_name = $wpdb->prefix . 'history_today';

    // 查询事件详情
    $event = $wpdb->get_row(
        $wpdb->prepare("SELECT event_desc FROM $table_name WHERE id = %d", $event_id),
        ARRAY_A
    );

    if (!$event) {
        return new WP_Error(
            'no_event',
            '未找到该事件',
            ['status' => 404]
        );
    }

    // 安全处理富文本（保留原逻辑，可后续添加清理<br>的函数）
    $desc = ht_safe_rich_text($event['event_desc'] ?? '');

    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'desc' => $desc
        ]
    ], 200);
}

/**
 * 获取今日历史事件（仅基础信息，不含desc）
 * @return array 事件列表
 */
function ht_get_today_events() {
    global $wpdb;
    $today = date('md');

    // 处理闰年2月29日，平年默认取2月28日
    if ($today == '0229' && !date('L')) {
        $today = '0228';
    }

    $table_name = $wpdb->prefix . 'history_today';
    // 仅查询基础字段，desc通过接口异步获取
    $events = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, event_year, event_content FROM $table_name WHERE month_day = %s ORDER BY event_year DESC",
            $today
        ),
        ARRAY_A
    );

    return $events ?: [];
}

/**
 * 处理事件列表：根据参数随机抽取N条或显示全部
 * @param array $events 原始事件列表
 * @param array $atts 短代码参数
 * @return array 处理后的事件列表
 */
function ht_process_events($events, $atts) {
    $limit = isset($atts['limit']) ? intval($atts['limit']) : 0;
    $show_all = isset($atts['show_all']) ? filter_var($atts['show_all'], FILTER_VALIDATE_BOOLEAN) : false;

    if ($show_all || empty($limit) || count($events) <= $limit) {
        return $events;
    }

    $random_keys = array_rand($events, $limit);
    $random_events = [];

    if (!is_array($random_keys)) {
        $random_events[] = $events[$random_keys];
    } else {
        foreach ($random_keys as $key) {
            $random_events[] = $events[$key];
        }
    }

    usort($random_events, function($a, $b) {
        $yearA = is_numeric($a['event_year']) ? intval($a['event_year']) : 0;
        $yearB = is_numeric($b['event_year']) ? intval($b['event_year']) : 0;
        return $yearB - $yearA;
    });

    return $random_events;
}

/**
 * 安全渲染富文本内容
 * @param string $content 原始富文本内容
 * @return string 安全的HTML内容
 */
function ht_safe_rich_text($content) {
    if (empty($content)) return '';

	$html = ht_markdown_to_html($content);

    return $html;
}

/**
 * 短代码处理函数：异步加载详情（恢复desc_type="show"逻辑）
 * @param array $atts 短代码参数
 * @return string HTML内容
 */
function ht_history_today_shortcode($atts) {
    $atts = shortcode_atts([
        'limit'     => 0,
        'show_all'  => false,
        'show_desc' => false,
        'desc_type' => 'fold', // fold=折叠（默认），show=直接显示
        'rich_text' => true,
        'show_more_link' => ''
    ], $atts);

    // 参数类型转换
    $atts['limit'] = intval($atts['limit']);
    $atts['show_all'] = filter_var($atts['show_all'], FILTER_VALIDATE_BOOLEAN);
    $atts['show_desc'] = filter_var($atts['show_desc'], FILTER_VALIDATE_BOOLEAN);
    $atts['rich_text'] = filter_var($atts['rich_text'], FILTER_VALIDATE_BOOLEAN);
    $atts['desc_type'] = in_array($atts['desc_type'], ['fold', 'show']) ? $atts['desc_type'] : 'fold';
    // 简码配置的显示更多URL
    $atts['show_more_link'] = $atts['show_more_link'] ? esc_url($atts['show_more_link']) : '';

    // 获取事件列表
    $original_events = ht_get_today_events();
    $events = ht_process_events($original_events, $atts);

    $today = date('m月d日');
    $total_count = count($original_events);
    $show_count = count($events);

    // 构建HTML容器
    $html = '<div class="history-today-wrapper">';
    $html .= '<h3 class="history-today-title">历史上的今天（'.$today.'）</h3>';

    if (empty($events)) {
        $html .= '<p class="history-today-empty">暂无相关历史事件记录</p>';
    } else {

        $html .= '<div class="history-today-all-events">';
        $html .= '<ul>';

        // 循环渲染事件
        foreach ($events as $index => $event) {
            $event_id = 'ht-event-' . $event['id'];
            $year = esc_html($event['event_year']);
            $content = esc_html($event['event_content']);

            $html .= '<li class="history-today-event-item">';
            $html .= '<strong class="event-year">'.$year.'年</strong>：';
            $html .= '<span class="event-content">'.$content.'</span>';

            // 仅当show_desc为true时处理详情
            if ($atts['show_desc']) {
                // 区分desc_type：fold（折叠）/ show（直接显示）
                if ($atts['desc_type'] === 'fold') {
                    // 折叠模式：显示按钮，点击加载
                    $html .= '<button class="ht-desc-toggle"
                                data-event-id="'.$event['id'].'"
                                data-target="'.$event_id.'"
                                type="button">查看详情</button>';
                    $html .= '<div id="'.$event_id.'" class="event-desc fold-desc">
                                <div class="desc-loading" style="display:none;">加载中...</div>
                                <div class="desc-content"></div>
                                <div class="desc-error" style="color:#e74c3c;display:none;">加载失败，请重试</div>
                              </div>';
                } elseif ($atts['desc_type'] === 'show') {
                    // 直接显示模式：无按钮，默认展开，自动加载
                    $html .= '<div id="'.$event_id.'" class="event-desc show-desc">
                                <div class="desc-loading">加载中...</div>
                                <div class="desc-content"></div>
                                <div class="desc-error" style="color:#e74c3c;display:none;">加载失败，请重试</div>
                              </div>';
                }
            }

            $html .= '</li>';
        }
        // 左下角显示查看更多按钮
        if ($atts['show_more_link']) {
            $html .= '<li class="history-today-event-item">';
                $html .= '<a style="justify-content: flex-end;display: flex;" target="_blank" href="'.$atts['show_more_link'].'">查看更多</a>';
            $html .= '</li>';
        }

        $html .= '</ul></div>';
    }

    $html .= '</div>';

    // 异步加载详情的JS（同时处理fold和show模式）
    if ($atts['show_desc']) {
        // 获取REST API根地址
        $api_url = get_rest_url(null, 'history-today/v1/event/');
        $html .= <<<JS
        <script>
        (function() {
            if (window.htDescLoaded) return;
            window.htDescLoaded = true;

            // 全局缓存已加载的详情，避免重复请求
            const descCache = {};
            const apiBaseUrl = "{$api_url}";

            // 通用加载详情函数
            const loadEventDesc = async (eventId, targetEl) => {
                if (!targetEl) return false;

                const loadingEl = targetEl.querySelector('.desc-loading');
                const contentEl = targetEl.querySelector('.desc-content');
                const errorEl = targetEl.querySelector('.desc-error');

                // 已缓存则直接渲染
                if (descCache[eventId]) {
                    contentEl.innerHTML = descCache[eventId];
                    loadingEl.style.display = "none";
                    return true;
                }

                try {
                    // 调用API
                    const response = await fetch(apiBaseUrl + eventId);
                    const result = await response.json();

                    if (result.success && result.data.desc) {
                        descCache[eventId] = result.data.desc;
                        contentEl.innerHTML = result.data.desc;
                        loadingEl.style.display = "none";
                        return true;
                    } else {
                        errorEl.style.display = "block";
                        loadingEl.style.display = "none";
                        return false;
                    }
                } catch (e) {
                    console.error("加载事件详情失败（ID：" + eventId + "）：", e);
                    errorEl.style.display = "block";
                    loadingEl.style.display = "none";
                    return false;
                }
            };

            document.addEventListener("DOMContentLoaded", function() {
                // 1. 处理折叠模式（fold）：点击按钮加载
                const foldToggles = document.querySelectorAll(".ht-desc-toggle");
                foldToggles.forEach(toggle => {
                    toggle.addEventListener("click", async function() {
                        const btn = this;
                        const eventId = btn.dataset.eventId;
                        const targetId = btn.dataset.target;
                        const targetEl = document.getElementById(targetId);

                        if (!targetEl) return;

                        // 切换折叠状态
                        const isActive = targetEl.classList.contains('active');

                        // 收起逻辑
                        if (isActive) {
                            targetEl.classList.remove('active');
                            btn.textContent = "查看详情";
                            targetEl.style.maxHeight = "0";
                            return;
                        }

                        // 展开逻辑
                        btn.disabled = true;
                        btn.textContent = "加载中...";
                        targetEl.querySelector('.desc-loading').style.display = "block";

                        // 加载详情
                        const loadSuccess = await loadEventDesc(eventId, targetEl);

                        // 更新UI状态
                        if (loadSuccess) {
                            targetEl.classList.add('active');
                            targetEl.style.maxHeight = targetEl.scrollHeight + "px";
                            btn.textContent = "收起详情";
                        } else {
                            btn.textContent = "查看详情";
                        }
                        btn.disabled = false;
                    });
                });

                // 2. 处理直接显示模式（show）：页面加载后自动加载
                const showDescElements = document.querySelectorAll(".show-desc");
                showDescElements.forEach(el => {
                    const eventId = el.id.replace('ht-event-', '');
                    // 自动加载详情
                    loadEventDesc(eventId, el);
                });
            });
        })();
        </script>
JS;
    }

    return $html;
}
add_shortcode('history_today', 'ht_history_today_shortcode');

/**
 * 加载前端样式
 */
function ht_enqueue_styles() {
    wp_enqueue_style(
        'history-today-style',
        plugins_url('css/style.css', __FILE__),
        [],
        '1.5.1',
        'all'
    );
}
add_action('wp_enqueue_scripts', 'ht_enqueue_styles');

// 插件停用钩子
register_deactivation_hook(__FILE__, 'ht_deactivate_plugin');
function ht_deactivate_plugin() {}
?>