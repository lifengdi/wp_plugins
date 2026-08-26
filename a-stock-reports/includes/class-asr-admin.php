<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASR_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_asr_manual_sync', array( __CLASS__, 'ajax_sync' ) );
	}

	public static function add_menu() {
		add_menu_page(
			'A-Stock 报告',
			'A-Stock 报告',
			'manage_options',
			'a-stock-reports',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			30
		);
	}

	public static function register_settings() {
		register_setting( 'asr_settings', 'asr_repo_url', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asr_settings', 'asr_sync_time', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asr_settings', 'asr_reports_subdir', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function render_page() {
		$last_sync_time = get_option( 'asr_last_sync_time', '从未同步' );
		$last_sync_log  = get_option( 'asr_last_sync_log', '' );
		$index          = get_option( 'asr_reports_index', array() );
		?>
		<div class="wrap">
			<h1>A-Stock 报告设置</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'asr_settings' ); ?>
				<table class="form-table">
					<tr>
						<th>Git 仓库地址</th>
						<td><input type="text" name="asr_repo_url" value="<?php echo esc_attr( get_option( 'asr_repo_url' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>每日同步时间</th>
						<td><input type="time" name="asr_sync_time" value="<?php echo esc_attr( get_option( 'asr_sync_time', '06:00' ) ); ?>" /></td>
					</tr>
					<tr>
						<th>报告子目录</th>
						<td><input type="text" name="asr_reports_subdir" value="<?php echo esc_attr( get_option( 'asr_reports_subdir', 'reports' ) ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( '保存设置' ); ?>
			</form>

			<hr />
			<h2>同步操作</h2>
			<p>上次同步时间：<strong><?php echo esc_html( $last_sync_time ); ?></strong></p>
			<p>已索引报告数：<strong><?php echo count( $index ); ?></strong></p>
			<button type="button" id="asr-sync-btn" class="button button-primary">立即同步</button>
			<span id="asr-sync-status" style="margin-left:10px;"></span>
			<?php if ( $last_sync_log ) : ?>
				<h3>最近同步日志</h3>
				<pre style="background:#f0f0f0;padding:10px;max-height:200px;overflow:auto;"><?php echo esc_html( $last_sync_log ); ?></pre>
			<?php endif; ?>

			<hr />
			<h2>使用方法</h2>
			<p>在页面或文章中插入短代码：</p>
			<code>[a_stock_reports]</code> — 展示完整的年月报告列表
		</div>
		<script>
		jQuery(function($){
			$('#asr-sync-btn').on('click', function(){
				var $btn = $(this), $status = $('#asr-sync-status');
				$btn.prop('disabled', true);
				$status.text('同步中，请稍候...');
				$.post(ajaxurl, {action:'asr_manual_sync', _ajax_nonce:'<?php echo wp_create_nonce( 'asr_sync' ); ?>'}, function(res){
					$btn.prop('disabled', false);
					if(res.success){
						$status.text('同步成功！共索引 ' + res.data.count + ' 篇报告');
						setTimeout(function(){ location.reload(); }, 1500);
					} else {
						$status.text('同步失败：' + res.data);
					}
				}).fail(function(){
					$btn.prop('disabled', false);
					$status.text('请求失败');
				});
			});
		});
		</script>
		<?php
	}

	public static function ajax_sync() {
		check_ajax_referer( 'asr_sync' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '权限不足' );
		}
		$result = ASR_Git_Sync::sync();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$index = get_option( 'asr_reports_index', array() );
		wp_send_json_success( array( 'count' => count( $index ) ) );
	}
}
