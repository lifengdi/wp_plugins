<?php
/**
 * Plugin Name: Chinese Almanac
 * Plugin URI: https://www.lifengdi.com/
 * Description: 显示中国传统黄历信息 [chinese_almanac]
 * Version: 1.0.0
 * Author: Dylan Li
 * Author URI: https://www.lifengdi.com/
 * License: GPL2
 */

// 禁止直接访问插件文件
if (!defined('ABSPATH')) {
    exit;
}

function chinese_almanac_enqueue_assets() {
    global $post;

    // 仅在包含目标短代码的页面加载资源（兼容非单页场景）
    $load_assets = false;
    if (is_singular() && is_a($post, 'WP_Post')) {
        $load_assets = has_shortcode($post->post_content, 'chinese_almanac');
    }

    if ($load_assets) {
        // 加载CSS
        wp_enqueue_style(
            'chinese-almanac-style',
            plugins_url('css/tyme.css', __FILE__),
            array(),
            '1.0',
            'all'
        );

        // 1. 加载Vue（不依赖jQuery）
        wp_enqueue_script(
            'chinese-almanac-vue',
            plugins_url('js/vue.min.js', __FILE__),
            array(), // Vue无需依赖jQuery，清空依赖
            '3.3.4', // 标注真实Vue版本，便于缓存控制
            true
        );

        // 2. 加载Tyme（依赖Vue）
        wp_enqueue_script(
            'chinese-almanac-tyme',
            plugins_url('js/tyme.min.js', __FILE__),
            array('chinese-almanac-vue'), // 仅依赖Vue
            '1.0',
            true
        );

        // 3. 加载业务UI（依赖Tyme）
        wp_enqueue_script(
            'chinese-almanac-ui',
            plugins_url('js/show.js', __FILE__),
            array('chinese-almanac-tyme'), // 仅依赖Tyme
            '1.0',
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'chinese_almanac_enqueue_assets');


// 注册简码
add_shortcode('chinese_almanac', 'display_chinese_almanac');

/**
 * 显示黄历信息的简码函数
 */
function display_chinese_almanac($atts) {
    // 解析参数
    $atts = shortcode_atts(array(
        'date' => '',
        'format' => 'Y-m-d'
    ), $atts);
    ob_start();
        ?>
        <div id="demo-huangli">
          <div class="solar">{{solar}}</div>
          <div class="lunar">{{lunar}}</div>
          <div class="gz">{{gz}}</div>
          <div class="yi"><i v-for="o in yi">{{o}}</i></div>
          <div class="ji"><i v-for="o in ji">{{o}}</i></div>
          <table>
            <tbody>
              <tr>
                <td colspan="2"><div><b>纳音</b><i>{{sound}}</i></div></td>
                <td><div><b>冲煞</b><i>冲{{chong}} 煞{{sha}}</i></div></td>
                <td colspan="2"><div><b>值神</b><i>{{twelveStar}}</i></div></td>
              </tr>
              <tr>
                <td style="width: 12.5%"><div><b style="width: 2em;">时辰吉凶</b></div></td>
                <td colspan="4" class="v h"><div><u v-for="o in hours"><i>{{o}}</i></u></div></td>
              </tr>
              <tr>
                <td rowspan="2" class="v h"><div><b>建除十二神</b><i>{{duty}}</i></div></td>
                <td style="width: 25%"><div><b>吉神宜趋</b><ul><li v-for="o in god.ji">{{o}}</li></ul></div></td>
                <td><div><b>今日胎神</b><i>{{fetus}}</i></div></td>
                <td style="width: 25%"><div><b>凶神宜忌</b><ul><li v-for="o in god.xiong">{{o}}</li></ul></div></td>
                <td rowspan="2" class="v h" style="width: 12.5%"><div><b>二十八星宿</b><i>{{twentyEightStar}}</i></div></td>
              </tr>
              <tr>
                <td colspan="3"><div><b>彭祖百忌</b><i v-for="o in pz">{{o}}</i></div></td>
              </tr>
            </tbody>
          </table>
        </div>


        <?php
        return ob_get_clean();
    } catch (Exception $e) {
        return "<p>无法获取黄历信息: " . $e->getMessage() . "</p>";
    }
}
