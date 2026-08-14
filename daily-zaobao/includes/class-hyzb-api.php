<?php
/**
 * ALAPI HTTP client.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to https://v3.alapi.cn.
 *
 * Auth follows the published OpenAPI spec: the token travels in a `token`
 * request header, never in the query string, so it stays out of access logs.
 */
class HYZB_Api {

	/**
	 * API base URL.
	 */
	const BASE_URL = 'https://v3.alapi.cn';

	/**
	 * API token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token ALAPI token.
	 */
	public function __construct( $token ) {
		$this->token = (string) $token;
	}

	/**
	 * Fetch one source by slug, applying its configured parameters.
	 *
	 * @param string $slug    Source slug from HYZB_Options.
	 * @param array  $options Settings array.
	 * @return array|WP_Error Payload from `data`, or an error.
	 */
	public function fetch_source( $slug, array $options ) {
		switch ( $slug ) {
			case HYZB_Options::SOURCE_ZAOBAO:
				return $this->post( '/api/zaobao', array( 'format' => 'json' ) );

			case HYZB_Options::SOURCE_ZHIHU:
				return $this->post( '/api/zhihu', array() );

			case HYZB_Options::SOURCE_WBTOP:
				return $this->post(
					'/api/new/wbtop',
					array( 'num' => (string) $options['wbtop_num'] )
				);

			case HYZB_Options::SOURCE_TOUTIAO:
				return $this->post(
					'/api/new/toutiao',
					array(
						'type' => (string) $options['toutiao_type'],
						'page' => '1',
					)
				);

			case HYZB_Options::SOURCE_TOPHUB:
				// The endpoint takes `id` or `type`, not both. A chosen site id is
				// more specific, so it wins when one is set.
				$tophub_id = isset( $options['tophub_id'] ) ? trim( (string) $options['tophub_id'] ) : '';

				return $this->post(
					'/api/tophub',
					'' !== $tophub_id
						? array( 'id' => $tophub_id )
						: array( 'type' => (string) $options['tophub_type'] )
				);
		}

		return new WP_Error(
			'hyzb_unknown_source',
			sprintf(
				/* translators: %s: source slug. */
				__( '未知的数据源：%s', 'daily-zaobao' ),
				$slug
			)
		);
	}

	/**
	 * Fetch a specific 知乎日报 edition.
	 *
	 * @param string $date Edition date as YYYYMMDD.
	 * @return array|WP_Error Payload from `data`, or an error.
	 */
	public function get_zhihu_by_date( $date ) {
		return $this->post( '/api/zhihu/get', array( 'date' => $date ) );
	}

	/**
	 * List the available 网易新闻 categories.
	 *
	 * @return array|WP_Error
	 */
	public function get_toutiao_types() {
		return $this->post( '/api/new/toutiao/type', array() );
	}

	/**
	 * List the available 今日热榜 sites.
	 *
	 * @param int $page_size How many sites to return.
	 * @return array|WP_Error
	 */
	public function get_tophub_sites( $page_size = 100 ) {
		return $this->post(
			'/api/tophub/site',
			array(
				'page'      => '1',
				'page_size' => (string) absint( $page_size ),
			)
		);
	}

	/**
	 * POST a JSON body and unwrap the ALAPI envelope.
	 *
	 * @param string $path Endpoint path.
	 * @param array  $body Request parameters.
	 * @return array|WP_Error The `data` member on success.
	 */
	private function post( $path, $body ) {
		if ( '' === $this->token ) {
			return new WP_Error( 'hyzb_no_token', __( '尚未填写 ALAPI Token，请先在插件设置页填写。', 'daily-zaobao' ) );
		}

		$args = array(
			'timeout' => 20,
			'headers' => array(
				'token'        => $this->token,
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$response = wp_remote_post( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'hyzb_bad_json',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( '接口返回的不是合法 JSON（HTTP %d）。', 'daily-zaobao' ),
					(int) $status
				)
			);
		}

		$code = isset( $parsed['code'] ) ? (int) $parsed['code'] : 0;

		if ( 200 !== $code || empty( $parsed['success'] ) ) {
			$message = isset( $parsed['message'] ) ? (string) $parsed['message'] : __( '未知错误', 'daily-zaobao' );

			return new WP_Error(
				'hyzb_api_error',
				sprintf(
					/* translators: 1: ALAPI error code, 2: error message. */
					__( 'ALAPI 返回错误 %1$d：%2$s', 'daily-zaobao' ),
					$code,
					$message
				),
				array( 'code' => $code )
			);
		}

		if ( empty( $parsed['data'] ) || ! is_array( $parsed['data'] ) ) {
			return new WP_Error( 'hyzb_empty_data', __( '接口调用成功但没有返回数据。', 'daily-zaobao' ) );
		}

		return $parsed['data'];
	}
}
