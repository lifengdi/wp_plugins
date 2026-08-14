<?php
/**
 * Settings screen.
 *
 * @package daily-zaobao
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the options page and handles the manual fetch action.
 */
class HYZB_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE = 'daily-zaobao';

	/**
	 * Nonce action for the manual fetch button.
	 */
	const FETCH_ACTION = 'hyzb_fetch_now';

	/**
	 * Nonce action for the "refresh option lists" button.
	 */
	const REFRESH_ACTION = 'hyzb_refresh_lists';

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::FETCH_ACTION, array( __CLASS__, 'handle_fetch_now' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh_lists' ) );
		add_action( 'update_option_' . HYZB_OPTION, array( __CLASS__, 'on_settings_saved' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( HYZB_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/**
	 * Add author and support links to the plugin's row on the plugins screen.
	 *
	 * @param array  $meta Existing row meta links.
	 * @param string $file Plugin file being filtered.
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( HYZB_FILE ) !== $file ) {
			return $meta;
		}

		$meta[] = '<a href="' . esc_url( HYZB_AUTHOR_URL ) . '" target="_blank" rel="noopener">'
			. esc_html__( '作者主页', 'daily-zaobao' ) . '</a>';
		$meta[] = '<a href="' . esc_url( HYZB_SUPPORT_URL ) . '" target="_blank" rel="noopener">'
			. esc_html__( '问题反馈', 'daily-zaobao' ) . '</a>';

		return $meta;
	}

	/**
	 * Add the settings page under Settings.
	 */
	public static function add_page() {
		add_options_page(
			__( '每日早报自动发布', 'daily-zaobao' ),
			__( '每日早报', 'daily-zaobao' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'hyzb_group',
			HYZB_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'HYZB_Options', 'sanitize' ),
				'default'           => HYZB_Options::defaults(),
			)
		);
	}

	/**
	 * Re-arm the cron event whenever the run time changes.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public static function on_settings_saved( $old, $new ) {
		$old_time = is_array( $old ) && isset( $old['run_time'] ) ? $old['run_time'] : '';
		$new_time = is_array( $new ) && isset( $new['run_time'] ) ? $new['run_time'] : '';

		if ( $old_time !== $new_time || ! HYZB_Cron::next_run() ) {
			HYZB_Cron::reschedule();
		}
	}

	/**
	 * Add a Settings shortcut on the plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( '设置', 'daily-zaobao' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Run an import immediately, then redirect back to the settings page.
	 */
	public static function handle_fetch_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限执行此操作。', 'daily-zaobao' ) );
		}

		check_admin_referer( self::FETCH_ACTION );

		$result = HYZB_Importer::run();
		HYZB_Cron::log( $result, 'manual' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'hyzb_done' => 1,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Drop the cached dropdown lists, then redirect back.
	 */
	public static function handle_refresh_lists() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限执行此操作。', 'daily-zaobao' ) );
		}

		check_admin_referer( self::REFRESH_ACTION );

		HYZB_Lists::flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE,
					'hyzb_flushed' => 1,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = HYZB_Options::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '每日早报自动发布', 'daily-zaobao' ); ?></h1>

			<?php if ( isset( $_GET['hyzb_flushed'] ) ) : ?>
				<div class="notice notice-success"><p>
					<?php esc_html_e( '可选列表缓存已清空，本页的下拉框已重新拉取。', 'daily-zaobao' ); ?>
				</p></div>
			<?php endif; ?>

			<?php self::render_status( $options ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'hyzb_group' ); ?>

				<h2><?php esc_html_e( '接口', 'daily-zaobao' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hyzb_token"><?php esc_html_e( 'ALAPI Token', 'daily-zaobao' ); ?></label>
						</th>
						<td>
							<input type="password" id="hyzb_token" class="regular-text" autocomplete="new-password"
								name="<?php echo esc_attr( HYZB_OPTION ); ?>[token]" value="" />
							<p class="description">
								<?php
								if ( '' !== $options['token'] ) {
									esc_html_e( 'Token 已保存。留空表示保持不变；填入新值则覆盖。', 'daily-zaobao' );
								} else {
									esc_html_e( '尚未填写。', 'daily-zaobao' );
								}
								?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: registration link, 2: token dashboard link. */
									esc_html__( '还没有账号？%1$s；已有账号请在 %2$s 创建 Token。', 'daily-zaobao' ),
									'<a href="' . esc_url( HYZB_ALAPI_URL ) . '" target="_blank" rel="noopener">'
										. esc_html__( '注册 ALAPI', 'daily-zaobao' ) . '</a>',
									'<a href="https://www.alapi.cn/dashboard/data/token" target="_blank" rel="noopener">'
										. esc_html__( '控制台', 'daily-zaobao' ) . '</a>'
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Token 仅保存在本站数据库，不会显示在页面上，也不会写入运行日志。', 'daily-zaobao' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '数据源', 'daily-zaobao' ); ?></h2>
				<p class="description">
					<?php esc_html_e( '勾选的每个数据源，会在同一篇日报文章里各占一个小节。每个源每天各消耗 1 次接口调用额度。', 'daily-zaobao' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '启用的数据源', 'daily-zaobao' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( HYZB_Options::sources() as $slug => $source ) : ?>
									<label style="display:block;margin-bottom:.6em;">
										<input type="checkbox"
											name="<?php echo esc_attr( HYZB_OPTION ); ?>[sources][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $options['sources'], true ) ); ?> />
										<strong><?php echo esc_html( $source['label'] ); ?></strong>
										<span class="description">
											— <?php echo esc_html( $source['note'] ); ?>
											<a href="<?php echo esc_url( 'https://www.alapi.cn/api/' . (int) $source['doc_id'] . '/api_document' ); ?>"
												target="_blank" rel="noopener"><?php esc_html_e( '文档', 'daily-zaobao' ); ?></a>
										</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								<?php esc_html_e( '至少勾选一个；全部取消时会回落到「每日早报」。', 'daily-zaobao' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hyzb_wbtop_num"><?php esc_html_e( '微博热搜条数', 'daily-zaobao' ); ?></label>
						</th>
						<td>
							<input type="number" id="hyzb_wbtop_num" min="1" max="50" class="small-text"
								name="<?php echo esc_attr( HYZB_OPTION ); ?>[wbtop_num]"
								value="<?php echo esc_attr( $options['wbtop_num'] ); ?>" />
							<p class="description"><?php esc_html_e( '接口上限 50。', 'daily-zaobao' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hyzb_toutiao_type"><?php esc_html_e( '网易新闻类型', 'daily-zaobao' ); ?></label>
						</th>
						<td>
							<?php self::render_toutiao_type_control( $options ); ?>
							<?php esc_html_e( '条数', 'daily-zaobao' ); ?>
							<input type="number" min="1" max="50" class="small-text"
								name="<?php echo esc_attr( HYZB_OPTION ); ?>[toutiao_limit]"
								value="<?php echo esc_attr( $options['toutiao_limit'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hyzb_tophub_id"><?php esc_html_e( '今日热榜榜单', 'daily-zaobao' ); ?></label>
						</th>
						<td>
							<?php self::render_tophub_control( $options ); ?>
							<?php esc_html_e( '条数', 'daily-zaobao' ); ?>
							<input type="number" min="1" max="50" class="small-text"
								name="<?php echo esc_attr( HYZB_OPTION ); ?>[tophub_limit]"
								value="<?php echo esc_attr( $options['tophub_limit'] ); ?>" />
						</td>
					</tr>
				</table>

				<p>
					<?php esc_html_e( '上面两个下拉框的可选项来自接口，缓存一周。', 'daily-zaobao' ); ?>
					<?php esc_html_e( '若接口新增了榜单或类型，可手动刷新（每次刷新各消耗 1 次调用额度）。', 'daily-zaobao' ); ?>
				</p>

				<h2><?php esc_html_e( '定时与发布', 'daily-zaobao' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hyzb_run_time"><?php esc_html_e( '每天抓取时间', 'daily-zaobao' ); ?></label>
						</th>
						<td>
							<input type="time" id="hyzb_run_time"
								name="<?php echo esc_attr( HYZB_OPTION ); ?>[run_time]"
								value="<?php echo esc_attr( $options['run_time'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: site timezone string. */
									esc_html__( '使用站点时区（%s）。WP-Cron 依赖访问触发，冷站点可能延后。', 'daily-zaobao' ),
									esc_html( wp_timezone_string() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="hyzb_title_format"><?php esc_html_e( '标题格式', 'daily-zaobao' ); ?></label>
						</th>
						<td>
							<input type="text" id="hyzb_title_format" class="regular-text"
								name="<?php echo esc_attr( HYZB_OPTION ); ?>[title_format]"
								value="<?php echo esc_attr( $options['title_format'] ); ?>" />
							<p class="description"><?php esc_html_e( '必须包含 {date}，否则回落到默认值。', 'daily-zaobao' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hyzb_post_status"><?php esc_html_e( '文章状态', 'daily-zaobao' ); ?></label></th>
						<td>
							<select id="hyzb_post_status" name="<?php echo esc_attr( HYZB_OPTION ); ?>[post_status]">
								<?php
								$statuses = array(
									'publish' => __( '直接发布', 'daily-zaobao' ),
									'draft'   => __( '存为草稿', 'daily-zaobao' ),
									'pending' => __( '待审阅', 'daily-zaobao' ),
									'private' => __( '私密', 'daily-zaobao' ),
								);

								foreach ( $statuses as $value => $label ) {
									printf(
										'<option value="%s"%s>%s</option>',
										esc_attr( $value ),
										selected( $options['post_status'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hyzb_post_category"><?php esc_html_e( '分类', 'daily-zaobao' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'name'             => HYZB_OPTION . '[post_category]',
									'id'               => 'hyzb_post_category',
									'selected'         => (int) $options['post_category'],
									'show_option_none' => __( '（使用默认分类）', 'daily-zaobao' ),
									'option_none_value' => 0,
									'hide_empty'       => false,
									'hierarchical'     => true,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hyzb_post_author"><?php esc_html_e( '作者', 'daily-zaobao' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'            => HYZB_OPTION . '[post_author]',
									'id'              => 'hyzb_post_author',
									'selected'        => (int) $options['post_author'],
									'show_option_none' => __( '（使用系统默认）', 'daily-zaobao' ),
									'option_none_value' => 0,
								)
							);
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '内容选项', 'daily-zaobao' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '显示', 'daily-zaobao' ); ?></th>
						<td>
							<?php
							$flags = array(
								'show_images' => __( '显示图片（直接引用原始外链，不保存到媒体库）', 'daily-zaobao' ),
								'show_weiyu'  => __( '显示每日微语（仅「每日早报」有）', 'daily-zaobao' ),
								'show_hint'   => __( '显示条目附注（作者、来源、时间、热度）', 'daily-zaobao' ),
								'attribution' => __( '在文末标注数据来源', 'daily-zaobao' ),
							);

							foreach ( $flags as $key => $label ) {
								printf(
									'<label style="display:block;margin-bottom:.4em;"><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label>',
									esc_attr( HYZB_OPTION ),
									esc_attr( $key ),
									checked( ! empty( $options[ $key ] ), true, false ),
									esc_html( $label )
								);
							}
							?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( '立即抓取', 'daily-zaobao' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '按当前设置立即执行一次。若当天的文章已存在，则跳过而不重复创建。', 'daily-zaobao' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FETCH_ACTION ); ?>" />
				<?php wp_nonce_field( self::FETCH_ACTION ); ?>
				<?php submit_button( __( '立即抓取一次', 'daily-zaobao' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( '刷新可选列表', 'daily-zaobao' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '清空「网易新闻类型」和「今日热榜榜单」的缓存，下次打开本页时重新拉取。', 'daily-zaobao' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
				<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
				<?php submit_button( __( '刷新可选列表', 'daily-zaobao' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<p class="description">
				<?php
				printf(
					/* translators: 1: author site link, 2: support forum link. */
					esc_html__( '作者主页：%1$s　问题反馈：%2$s', 'daily-zaobao' ),
					'<a href="' . esc_url( HYZB_AUTHOR_URL ) . '" target="_blank" rel="noopener">'
						. esc_html( HYZB_AUTHOR_URL ) . '</a>',
					'<a href="' . esc_url( HYZB_SUPPORT_URL ) . '" target="_blank" rel="noopener">'
						. esc_html( HYZB_SUPPORT_URL ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * 网易新闻 category control: a dropdown when the list loads, a text box if not.
	 *
	 * @param array $options Settings array.
	 */
	private static function render_toutiao_type_control( array $options ) {
		$types   = HYZB_Lists::toutiao_types();
		$current = (string) $options['toutiao_type'];

		if ( is_wp_error( $types ) ) {
			self::render_fallback_text_input( 'toutiao_type', 'hyzb_toutiao_type', $current, 'small-text', $types );

			return;
		}

		echo '<select id="hyzb_toutiao_type" name="' . esc_attr( HYZB_OPTION ) . '[toutiao_type]">';

		$known = false;

		foreach ( $types as $type ) {
			if ( $type['type'] === $current ) {
				$known = true;
			}

			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $type['type'] ),
				selected( $current, $type['type'], false ),
				esc_html( $type['name'] . ' (' . $type['type'] . ')' )
			);
		}

		// Keep a previously saved value selectable even if it left the list.
		if ( ! $known && '' !== $current ) {
			printf(
				'<option value="%s" selected="selected">%s</option>',
				esc_attr( $current ),
				esc_html( sprintf( /* translators: %s: stored value. */ __( '已保存的值：%s', 'daily-zaobao' ), $current ) )
			);
		}

		echo '</select> ';
	}

	/**
	 * 今日热榜 board control: an <optgroup> dropdown of site ids, or a text box.
	 *
	 * @param array $options Settings array.
	 */
	private static function render_tophub_control( array $options ) {
		$sites   = HYZB_Lists::tophub_sites();
		$current = (string) $options['tophub_id'];

		if ( is_wp_error( $sites ) ) {
			// Falls back to the `type` parameter, which is what the endpoint used
			// before a specific board id could be chosen.
			self::render_fallback_text_input(
				'tophub_type',
				'hyzb_tophub_id',
				(string) $options['tophub_type'],
				'regular-text',
				$sites
			);

			return;
		}

		echo '<select id="hyzb_tophub_id" name="' . esc_attr( HYZB_OPTION ) . '[tophub_id]">';

		printf(
			'<option value=""%s>%s</option>',
			selected( $current, '', false ),
			esc_html(
				sprintf(
					/* translators: %s: fallback board type. */
					__( '（默认榜单：%s）', 'daily-zaobao' ),
					$options['tophub_type']
				)
			)
		);

		$known = false;

		foreach ( $sites as $site => $boards ) {
			echo '<optgroup label="' . esc_attr( $site ) . '">';

			foreach ( $boards as $board ) {
				if ( $board['id'] === $current ) {
					$known = true;
				}

				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $board['id'] ),
					selected( $current, $board['id'], false ),
					esc_html( $board['title'] )
				);
			}

			echo '</optgroup>';
		}

		if ( ! $known && '' !== $current ) {
			printf(
				'<option value="%s" selected="selected">%s</option>',
				esc_attr( $current ),
				esc_html( sprintf( /* translators: %s: stored board id. */ __( '已保存的 ID：%s', 'daily-zaobao' ), $current ) )
			);
		}

		echo '</select> ';
	}

	/**
	 * Text input shown when a dropdown list could not be fetched.
	 *
	 * The setting stays editable rather than locking the admin out of it.
	 *
	 * @param string   $key   Option key to write to.
	 * @param string   $id    Element id.
	 * @param string   $value Current value.
	 * @param string   $class CSS class for the input.
	 * @param WP_Error $error Why the list could not be loaded.
	 */
	private static function render_fallback_text_input( $key, $id, $value, $class, $error ) {
		printf(
			'<input type="text" id="%s" class="%s" name="%s[%s]" value="%s" /> ',
			esc_attr( $id ),
			esc_attr( $class ),
			esc_attr( HYZB_OPTION ),
			esc_attr( $key ),
			esc_attr( $value )
		);

		echo '<p class="description" style="color:#b32d2e;">'
			. esc_html__( '无法加载可选列表，已回退为手动填写。', 'daily-zaobao' )
			. ' ' . esc_html( $error->get_error_message() )
			. '</p>';
	}

	/**
	 * Show the schedule and the outcome of the most recent run.
	 *
	 * @param array $options Settings array.
	 */
	private static function render_status( array $options ) {
		if ( '' === $options['token'] ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( '还没有填写 ALAPI Token，定时抓取会失败。', 'daily-zaobao' )
				. '</p></div>';
		}

		$next = HYZB_Cron::next_run();

		echo '<p><strong>' . esc_html__( '下次抓取：', 'daily-zaobao' ) . '</strong> ';
		if ( $next ) {
			echo esc_html( wp_date( 'Y-m-d H:i', $next ) );
		} else {
			echo esc_html__( '未排程（保存一次设置即可重新排程）', 'daily-zaobao' );
		}
		echo '</p>';

		$labels = array();
		foreach ( $options['sources'] as $slug ) {
			$labels[] = HYZB_Options::source_label( $slug );
		}
		echo '<p><strong>' . esc_html__( '当前数据源：', 'daily-zaobao' ) . '</strong> '
			. esc_html( implode( '、', $labels ) ) . '</p>';

		$last = HYZB_Cron::last_run();

		if ( ! $last ) {
			return;
		}

		$classes = array(
			'success' => 'notice-success',
			'skipped' => 'notice-info',
			'partial' => 'notice-warning',
			'error'   => 'notice-error',
		);

		$titles = array(
			'success' => __( '上次抓取成功', 'daily-zaobao' ),
			'skipped' => __( '上次抓取已跳过（当天文章已存在）', 'daily-zaobao' ),
			'partial' => __( '上次抓取部分成功', 'daily-zaobao' ),
			'error'   => __( '上次抓取失败', 'daily-zaobao' ),
		);

		$status = isset( $last['status'] ) ? $last['status'] : 'error';
		$class  = isset( $classes[ $status ] ) ? $classes[ $status ] : 'notice-error';
		$title  = isset( $titles[ $status ] ) ? $titles[ $status ] : $titles['error'];

		echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html( $title ) . '</strong><br />';

		printf(
			/* translators: 1: timestamp, 2: trigger source. */
			esc_html__( '时间：%1$s（%2$s）', 'daily-zaobao' ),
			esc_html( wp_date( 'Y-m-d H:i', (int) $last['time'] ) ),
			'manual' === $last['trigger'] ? esc_html__( '手动', 'daily-zaobao' ) : esc_html__( '定时', 'daily-zaobao' )
		);

		if ( ! empty( $last['ok'] ) && is_array( $last['ok'] ) ) {
			$ok = array_map( array( 'HYZB_Options', 'source_label' ), $last['ok'] );
			echo '<br />' . esc_html__( '成功的数据源：', 'daily-zaobao' ) . esc_html( implode( '、', $ok ) );
		}

		if ( ! empty( $last['post_id'] ) ) {
			$link = get_edit_post_link( (int) $last['post_id'] );

			if ( $link ) {
				echo '<br /><a href="' . esc_url( $link ) . '">' . esc_html__( '查看生成的文章', 'daily-zaobao' ) . '</a>';
			}
		}

		if ( ! empty( $last['message'] ) ) {
			echo '<br />' . esc_html( $last['message'] );
		}

		echo '</p></div>';
	}
}
