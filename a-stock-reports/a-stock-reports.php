<?php
/**
 * Plugin Name: A-Stock Reports
 * Description: 从 Git 仓库同步 A 股复盘报告，按年月展示 HTML 报告列表。
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: a-stock-reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASR_REPO_DIR', WP_CONTENT_DIR . '/a-stock-repo' );

require_once ASR_PLUGIN_DIR . 'includes/class-asr-git-sync.php';
require_once ASR_PLUGIN_DIR . 'includes/class-asr-admin.php';
require_once ASR_PLUGIN_DIR . 'includes/class-asr-frontend.php';

register_activation_hook( __FILE__, 'asr_activate' );
register_deactivation_hook( __FILE__, 'asr_deactivate' );

function asr_activate() {
	$defaults = array(
		'asr_repo_url'   => 'git@github.com:lifengdi/a-stock.git',
		'asr_sync_time'  => '06:00',
		'asr_reports_subdir' => 'reports',
	);
	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
	if ( ! wp_next_scheduled( 'asr_daily_sync_event' ) ) {
		$time_parts = explode( ':', get_option( 'asr_sync_time', '06:00' ) );
		$hour       = (int) $time_parts[0];
		$minute     = isset( $time_parts[1] ) ? (int) $time_parts[1] : 0;
		$timestamp  = strtotime( "today {$hour}:{$minute}" );
		if ( $timestamp < time() ) {
			$timestamp = strtotime( "tomorrow {$hour}:{$minute}" );
		}
		wp_schedule_event( $timestamp, 'daily', 'asr_daily_sync_event' );
	}
}

function asr_deactivate() {
	wp_clear_scheduled_hook( 'asr_daily_sync_event' );
}

add_action( 'asr_daily_sync_event', array( 'ASR_Git_Sync', 'sync' ) );

ASR_Admin::init();
ASR_Frontend::init();
