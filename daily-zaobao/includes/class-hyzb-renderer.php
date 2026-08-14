<?php
/**
 * Turns API payloads into post content.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the HTML body of a generated post.
 *
 * Every image and media reference is emitted as the original remote URL. Nothing
 * is copied into the media library, per the plugin's design.
 */
class HYZB_Renderer {

	/**
	 * Settings snapshot.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param array $options Settings array from HYZB_Options::all().
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Render one source as a self-contained section.
	 *
	 * @param string $slug        Source slug.
	 * @param array  $data        Payload from the API.
	 * @param bool   $with_header Whether to prefix an <h2> with the source name.
	 * @return string Empty string when the payload yields nothing renderable.
	 */
	public function render_section( $slug, array $data, $with_header = true ) {
		switch ( $slug ) {
			case HYZB_Options::SOURCE_ZAOBAO:
				$body = $this->render_zaobao( $data );
				break;

			case HYZB_Options::SOURCE_ZHIHU:
				$body = $this->render_zhihu( $data );
				break;

			case HYZB_Options::SOURCE_WBTOP:
				$body = $this->render_wbtop( $data );
				break;

			case HYZB_Options::SOURCE_TOUTIAO:
				$body = $this->render_toutiao( $data );
				break;

			case HYZB_Options::SOURCE_TOPHUB:
				$body = $this->render_tophub( $data );
				break;

			default:
				return '';
		}

		if ( '' === trim( $body ) ) {
			return '';
		}

		$html = '<section class="hyzb-section hyzb-section-' . esc_attr( $slug ) . "\">\n";

		if ( $with_header ) {
			$html .= '<h2 class="hyzb-section-title">' . esc_html( HYZB_Options::source_label( $slug ) ) . "</h2>\n";
		}

		return $html . $body . "</section>\n";
	}

	/**
	 * Source credit line for a set of slugs.
	 *
	 * @param array $slugs Source slugs that actually produced content.
	 * @return string
	 */
	public function render_attribution( array $slugs ) {
		if ( empty( $this->options['attribution'] ) || ! $slugs ) {
			return '';
		}

		$sources = HYZB_Options::sources();
		$links   = array();

		foreach ( $slugs as $slug ) {
			if ( ! isset( $sources[ $slug ] ) ) {
				continue;
			}

			// Every credit points at the same ALAPI link rather than at the
			// individual endpoint docs.
			$links[] = $this->link( HYZB_ALAPI_URL, $sources[ $slug ]['label'] );
		}

		if ( ! $links ) {
			return '';
		}

		return '<p class="hyzb-attribution"><small>' . esc_html__( '数据来源：', 'daily-zaobao' )
			. implode( esc_html__( '、', 'daily-zaobao' ), $links )
			. '（' . $this->link( HYZB_ALAPI_URL, 'ALAPI' ) . "）</small></p>\n";
	}

	/**
	 * 每日早报: plain-text bulletins plus the daily quote.
	 *
	 * Items arrive as strings such as "1、正文；" with no per-item link, so this
	 * renders an ordered list and strips the baked-in numbering.
	 *
	 * @param array $data Payload from /api/zaobao.
	 * @return string
	 */
	private function render_zaobao( array $data ) {
		$html = '';

		if ( $this->options['show_images'] ) {
			$html .= $this->figure( $this->pick_string( $data, 'head_image' ), __( '每日早报头图', 'daily-zaobao' ) );
		}

		$news = $this->pick_list( $data, 'news' );

		if ( $news ) {
			$html .= "<ol class=\"hyzb-list hyzb-bulletins\">\n";

			foreach ( $news as $item ) {
				if ( ! is_string( $item ) ) {
					continue;
				}

				$text = $this->strip_leading_number( $item );

				if ( '' !== $text ) {
					$html .= "\t<li>" . esc_html( $text ) . "</li>\n";
				}
			}

			$html .= "</ol>\n";
		}

		// Only `head_image` is rendered. The payload's separate `image` field is
		// deliberately ignored — it duplicates the header art when populated.
		if ( $this->options['show_weiyu'] ) {
			$weiyu = $this->pick_string( $data, 'weiyu' );

			if ( '' !== $weiyu ) {
				$html .= '<blockquote class="hyzb-weiyu"><p>' . esc_html( $weiyu ) . "</p></blockquote>\n";
			}
		}

		return $html;
	}

	/**
	 * 知乎日报: headline block plus the recommended list.
	 *
	 * @param array $data Payload from /api/zhihu.
	 * @return string
	 */
	private function render_zhihu( array $data ) {
		$html = '';

		// `top_stories` carries a single `image`; `stories` an `images` array.
		$top = $this->normalize_rows(
			$this->pick_list( $data, 'top_stories' ),
			'title',
			'url',
			array( 'image' ),
			'hint'
		);

		if ( $top ) {
			$html .= '<h3>' . esc_html__( '头条', 'daily-zaobao' ) . "</h3>\n";
			$html .= $this->render_items( $top );
		}

		$stories = $this->normalize_rows(
			$this->pick_list( $data, 'stories' ),
			'title',
			'url',
			array( 'images' ),
			'hint'
		);

		if ( $stories ) {
			if ( $top ) {
				$html .= '<h3>' . esc_html__( '今日推荐', 'daily-zaobao' ) . "</h3>\n";
			}
			$html .= $this->render_items( $stories );
		}

		return $html;
	}

