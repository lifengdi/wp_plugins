<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Git_Sync {

	public static function sync() {
		$repo_url = get_option( 'asr_repo_url', '' );
		$repo_dir = ASR_REPO_DIR;
		$log      = '';
		$git_ok   = false;

		$git_available = false;
		exec( 'which git 2>/dev/null', $git_check, $git_ret );
		if ( 0 === $git_ret ) {
			$git_available = true;
		}

		if ( $git_available && ! empty( $repo_url ) ) {
			if ( is_dir( $repo_dir . '/.git' ) ) {
				$command = sprintf(
					'cd %s && git pull origin main 2>&1',
					escapeshellarg( $repo_dir )
				);
			} else if ( ! is_dir( $repo_dir ) ) {
				$command = sprintf(
					'git clone %s %s 2>&1',
					escapeshellarg( $repo_url ),
					escapeshellarg( $repo_dir )
				);
			} else {
				$log    = '仓库目录已存在但非 git 仓库，跳过 git 操作，直接构建索引。';
				$git_ok = true;
				$command = null;
			}

			if ( $command ) {
				$output     = array();
				$return_var = 0;
				exec( $command, $output, $return_var );
				$log    = implode( "\n", $output );
				$git_ok = ( 0 === $return_var );
			}
		} else {
			$log    = 'Git 不可用或未配置仓库地址，直接从现有文件构建索引。';
			$git_ok = true;
		}

		update_option( 'asr_last_sync_log', $log );
		update_option( 'asr_last_sync_time', current_time( 'mysql' ) );

		$subdir     = get_option( 'asr_reports_subdir', 'reports' );
		$reports_dir = $repo_dir . '/' . trim( $subdir, '/' );
		if ( ! is_dir( $reports_dir ) ) {
			return new WP_Error( 'asr_no_reports', '报告目录不存在：' . $reports_dir );
		}

		self::build_index();

		if ( ! $git_ok ) {
			return new WP_Error( 'asr_git_error', $log );
		}

		return true;
	}

	public static function build_index() {
		$subdir     = get_option( 'asr_reports_subdir', 'reports' );
		$reports_dir = ASR_REPO_DIR . '/' . trim( $subdir, '/' );
		if ( ! is_dir( $reports_dir ) ) {
			update_option( 'asr_reports_index', array() );
			return;
		}

		$index = array();
		$years = self::scan_dirs( $reports_dir );
		sort( $years );

		foreach ( $years as $year ) {
			$year_path = $reports_dir . '/' . $year;
			if ( ! is_dir( $year_path ) || ! is_numeric( $year ) ) {
				continue;
			}
			$months = self::scan_dirs( $year_path );
			sort( $months );
			foreach ( $months as $month ) {
				$month_path = $year_path . '/' . $month;
				if ( ! is_dir( $month_path ) ) {
					continue;
				}
				$files = glob( $month_path . '/*.html' );
				if ( empty( $files ) ) {
					continue;
				}
				foreach ( $files as $file ) {
					$basename = basename( $file, '.html' );
					$parsed   = self::parse_filename( $basename );
					$index[] = array(
						'year'     => $year,
						'month'    => $month,
						'file'     => $year . '/' . $month . '/' . basename( $file ),
						'title'    => $parsed['title'],
						'date'     => $parsed['date'],
						'basename' => $basename,
					);
				}
			}
		}

		usort( $index, function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		});

		update_option( 'asr_reports_index', $index, false );
	}

	private static function parse_filename( $basename ) {
		$title = $basename;
		$date  = '';
		if ( preg_match( '/_(\d{8})$/', $basename, $m ) ) {
			$date  = $m[1];
			$title = str_replace( '_' . $date, '', $basename );
			$title = $title . ' ' . substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
		}
		return array(
			'title' => $title,
			'date'  => $date,
		);
	}

	private static function scan_dirs( $path ) {
		$items  = array();
		$handle = opendir( $path );
		if ( ! $handle ) {
			return $items;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( is_dir( $path . '/' . $entry ) ) {
				$items[] = $entry;
			}
		}
		closedir( $handle );
		return $items;
	}

	private static function rmdir_recursive( $dir ) {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getRealPath() );
			} else {
				unlink( $item->getRealPath() );
			}
		}
		rmdir( $dir );
	}
}
