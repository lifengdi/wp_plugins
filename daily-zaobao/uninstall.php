<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's own options and its scheduled event. Generated posts are
 * left in place — they are site content, not plugin state.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'hyzb_settings' );
delete_option( 'hyzb_last_run' );

delete_transient( 'hyzb_tophub_sites' );
delete_transient( 'hyzb_toutiao_types' );

wp_clear_scheduled_hook( 'hyzb_daily_fetch' );