	/**
	 * 微博热搜榜: the payload is a flat list of trending terms.
	 *
	 * @param array $data Payload from /api/new/wbtop.
	 * @return string
	 */
	private function render_wbtop( array $data ) {
		$items = array();
		$limit = (int) $this->options['wbtop_num'];

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = $this->pick_string( $row, 'hot_word' );

			if ( '' === $title ) {
				continue;
			}

			$heat = isset( $row['hot_word_num'] ) ? absint( $row['hot_word_num'] ) : 0;

			$items[] = array(
				'title' => $title,
				'url'   => $this->pick_url( $row, 'url' ),
				'image' => '',
				'meta'  => $heat
					? sprintf(
						/* translators: %s: formatted heat value. */
						__( '热度 %s', 'daily-zaobao' ),
						number_format_i18n( $heat )
					)
					: '',
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items ? $this->render_items( $items, true ) : '';
	}

	/**
	 * 网易新闻头条.
	 *
	 * @param array $data Payload from /api/new/toutiao.
	 * @return string
	 */
	private function render_toutiao( array $data ) {
		$items = array();
		$limit = (int) $this->options['toutiao_limit'];

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = $this->pick_string( $row, 'title' );

			if ( '' === $title ) {
				continue;
			}

			// `m_url` and `pc_url` are the same value in practice; prefer pc_url.
			$url = $this->pick_url( $row, 'pc_url' );

			if ( '' === $url ) {
				$url = $this->pick_url( $row, 'm_url' );
			}

			$meta   = array();
			$source = $this->pick_string( $row, 'source' );
			$time   = $this->pick_string( $row, 'time' );

			if ( '' !== $source ) {
				$meta[] = $source;
			}
			if ( '' !== $time ) {
				$meta[] = $time;
			}

			$digest = $this->pick_string( $row, 'digest' );

			$items[] = array(
				'title'  => $title,
				'url'    => $url,
				'image'  => $this->pick_string( $row, 'imgsrc' ),
				'meta'   => implode( ' · ', $meta ),
				// The feed often repeats the title in `digest`; drop it when it does.
				'digest' => ( '' !== $digest && $digest !== $title ) ? $digest : '',
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items ? $this->render_items( $items ) : '';
	}

	/**
	 * 今日热榜.
	 *
	 * @param array $data Payload from /api/tophub.
	 * @return string
	 */
	private function render_tophub( array $data ) {
		$rows  = $this->pick_list( $data, 'list' );
		$items = $this->normalize_rows( $rows, 'title', 'link', array( 'image' ), '' );

		if ( ! $items ) {
			return '';
		}

		$items = array_slice( $items, 0, (int) $this->options['tophub_limit'] );

		$html = '';

		// The endpoint reports which board the data came from; worth keeping.
		$name = $this->pick_string( $data, 'name' );
		$time = $this->pick_string( $data, 'last_update' );

		if ( '' !== $name || '' !== $time ) {
			$label = '' !== $name ? $name : '';

			if ( '' !== $time ) {
				$label .= ( '' !== $label ? ' · ' : '' ) . sprintf(
					/* translators: %s: last update timestamp. */
					__( '更新于 %s', 'daily-zaobao' ),
					$time
				);
			}

			$html .= '<p class="hyzb-board-meta"><small>' . esc_html( $label ) . "</small></p>\n";
		}

		return $html . $this->render_items( $items, true );
	}

	/**
	 * Map raw API rows onto the internal item shape.
	 *
	 * @param array  $rows       Raw rows.
	 * @param string $title_key  Key holding the title.
	 * @param string $url_key    Key holding the link.
	 * @param array  $image_keys Keys that may hold an image (string or array).
	 * @param string $meta_key   Key holding a short meta line, or ''.
	 * @return array
	 */
	private function normalize_rows( array $rows, $title_key, $url_key, array $image_keys, $meta_key ) {
		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = $this->pick_string( $row, $title_key );

			if ( '' === $title ) {
				continue;
			}

			$image = '';

			foreach ( $image_keys as $key ) {
				if ( ! isset( $row[ $key ] ) ) {
					continue;
				}

				$value = $row[ $key ];

				// `stories` carries `images` as an array, `top_stories` an `image`
				// string. The array case must be tested first: indexing a string
				// with [0] silently yields its first character, which is how this
				// previously produced src="h" for every 知乎日报 headline.
				if ( is_array( $value ) ) {
					if ( isset( $value[0] ) && is_string( $value[0] ) && '' !== trim( $value[0] ) ) {
						$image = $value[0];
						break;
					}

					continue;
				}

				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$image = $value;
					break;
				}
			}

			$items[] = array(
				'title' => $title,
				'url'   => $this->pick_url( $row, $url_key ),
				'image' => $image,
				'meta'  => '' !== $meta_key ? $this->pick_string( $row, $meta_key ) : '',
			);
		}

		return $items;
	}

