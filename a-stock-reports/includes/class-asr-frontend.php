<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Frontend {

	public static function init() {
		add_shortcode( 'a_stock_reports', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	public static function enqueue_styles() {
		if ( get_query_var( 'asr_report_file' ) || self::is_shortcode_page() ) {
			wp_add_inline_style( 'wp-block-library', self::get_css() );
		}
	}

	private static function is_shortcode_page() {
		global $post;
		return $post && has_shortcode( $post->post_content, 'a_stock_reports' );
	}

	public static function render_shortcode( $atts ) {
		$file = isset( $_GET['asr_file'] ) ? sanitize_text_field( wp_unslash( $_GET['asr_file'] ) ) : '';

		if ( ! empty( $file ) ) {
			return self::render_detail( $file );
		}

		return self::render_list();
	}

	private static function render_detail( $file ) {
		if ( strpos( $file, '..' ) !== false ) {
			return '<p class="asr-empty">非法路径。</p>';
		}

		$subdir    = get_option( 'asr_reports_subdir', 'reports' );
		$full_path = ASR_REPO_DIR . '/' . trim( $subdir, '/' ) . '/' . $file;

		if ( ! file_exists( $full_path ) || pathinfo( $full_path, PATHINFO_EXTENSION ) !== 'html' ) {
			return '<p class="asr-empty">报告不存在。</p>';
		}

		$real_base = realpath( ASR_REPO_DIR . '/' . trim( $subdir, '/' ) );
		$real_file = realpath( $full_path );
		if ( ! $real_file || strpos( $real_file, $real_base ) !== 0 ) {
			return '<p class="asr-empty">非法路径。</p>';
		}

		$basename = basename( $full_path, '.html' );
		$parsed   = self::parse_title( $basename );
		$back_url = remove_query_arg( 'asr_file' );

		$iframe_url = content_url( 'a-stock-repo/' . trim( $subdir, '/' ) . '/' . $file );

		$html  = '<div class="asr-report-view">';
		$html .= '<div class="asr-report-header">';
		$html .= '<a href="' . esc_url( $back_url ) . '" class="asr-back-link">&larr; 返回列表</a>';
		$html .= '<h1 class="asr-report-title">' . esc_html( $parsed ) . '</h1>';
		$html .= '</div>';
		$html .= '<div class="asr-report-content">';
		$html .= '<iframe src="' . esc_url( $iframe_url ) . '" class="asr-report-iframe" frameborder="0"></iframe>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	private static function render_list() {
		$index = get_option( 'asr_reports_index', array() );
		if ( empty( $index ) ) {
			return '<p class="asr-empty">暂无报告数据，请先在后台同步仓库。</p>';
		}

		$grouped = array();
		foreach ( $index as $item ) {
			$grouped[ $item['year'] ][ $item['month'] ][] = $item;
		}
		krsort( $grouped );

		$html = '<div class="asr-reports-wrap">';

		foreach ( $grouped as $year => $months ) {
			krsort( $months );
			$html .= '<div class="asr-year-section">';
			$html .= '<h2 class="asr-year-title">' . esc_html( $year ) . ' 年</h2>';

			foreach ( $months as $month => $reports ) {
				$month_label = (int) $month . ' 月';
				$html .= '<div class="asr-month-section">';
				$html .= '<h3 class="asr-month-title">' . esc_html( $month_label ) . '（' . count( $reports ) . ' 篇）</h3>';
				$html .= '<ul class="asr-report-list">';

				foreach ( $reports as $report ) {
					$url = add_query_arg( 'asr_file', $report['file'] );
					$html .= '<li class="asr-report-item">';
					$html .= '<a href="' . esc_url( $url ) . '" class="asr-report-link">';
					$html .= '<span class="asr-report-item-title">' . esc_html( $report['title'] ) . '</span>';
					if ( $report['date'] ) {
						$html .= '<span class="asr-report-date">' . esc_html(
							substr( $report['date'], 0, 4 ) . '-' .
							substr( $report['date'], 4, 2 ) . '-' .
							substr( $report['date'], 6, 2 )
						) . '</span>';
					}
					$html .= '</a>';
					$html .= '</li>';
				}

				$html .= '</ul>';
				$html .= '</div>';
			}

			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	private static function parse_title( $basename ) {
		if ( preg_match( '/_(\d{8})$/', $basename, $m ) ) {
			$date  = $m[1];
			$title = str_replace( '_' . $date, '', $basename );
			return $title . ' ' . substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
		}
		return $basename;
	}

	private static function get_css() {
		return '
		.asr-reports-wrap { width: 100%; max-width: 100%; padding: 20px 16px; box-sizing: border-box; }
		.asr-year-title { font-size: 1.6em; margin: 30px 0 15px; padding-bottom: 8px; border-bottom: 2px solid #e63946; color: #1a1a2e; }
		.asr-month-title { font-size: 1.2em; margin: 20px 0 10px; color: #5a5a72; }
		.asr-report-list { list-style: none; padding: 0; margin: 0; }
		.asr-report-item { border-bottom: 1px solid #eee; }
		.asr-report-link { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; text-decoration: none; color: #1a1a2e; transition: background .2s; border-radius: 6px; }
		.asr-report-link:hover { background: #f5f6f8; }
		.asr-report-item-title { font-weight: 500; }
		.asr-report-date { color: #8e8ea0; font-size: 0.9em; white-space: nowrap; margin-left: 16px; }
		.asr-report-view { width: 100%; max-width: 100%; padding: 20px 16px; box-sizing: border-box; }
		.asr-report-header { margin-bottom: 20px; }
		.asr-back-link { color: #2563eb; text-decoration: none; font-size: 0.95em; }
		.asr-back-link:hover { text-decoration: underline; }
		.asr-report-title { font-size: 1.5em; margin-top: 10px; color: #1a1a2e; }
		.asr-report-iframe { width: 100%; height: 200vh; min-height: 100vh; border: 1px solid #e8e8ef; border-radius: 8px; }
		.asr-empty { text-align: center; color: #8e8ea0; padding: 40px 0; }
		@media (max-width: 768px) {
			.asr-reports-wrap, .asr-report-view { padding: 12px 10px; }
			.asr-year-title { font-size: 1.3em; }
			.asr-month-title { font-size: 1.05em; }
			.asr-report-link { padding: 10px 12px; flex-wrap: wrap; }
			.asr-report-date { margin-left: 0; margin-top: 4px; width: 100%; }
			.asr-report-title { font-size: 1.2em; }
			.asr-report-iframe { height: 150vh; min-height: 80vh; border-radius: 4px; }
		}
		';
	}
}
