<?php
/**
 * Daily schedule handling.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the daily WP-Cron event and records the outcome of each run.
 */
class HYZB_Cron {

	/**
	 * Hook the cron callback.
	 */
	public static function init() {
		add_action( HYZB_CRON_HOOK, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Cron callback: run the import and store the result for the settings page.
	 */
	public static function handle() {
		$result = HYZB_Importer::run();

		self::log( $result, 'cron' );
	}

	/**
	 * Persist the outcome of a run.
	 *
	 * Only messages are stored — never the token or the raw API response.
	 *
	 * @param array|WP_Error $result  Return value of HYZB_Importer::run().
	 * @param string         $trigger Either 'cron' or 'manual'.
	 * @return array The stored log entry.
	 */
	public static function log( $result, $trigger ) {
		$entry = array(
			'time'    => time(),
			'trigger' => $trigger,
		);

		if ( is_wp_error( $result ) ) {
			$entry['status']  = 'error';
			$entry['message'] = $result->get_error_message();
			$entry['post_id'] = 0;
		} else {
			$entry['status']  = $result['skipped'] ? 'skipped' : 'success';
			$entry['post_id'] = (int) $result['post_id'];
			$entry['edition'] = $result['edition'];
			$entry['ok']      = $result['ok'];

			if ( ! empty( $result['failed'] ) ) {
				// A partial success still needs to surface which sources failed.
				$entry['message'] = HYZB_Importer::format_failures( $result['failed'] );
				$entry['status']  = $result['skipped'] ? 'skipped' : 'partial';
			}
		}

		update_option( HYZB_LAST_RUN_OPTION, $entry, false );

		return $entry;
	}

	/**
	 * The stored result of the most recent run, if any.
	 *
	 * @return array|null
	 */
	public static function last_run() {
		$entry = get_option( HYZB_LAST_RUN_OPTION );

		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Drop and re-create the daily event using the configured run time.
	 */
	public static function reschedule() {
		self::unschedule();

		wp_schedule_event( self::next_timestamp(), 'daily', HYZB_CRON_HOOK );
	}

	/**
	 * Remove every queued instance of the event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( HYZB_CRON_HOOK );
	}

	/**
	 * Timestamp of the next scheduled run, or 0 when not scheduled.
	 *
	 * @return int
	 */
	public static function next_run() {
		return (int) wp_next_scheduled( HYZB_CRON_HOOK );
	}

	/**
	 * The next occurrence of the configured HH:MM, in the site's timezone.
	 *
	 * @return int UTC timestamp.
	 */
	private static function next_timestamp() {
		$run_time = HYZB_Options::get( 'run_time' );

		list( $hour, $minute ) = array_map( 'intval', explode( ':', $run_time ) );

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$target   = $now->setTime( $hour, $minute, 0 );

		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}

		return $target->getTimestamp();
	}
}