	/**
	 * Render normalised items as a list.
	 *
	 * Each row is laid out as thumbnail-left / text-right. Rows without a usable
	 * image get a `hyzb-item--no-thumb` class so the text can span the full width
	 * instead of leaving a hole where the thumbnail would be.
	 *
	 * @param array $items    Item rows: title, url, image, meta, digest.
	 * @param bool  $numbered Whether to render an ordered list.
	 * @return string
	 */
	private function render_items( array $items, $numbered = false ) {
		$tag  = $numbered ? 'ol' : 'ul';
		$html = '<' . $tag . " class=\"hyzb-list hyzb-items\">\n";

		foreach ( $items as $item ) {
			$title = $item['title'];
			$url   = isset( $item['url'] ) ? $item['url'] : '';

			$thumb = '';

			if ( $this->options['show_images'] && ! empty( $item['image'] ) ) {
				$img = $this->img( $item['image'], $title );

				if ( '' !== $img ) {
					$thumb = '' === $url
						? '<span class="hyzb-thumb">' . $img . '</span>'
						: '<a class="hyzb-thumb" href="' . esc_url( $url )
							. '" target="_blank" rel="noopener nofollow external">' . $img . '</a>';
				}
			}

			$html .= "\t<li class=\"hyzb-item" . ( '' === $thumb ? ' hyzb-item--no-thumb' : '' ) . "\">\n";

			if ( '' !== $thumb ) {
				$html .= "\t\t" . $thumb . "\n";
			}

			$html .= "\t\t<div class=\"hyzb-item-body\">\n";

			$html .= "\t\t\t<p class=\"hyzb-item-title\">";
			$html .= '' === $url ? esc_html( $title ) : $this->link( $url, $title );
			$html .= "</p>\n";

			if ( ! empty( $item['digest'] ) ) {
				$html .= "\t\t\t<p class=\"hyzb-item-digest\">" . esc_html( $item['digest'] ) . "</p>\n";
			}

			if ( $this->options['show_hint'] && ! empty( $item['meta'] ) ) {
				$html .= "\t\t\t<p class=\"hyzb-item-meta\"><small>" . esc_html( $item['meta'] ) . "</small></p>\n";
			}

			$html .= "\t\t</div>\n\t</li>\n";
		}

		return $html . '</' . $tag . ">\n";
	}

	/**
	 * Build an external anchor.
	 *
	 * @param string $url      Destination URL, already validated.
	 * @param string $inner    Link text, or ready-made HTML when $esc_text is false.
	 * @param bool   $esc_text Whether to escape $inner here.
	 * @return string
	 */
	private function link( $url, $inner, $esc_text = true ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow external">'
			. ( $esc_text ? esc_html( $inner ) : $inner )
			. '</a>';
	}

	/**
	 * Build an <img> for a remote URL, or an empty string.
	 *
	 * @param string $url Remote image URL.
	 * @param string $alt Alt text.
	 * @return string
	 */
	private function img( $url, $alt ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return '';
		}

		// referrerpolicy keeps the remote CDNs from 403-ing on hotlink checks.
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt )
			. '" loading="lazy" referrerpolicy="no-referrer" />';
	}

	/**
	 * Wrap a remote image in a <figure>, or return an empty string.
	 *
	 * @param string $url Remote image URL.
	 * @param string $alt Alt text.
	 * @return string
	 */
	private function figure( $url, $alt ) {
		$img = $this->img( $url, $alt );

		return '' === $img ? '' : '<figure class="hyzb-figure">' . $img . "</figure>\n";
	}

	/**
	 * Read a string member from an array.
	 *
	 * @param array  $source Source array.
	 * @param string $key    Key to read.
	 * @return string
	 */
	private function pick_string( array $source, $key ) {
		if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) {
			return '';
		}

		return trim( $source[ $key ] );
	}

	/**
	 * Read a list member from an array.
	 *
	 * @param array  $source Source array.
	 * @param string $key    Key to read.
	 * @return array
	 */
	private function pick_list( array $source, $key ) {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
	}

	/**
	 * Read and validate a URL member from an array.
	 *
	 * @param array  $source Source array.
	 * @param string $key    Key to read.
	 * @return string Empty string when missing or not an http(s) URL.
	 */
	private function pick_url( array $source, $key ) {
		$url = esc_url_raw( $this->pick_string( $source, $key ) );

		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * Remove the "12、" / "12." / "12）" style prefix the feed bakes into each item.
	 *
	 * @param string $text Raw item text.
	 * @return string
	 */
	private function strip_leading_number( $text ) {
		$text = trim( $text );
		$text = preg_replace( '/^\s*\d{1,2}\s*(?:[、.．,，)）]|:|：)\s*/u', '', $text );

		return trim( (string) $text );
	}
}
