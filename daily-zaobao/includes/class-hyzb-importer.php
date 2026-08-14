<?php
/**
 * Fetch, de-duplicate and insert the daily post.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates one import run.
 */
class HYZB_Importer {

	/**
	 * Post meta key holding the unique edition identifier (Ymd).
	 */
	const META_EDITION = '_hyzb_edition';

	/**
	 * Post meta key holding the source slugs that produced the post.
	 */
	const META_SOURCES = '_hyzb_sources';

	/**
	 * Run one import.
	 *
	 * A source that errors out does not abort the run: the post is built from
	 * whatever succeeded, and the failures are returned for the log. Only a run
	 * where every source failed is treated as an error.
	 *
	 * @return array|WP_Error {
	 *     @type int    $post_id Post ID.
	 *     @type string $edition Edition key (Ymd).
	 *     @type bool   $skipped True when the edition already existed.
	 *     @type array  $ok      Slugs that produced content.
	 *     @type array  $failed  Map of slug => error message.
	 * }
	 */
	public static function run() {
		$options = HYZB_Options::all();
		$api     = new HYZB_Api( $options['token'] );

		$payloads = array();
		$failed   = array();

		foreach ( $options['sources'] as $slug ) {
			$data = $api->fetch_source( $slug, $options );

			if ( is_wp_error( $data ) ) {
				$failed[ $slug ] = $data->get_error_message();
				continue;
			}

			$payloads[ $slug ] = $data;
		}

		if ( ! $payloads ) {
			return new WP_Error(
				'hyzb_all_sources_failed',
				sprintf(
					/* translators: %s: list of "source: error" strings. */
					__( '所有数据源都抓取失败：%s', 'daily-zaobao' ),
					self::format_failures( $failed )
				)
			);
		}

		$edition = self::resolve_edition_date( $payloads );

		$existing = self::find_by_edition( $edition );

		if ( $existing ) {
			return array(
				'post_id' => $existing,
				'edition' => $edition,
				'skipped' => true,
				'ok'      => array_keys( $payloads ),
				'failed'  => $failed,
			);
		}

		$renderer = new HYZB_Renderer( $options );
		$sections = '';
		$rendered = array();

		// More than one section means the reader needs headings to tell them apart.
		$with_headers = count( $payloads ) > 1;

		foreach ( $payloads as $slug => $data ) {
			$section = $renderer->render_section( $slug, $data, $with_headers );

			if ( '' !== $section ) {
				$sections .= $section;
				$rendered[] = $slug;
			}
		}

		if ( '' === trim( $sections ) ) {
			return new WP_Error( 'hyzb_empty_content', __( '接口返回的数据里没有可用条目，本次未创建文章。', 'daily-zaobao' ) );
		}

		$content = $sections . $renderer->render_attribution( $rendered );

		$postarr = array(
			'post_title'   => self::build_title( $options['title_format'], $edition ),
			'post_content' => $content,
			'post_status'  => $options['post_status'],
			'post_type'    => 'post',
		);

		if ( $options['post_author'] ) {
			$postarr['post_author'] = (int) $options['post_author'];
		}

		if ( $options['post_category'] ) {
			$postarr['post_category'] = array( (int) $options['post_category'] );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_EDITION, $edition );
		update_post_meta( $post_id, self::META_SOURCES, implode( ',', $rendered ) );

		return array(
			'post_id' => (int) $post_id,
			'edition' => $edition,
			'skipped' => false,
			'ok'      => $rendered,
			'failed'  => $failed,
		);
	}

	/**
	 * Turn a slug => message map into a readable string.
	 *
	 * @param array $failed Failure map.
	 * @return string
	 */
	public static function format_failures( array $failed ) {
		$parts = array();

		foreach ( $failed as $slug => $message ) {
			$parts[] = HYZB_Options::source_label( $slug ) . '：' . $message;
		}

		return implode( '；', $parts );
	}

	/**
	 * Decide which date this edition belongs to.
	 *
	 * 每日早报 and 知乎日报 report their own edition date; the ranking endpoints do
	 * not. Prefer a reported date so the title matches the content, and fall back
	 * to the site's local date otherwise.
	 *
	 * @param array $payloads Map of slug => payload.
	 * @return string Date as Ymd.
	 */
	private static function resolve_edition_date( array $payloads ) {
		foreach ( array( HYZB_Options::SOURCE_ZAOBAO, HYZB_Options::SOURCE_ZHIHU ) as $slug ) {
			if ( ! isset( $payloads[ $slug ]['date'] ) ) {
				continue;
			}

			$date = self::normalize_date( $payloads[ $slug ]['date'] );

			if ( '' !== $date ) {
				return $date;
			}
		}

		return wp_date( 'Ymd' );
	}

	/**
	 * Look up a post already imported for this edition.
	 *
	 * Any post status counts, including trashed ones, so a manually deleted
	 * edition is not silently re-created on the next run.
	 *
	 * @param string $edition Edition identifier.
	 * @return int Post ID, or 0 when not found.
	 */
	private static function find_by_edition( $edition ) {
		$posts = get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => 'any',
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => self::META_EDITION,
				'meta_value'    => $edition,
			)
		);

		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Normalise a feed date into Ymd.
	 *
	 * The endpoints disagree: 每日早报 returns "2026-08-14", 知乎日报 "20260814".
	 * Both collapse to the same key here so the dedup check is stable.
	 *
	 * @param mixed $raw Date value from the payload.
	 * @return string Date as Ymd, or '' when unparseable.
	 */
	public static function normalize_date( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$digits = preg_replace( '/\D/', '', $raw );

		return ( is_string( $digits ) && 8 === strlen( $digits ) ) ? $digits : '';
	}

	/**
	 * Expand the title template.
	 *
	 * @param string $format  Title format containing {date}.
	 * @param string $edition Edition date as Ymd.
	 * @return string
	 */
	private static function build_title( $format, $edition ) {
		$timestamp = strtotime( $edition . ' 00:00:00' );
		$display   = $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $edition;

		return str_replace( '{date}', $display, $format );
	}
}
