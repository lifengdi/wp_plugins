<?php
/**
 * Cached option lists pulled from ALAPI.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and caches the "今日热榜站点列表" and "头条类型" lists.
 *
 * These lists back the settings-page dropdowns. Each fetch spends one API call
 * from the account's daily quota, so results are cached for a week and only
 * refreshed when the admin explicitly asks for it.
 */
class HYZB_Lists {

	/**
	 * Transient key for the 今日热榜 site list.
	 */
	const TRANSIENT_TOPHUB = 'hyzb_tophub_sites';

	/**
	 * Transient key for the 网易新闻 category list.
	 */
	const TRANSIENT_TOUTIAO = 'hyzb_toutiao_types';

	/**
	 * How long a fetched list stays cached.
	 */
	const TTL = WEEK_IN_SECONDS;

	/**
	 * How many 今日热榜 sites to request. The endpoint pages at `page_size`.
	 */
	const TOPHUB_PAGE_SIZE = 500;

	/**
	 * 今日热榜 sites, grouped by site name.
	 *
	 * @param bool $force Bypass the cache and refetch.
	 * @return array|WP_Error Map of site name => list of array{id,title,category}.
	 */
	public static function tophub_sites( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_TOPHUB );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api  = new HYZB_Api( HYZB_Options::get( 'token' ) );
		$rows = $api->get_tophub_sites( self::TOPHUB_PAGE_SIZE );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$grouped = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || ! is_string( $row['id'] ) ) {
				continue;
			}

			$site = isset( $row['site'] ) && is_string( $row['site'] ) && '' !== trim( $row['site'] )
				? trim( $row['site'] )
				: __( '其他', 'daily-zaobao' );

			$title = isset( $row['title'] ) && is_string( $row['title'] ) ? trim( $row['title'] ) : '';

			// `title` already reads "微博 - 热搜榜"; fall back to the category alone.
			if ( '' === $title ) {
				$title = isset( $row['category'] ) && is_string( $row['category'] ) ? trim( $row['category'] ) : $row['id'];
			}

			$grouped[ $site ][] = array(
				'id'    => $row['id'],
				'title' => $title,
			);
		}

		if ( ! $grouped ) {
			return new WP_Error( 'hyzb_empty_list', __( '站点列表返回为空。', 'daily-zaobao' ) );
		}

		set_transient( self::TRANSIENT_TOPHUB, $grouped, self::TTL );

		return $grouped;
	}

	/**
	 * 网易新闻 categories.
	 *
	 * @param bool $force Bypass the cache and refetch.
	 * @return array|WP_Error List of array{type,name}.
	 */
	public static function toutiao_types( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_TOUTIAO );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api  = new HYZB_Api( HYZB_Options::get( 'token' ) );
		$rows = $api->get_toutiao_types();

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$types = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['type'] ) ) {
				continue;
			}

			$type = (string) $row['type'];

			if ( '' === $type ) {
				continue;
			}

			$types[] = array(
				'type' => $type,
				'name' => isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : $type,
			);
		}

		if ( ! $types ) {
			return new WP_Error( 'hyzb_empty_list', __( '头条类型列表返回为空。', 'daily-zaobao' ) );
		}

		set_transient( self::TRANSIENT_TOUTIAO, $types, self::TTL );

		return $types;
	}

	/**
	 * Drop both caches so the next settings-page load refetches them.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT_TOPHUB );
		delete_transient( self::TRANSIENT_TOUTIAO );
	}
}
