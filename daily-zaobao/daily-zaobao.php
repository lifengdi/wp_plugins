<?php
/**
 * Plugin Name:       每日早报自动发布
 * Plugin URI:        https://bbs.lifengdi.com/
 * Description:       每天定时通过 ALAPI 抓取每日早报、知乎日报、微博热搜榜、网易新闻头条、今日热榜，整合为一篇文章发布。图片与媒体全部使用原始外链，不落本地媒体库。
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Dylan Li
 * Author URI:        https://www.lifengdi.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       daily-zaobao
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HYZB_VERSION', '1.3.0' );
define( 'HYZB_FILE', __FILE__ );
define( 'HYZB_DIR', plugin_dir_path( __FILE__ ) );
define( 'HYZB_URL', plugin_dir_url( __FILE__ ) );
define( 'HYZB_OPTION', 'hyzb_settings' );
define( 'HYZB_LAST_RUN_OPTION', 'hyzb_last_run' );
define( 'HYZB_CRON_HOOK', 'hyzb_daily_fetch' );

// ALAPI referral link. Used for the post footer credit and the token field hint.
define( 'HYZB_ALAPI_URL', 'https://www.alapi.cn/aff/xh7den' );
define( 'HYZB_AUTHOR_URL', 'https://www.lifengdi.com/' );
define( 'HYZB_SUPPORT_URL', 'https://bbs.lifengdi.com/' );

require_once HYZB_DIR . 'includes/class-hyzb-options.php';
require_once HYZB_DIR . 'includes/class-hyzb-api.php';
require_once HYZB_DIR . 'includes/class-hyzb-lists.php';
require_once HYZB_DIR . 'includes/class-hyzb-renderer.php';
require_once HYZB_DIR . 'includes/class-hyzb-importer.php';
require_once HYZB_DIR . 'includes/class-hyzb-cron.php';
require_once HYZB_DIR . 'includes/class-hyzb-admin.php';

/**
 * Boot the plugin once WordPress has loaded translations.
 */
function hyzb_init() {
	load_plugin_textdomain( 'daily-zaobao', false, dirname( plugin_basename( HYZB_FILE ) ) . '/languages' );

	HYZB_Cron::init();

	if ( is_admin() ) {
		HYZB_Admin::init();
	}
}
add_action( 'plugins_loaded', 'hyzb_init' );

/**
 * Load the layout stylesheet, but only where a generated post is being shown.
 *
 * The main query is scanned rather than just checking is_singular(), because many
 * themes render full post content on the blog index and on archives too.
 */
function hyzb_enqueue_styles() {
	global $wp_query;

	if ( ! isset( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
		return;
	}

	$found = false;

	foreach ( $wp_query->posts as $post ) {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;

		if ( $post_id && get_post_meta( $post_id, HYZB_Importer::META_EDITION, true ) ) {
			$found = true;
			break;
		}
	}

	if ( ! $found ) {
		return;
	}

	wp_enqueue_style(
		'hyzb-layout',
		HYZB_URL . 'assets/hyzb.css',
		array(),
		HYZB_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'hyzb_enqueue_styles' );

/**
 * Schedule the daily event on activation.
 */
function hyzb_activate() {
	HYZB_Cron::reschedule();
}
register_activation_hook( HYZB_FILE, 'hyzb_activate' );

/**
 * Clear the daily event on deactivation. Settings and posts are left alone.
 */
function hyzb_deactivate() {
	HYZB_Cron::unschedule();
}
register_deactivation_hook( HYZB_FILE, 'hyzb_deactivate' );
