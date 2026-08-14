<?php
/**
 * Settings storage and sanitisation.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the single option array used by the plugin.
 */
class HYZB_Options {

	const SOURCE_ZAOBAO  = 'zaobao';
	const SOURCE_ZHIHU   = 'zhihu';
	const SOURCE_WBTOP   = 'wbtop';
	const SOURCE_TOUTIAO = 'toutiao';
	const SOURCE_TOPHUB  = 'tophub';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'token'         => '',
			'sources'       => array( self::SOURCE_ZAOBAO ),
			'run_time'      => '07:30',
			'post_status'   => 'publish',
			'post_author'   => 0,
			'post_category' => 0,
			'title_format'  => '每日早报 · {date}',
			'show_weiyu'    => 1,
			'show_images'   => 1,
			'show_hint'     => 1,
			'attribution'   => 1,
			'wbtop_num'     => 20,
			'toutiao_type'  => '1',
			'toutiao_limit' => 15,
			'tophub_id'     => '',
			'tophub_type'   => 'weibo',
			'tophub_limit'  => 20,
		);
	}

	/**
	 * Source definitions: label, ALAPI doc id and per-source notes.
	 *
	 * @return array
	 */
	public static function sources() {
		return array(
			self::SOURCE_ZAOBAO  => array(
				'label'  => '每日早报',
				'doc_id' => 67,
				'note'   => '15 条全球新闻速报 + 每日微语。纯文字，接口不提供逐条原文链接。',
				'links'  => false,
			),
			self::SOURCE_ZHIHU   => array(
				'label'  => '知乎日报',
				'doc_id' => 15,
				'note'   => '头条 + 今日推荐，每条带原文链接与配图。',
				'links'  => true,
			),
			self::SOURCE_WBTOP   => array(
				'label'  => '微博热搜榜',
				'doc_id' => 16,
				'note'   => '热搜词 + 热度值，每条链接到微博搜索结果页。',
				'links'  => true,
			),
			self::SOURCE_TOUTIAO => array(
				'label'  => '网易新闻头条',
				'doc_id' => 18,
				'note'   => '标题 + 摘要 + 来源 + 配图，每条带原文链接。',
				'links'  => true,
			),
			self::SOURCE_TOPHUB  => array(
				'label'  => '今日热榜',
				'doc_id' => 29,
				'note'   => '聚合站点热榜（微博、抖音、知乎等），每条带原文链接。',
				'links'  => true,
			),
		);
	}

	/**
	 * Human label for a source slug.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	public static function source_label( $slug ) {
		$sources = self::sources();

		return isset( $sources[ $slug ] ) ? $sources[ $slug ]['label'] : $slug;
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( HYZB_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( self::defaults(), $stored );

		// A run with no source selected would create an empty post, so fall back.
		$merged['sources'] = self::filter_sources( $merged['sources'] );

		if ( ! $merged['sources'] ) {
			$merged['sources'] = array( self::SOURCE_ZAOBAO );
		}

		return $merged;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value to fall back to when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Keep only known source slugs, in the canonical display order.
	 *
	 * @param mixed $value Raw list of slugs.
	 * @return array
	 */
	public static function filter_sources( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$value = array_map( 'sanitize_key', $value );

		return array_values( array_intersect( array_keys( self::sources() ), $value ) );
	}

	/**
	 * Sanitise a submitted settings array.
	 *
	 * The token is trimmed but otherwise stored as given; it is a credential, so
	 * it is never echoed back into the page or written to the run log.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$existing = self::all();
		$clean    = array();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// An empty token field means "keep the stored token" so the value never
		// has to be re-typed just to change an unrelated setting.
		$token          = isset( $input['token'] ) ? trim( wp_unslash( $input['token'] ) ) : '';
		$clean['token'] = '' === $token ? $existing['token'] : $token;

		$clean['sources'] = self::filter_sources( isset( $input['sources'] ) ? $input['sources'] : array() );

		if ( ! $clean['sources'] ) {
			$clean['sources'] = $defaults['sources'];
		}

		$clean['run_time'] = self::sanitize_time( isset( $input['run_time'] ) ? $input['run_time'] : '' );

		$statuses             = array( 'publish', 'draft', 'pending', 'private' );
		$status               = isset( $input['post_status'] ) ? sanitize_key( $input['post_status'] ) : '';
		$clean['post_status'] = in_array( $status, $statuses, true ) ? $status : $defaults['post_status'];

		$clean['post_author']   = isset( $input['post_author'] ) ? absint( $input['post_author'] ) : 0;
		$clean['post_category'] = isset( $input['post_category'] ) ? absint( $input['post_category'] ) : 0;

		$title = isset( $input['title_format'] ) ? sanitize_text_field( wp_unslash( $input['title_format'] ) ) : '';
		if ( '' === $title || false === strpos( $title, '{date}' ) ) {
			// Without {date} every edition would collide on the same title.
			$title = $defaults['title_format'];
		}
		$clean['title_format'] = $title;

		foreach ( array( 'show_weiyu', 'show_images', 'show_hint', 'attribution' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// 微博热搜榜: the endpoint caps `num` at 50.
		$clean['wbtop_num'] = self::clamp( isset( $input['wbtop_num'] ) ? $input['wbtop_num'] : 0, 1, 50, $defaults['wbtop_num'] );

		$toutiao_type          = isset( $input['toutiao_type'] ) ? preg_replace( '/\D/', '', (string) $input['toutiao_type'] ) : '';
		$clean['toutiao_type'] = '' === $toutiao_type ? $defaults['toutiao_type'] : $toutiao_type;

		$clean['toutiao_limit'] = self::clamp( isset( $input['toutiao_limit'] ) ? $input['toutiao_limit'] : 0, 1, 50, $defaults['toutiao_limit'] );

		// /api/tophub takes `id` or `type`. The dropdown yields an `id`; the text
		// field stays as a fallback for when the site list cannot be loaded.
		$clean['tophub_id'] = isset( $input['tophub_id'] ) ? sanitize_text_field( wp_unslash( $input['tophub_id'] ) ) : '';

		$tophub_type          = isset( $input['tophub_type'] ) ? sanitize_text_field( wp_unslash( $input['tophub_type'] ) ) : '';
		$clean['tophub_type'] = '' === $tophub_type ? $defaults['tophub_type'] : $tophub_type;

		$clean['tophub_limit'] = self::clamp( isset( $input['tophub_limit'] ) ? $input['tophub_limit'] : 0, 1, 50, $defaults['tophub_limit'] );

		return $clean;
	}

	/**
	 * Clamp an integer into a range.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value used when the input is not a positive integer.
	 * @return int
	 */
	private static function clamp( $value, $min, $max, $fallback ) {
		$value = absint( $value );

		if ( 0 === $value ) {
			return $fallback;
		}

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Normalise an HH:MM string, falling back to the default on bad input.
	 *
	 * @param string $value Raw time value.
	 * @return string
	 */
	public static function sanitize_time( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value ) ) {
			return $value;
		}

		$defaults = self::defaults();

		return $defaults['run_time'];
	}
}
