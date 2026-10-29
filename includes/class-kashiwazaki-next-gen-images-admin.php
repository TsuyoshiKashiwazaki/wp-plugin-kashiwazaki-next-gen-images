<?php
/**
 * 管理画面（設定・一括変換・診断）と、メディアライブラリへの表示。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面のクラス。
 */
final class Kashiwazaki_Next_Gen_Images_Admin {

	const NONCE_ACTION = 'kashiwazaki_next_gen_images_ajax';
	const AJAX_PREFIX  = 'kashiwazaki_next_gen_images_';
	const MENU_TITLE   = 'Kashiwazaki Next-Gen Images';

	/**
	 * 管理画面のフックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'convert', array( __CLASS__, 'ajax_convert' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'job_start', array( __CLASS__, 'ajax_job_start' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'job_tick', array( __CLASS__, 'ajax_job_tick' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'job_stop', array( __CLASS__, 'ajax_job_stop' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'job_status', array( __CLASS__, 'ajax_job_status' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_job_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'mime_test', array( __CLASS__, 'ajax_mime_test' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREFIX . 'engine_test', array( __CLASS__, 'ajax_engine_test' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
	}

	/**
	 * 管理メニュー（位置 81）。
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			self::MENU_TITLE,
			self::MENU_TITLE,
			'manage_options',
			KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-images-alt2',
			81
		);
	}

	/**
	 * スクリプトとスタイルを読み込む（必要な画面だけ）。
	 *
	 * @param string $hook 画面のフック名。
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		$is_plugin_page = ( 'toplevel_page_' . KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG === $hook );
		$is_media_page  = in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true );
		$job            = Kashiwazaki_Next_Gen_Images_Job::get();
		// 一括処理が進行中なら、どの管理画面でも進捗を出し、見ている間は処理を進める（停止中は、この画面の中だけに出す）。
		$has_job = $job && 'running' === $job['state'] && current_user_can( 'manage_options' );
		if ( ! $is_plugin_page && ! $is_media_page && ! $has_job ) {
			return;
		}
		// ファイルの更新時刻をバージョンに足す。更新後に、ブラウザが古い CSS / JS を使い続けないため。
		$css_ver = KASHIWAZAKI_NEXT_GEN_IMAGES_VERSION . '.' . (int) @filemtime( KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'assets/css/admin.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$js_ver  = KASHIWAZAKI_NEXT_GEN_IMAGES_VERSION . '.' . (int) @filemtime( KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'assets/js/admin.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_enqueue_style( 'kashiwazaki-next-gen-images-admin', KASHIWAZAKI_NEXT_GEN_IMAGES_URL . 'assets/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'kashiwazaki-next-gen-images-admin', KASHIWAZAKI_NEXT_GEN_IMAGES_URL . 'assets/js/admin.js', array(), $js_ver, true );
		wp_localize_script(
			'kashiwazaki-next-gen-images-admin',
			'kashiwazakiNextGenImages',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'prefix'  => self::AJAX_PREFIX,
				'job'     => $has_job || $is_plugin_page ? Kashiwazaki_Next_Gen_Images_Job::summary( $job ) : null,
				'i18n'    => array(
					'working'       => __( '処理中…', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'serverError'   => __( 'サーバーの応答が不正です（時間切れまたはメモリ不足の可能性）。しばらくしてからもう一度お試しください。', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'start'         => __( '一括変換を開始', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'stop'          => __( '停止', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'stopping'      => __( '停止しています…', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'elapsed'       => __( '経過', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'took'          => __( '所要時間', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'now'           => __( 'いま', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'eta'           => __( '残りの目安: 約 %s 分', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'eta_soon'      => __( '残りの目安: 1 分未満', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'resume'        => __( '続きから再開', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'confirmRemove' => __( '変換済みの WebP / AVIF ファイルを削除します。元の画像は削除されません。\n\nページキャッシュ系のプラグインやサーバーのキャッシュを使っている場合は、削除したあとでキャッシュも消してください（古い表示が残ると、対応ブラウザで画像が表示されなくなります）。\n\nよろしいですか？', 'wp-plugin-kashiwazaki-next-gen-images' ),
					'confirmRestart' => __( '最初からやり直します。よろしいですか？', 'wp-plugin-kashiwazaki-next-gen-images' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* 画面                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * 管理画面のタブ一覧。
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'settings' => __( '設定', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'bulk'     => __( '一括変換', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'status'   => __( '診断', 'wp-plugin-kashiwazaki-next-gen-images' ),
		);
	}

	/**
	 * 管理画面を出力する。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap kashiwazaki-next-gen-images-wrap">';
		echo '<div class="kashiwazaki-next-gen-images-header"><h1>' . esc_html( self::MENU_TITLE ) . '</h1>';
		echo '<span class="kashiwazaki-next-gen-images-pill">v' . esc_html( KASHIWAZAKI_NEXT_GEN_IMAGES_VERSION ) . '</span>';
		echo '<span class="kashiwazaki-next-gen-images-pill kashiwazaki-next-gen-images-pill-safe"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( '元の画像は削除も変更もされません', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span></div>';
		echo '<hr class="wp-header-end">';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $url ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		self::render_conflict_notice();

		if ( 'bulk' === $tab ) {
			self::render_bulk_tab();
		} elseif ( 'status' === $tab ) {
			self::render_status_tab();
		} else {
			self::render_settings_tab();
		}
		echo '</div>';
	}

	/**
	 * ほかの画像最適化プラグインが動いていれば知らせる。
	 *
	 * @return void
	 */
	private static function render_conflict_notice() {
		$found = self::detect_conflicts();
		if ( empty( $found ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( '他の画像最適化・WebP 系プラグインが有効です：', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</strong> ' . esc_html( implode( '、', $found ) ) . '</p>';
		echo '<p>' . esc_html__( '同じ画像を両方が処理しても表示が壊れないようにしてあります（すでに <picture> で包まれた画像は包み直しません。他のプラグインが作った変換ファイルは、上書きも一括削除もしませんが、元画像より新しければそのまま配信に使います。元画像を削除しても、他のプラグインが作った変換ファイルは消さずに残します。消せるのは、このプラグインが作った変換ファイルだけです）。ただし二重の処理は無駄なので、どちらかの WebP 変換・配信を止めることをおすすめします。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p></div>';
	}

	/**
	 * 競合しそうなプラグインを探す。
	 *
	 * @return string[]
	 */
	private static function detect_conflicts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$known = array(
			'ewww-image-optimizer/ewww-image-optimizer.php'                   => 'EWWW Image Optimizer',
			'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php'       => 'EWWW Image Optimizer Cloud',
			'imagify/imagify.php'                                             => 'Imagify',
			'shortpixel-image-optimiser/wp-shortpixel.php'                    => 'ShortPixel Image Optimizer',
			'webp-express/webp-express.php'                                   => 'WebP Express',
			'webp-converter-for-media/webp-converter-for-media.php'           => 'Converter for Media',
			'optimole-wp/optimole-wp.php'                                     => 'Optimole',
			'wp-smushit/wp-smush.php'                                         => 'Smush',
			'litespeed-cache/litespeed-cache.php'                             => 'LiteSpeed Cache',
			'wp-rocket/wp-rocket.php'                                         => 'WP Rocket',
			'jetpack/jetpack.php'                                             => 'Jetpack',
			'performance-lab/load.php'                                        => 'Performance Lab',
		);
		$found = array();
		foreach ( $known as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * 設定タブ。
	 *
	 * 保存は WordPress の設定 API（options.php）に任せ、ここでは見た目だけを作る。
	 * 区画ごとのカード、スイッチ、スライダー、ツールチップで整理している。
	 *
	 * @return void
	 */
	private static function render_settings_tab() {
		$s       = Kashiwazaki_Next_Gen_Images_Settings::get();
		$support = Kashiwazaki_Next_Gen_Images_Engine::support();

		self::maybe_auto_mime_test();
		$mime = get_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, array() );

		if ( empty( $support['webp'] ) && empty( $support['avif'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'このサーバーでは WebP・AVIF を作れません（PHP の GD か Imagick が必要です）。変換は行われず、サイトの表示には影響しません。詳しくは「診断」タブをご覧ください。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p></div>';
		}
		if ( is_array( $mime ) ) {
			foreach ( array( 'webp', 'avif' ) as $f ) {
				if ( isset( $mime[ $f ] ) && 'ng' === $mime[ $f ] ) {
					echo '<div class="notice notice-error"><p>' . sprintf(
						/* translators: %s: format name */
						esc_html__( '%s の配信テストに失敗したため、安全のためこの形式の表示を止めています。「診断」タブで原因を確認してください。', 'wp-plugin-kashiwazaki-next-gen-images' ),
						esc_html( strtoupper( $f ) )
					) . '</p></div>';
				}
			}
		}

		// 保存後(options.php から settings-updated で戻る)の「設定を保存しました」の通知。add_menu_page の画面では WordPress が自動では出さない(options-general.php の配下の画面だけ)。
		settings_errors();
		echo '<form method="post" action="options.php" class="kashiwazaki-next-gen-images-form">';
		settings_fields( Kashiwazaki_Next_Gen_Images_Settings::GROUP );
		echo '<div class="kashiwazaki-next-gen-images-grid">';

		// 1. 変換する形式。
		self::ui_card_open( __( '変換する形式', 'wp-plugin-kashiwazaki-next-gen-images' ), __( 'どの形式の画像を作るか', 'wp-plugin-kashiwazaki-next-gen-images' ), 'format-image' );
		foreach ( array(
			'webp' => array( 'WebP', __( '多くのブラウザで使える、軽い画像形式です。', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
			'avif' => array( 'AVIF', __( 'WebP よりさらに軽くなります。変換に少し時間がかかります。', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
		) as $f => $info ) {
			$available = ! empty( $support[ $f ] );
			$tip       = $info[1] . ' ' . ( $available ? self::engine_summary( Kashiwazaki_Next_Gen_Images_Engine::engines_for( $f ) ) : __( 'このサーバーの PHP が対応していないため作れません。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
			$badge     = $available
				? '<span class="kashiwazaki-next-gen-images-badge kashiwazaki-next-gen-images-badge-ok">' . esc_html__( '作成できます', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>'
				: '<span class="kashiwazaki-next-gen-images-badge kashiwazaki-next-gen-images-badge-ng">' . esc_html__( 'このサーバーでは作れません', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';
			self::ui_switch_row( 'enable_' . $f, $info[0], $s[ 'enable_' . $f ], $tip, $badge );
		}
		echo '<p class="kashiwazaki-next-gen-images-hint">' . esc_html__( '表示は AVIF → WebP → 元の画像の順に、ブラウザが使えるものを選びます。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p>';
		self::ui_card_close();

		// 2. 画質と軽さ。
		self::ui_card_open( __( '画質と軽さ', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '数字が大きいほどきれいで、ファイルも大きくなります', 'wp-plugin-kashiwazaki-next-gen-images' ), 'image-filter' );
		self::ui_range_row( 'webp_quality', __( 'WebP の画質', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['webp_quality'], 1, 100, __( '1〜100。80 前後が目安です。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_range_row( 'avif_quality', __( 'AVIF の画質', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['avif_quality'], 1, 100, __( '1〜100。AVIF は低めの数字でもきれいです。60 前後が目安です。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_switch_row( 'png_lossless', __( 'PNG・GIF は劣化なしで変換', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['png_lossless'], __( 'ロゴ・図・スクリーンショットの文字や線が崩れません。写真の PNG では、あまり軽くならないことがあります。AVIF は劣化なしで作れないため、この設定のときは PNG・GIF の AVIF を作らず、WebP だけにします。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_switch_row( 'only_if_smaller', __( '軽くならなければ保存しない', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['only_if_smaller'], __( '変換後のファイルが元より大きい・同じなら捨てて、元の画像のまま表示します。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_card_close();

		// 3. 自動変換。
		self::ui_card_open( __( '新しい画像の自動変換', 'wp-plugin-kashiwazaki-next-gen-images' ), __( 'アップロードしたときの動き', 'wp-plugin-kashiwazaki-next-gen-images' ), 'upload' );
		self::ui_switch_row( 'auto_convert', __( 'アップロード時に自動で変換', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['auto_convert'], __( 'オフでも、「一括変換」タブからいつでも変換できます。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_segmented_row(
			'convert_timing',
			__( '変換のタイミング', 'wp-plugin-kashiwazaki-next-gen-images' ),
			$s['convert_timing'],
			array(
				'immediate'  => __( 'アップロード時（おすすめ）', 'wp-plugin-kashiwazaki-next-gen-images' ),
				'background' => __( 'あとで自動', 'wp-plugin-kashiwazaki-next-gen-images' ),
			),
			__( '「アップロード時」は、サーバーの時間制限を超えないよう時間を区切って変換し、終わらなかった分はあとで続けます。「あとで自動」は WP-Cron が変換するので速いですが、WP-Cron が動いている必要があります。', 'wp-plugin-kashiwazaki-next-gen-images' )
		);
		self::ui_card_close();

		// 4. 表示。
		self::ui_card_open( __( 'サイトでの表示', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '変換した画像をどう見せるか', 'wp-plugin-kashiwazaki-next-gen-images' ), 'visibility' );
		self::ui_switch_row( 'delivery_enabled', __( '変換した画像を表示する', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['delivery_enabled'], __( 'オフにすると、変換済みファイルがあっても元の画像のまま表示します。問題の切り分けに使えます。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_segmented_row(
			'delivery_scope',
			__( '切り替える範囲', 'wp-plugin-kashiwazaki-next-gen-images' ),
			$s['delivery_scope'],
			array(
				'content' => __( '記事本文など（おすすめ）', 'wp-plugin-kashiwazaki-next-gen-images' ),
				'page'    => __( 'ページ全体', 'wp-plugin-kashiwazaki-next-gen-images' ),
			),
			__( '「記事本文など」は、記事・アイキャッチ・ウィジェット・画像ブロックが対象で、安全です。「ページ全体」はテーマが直接出す画像も対象ですが、他のプラグインの出力と干渉することがあります。CSS の background-image は、どちらでも対象外です。', 'wp-plugin-kashiwazaki-next-gen-images' )
		);
		self::ui_switch_row( 'delivery_css', __( '表示崩れ防止の CSS を出力', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['delivery_css'], __( '画像のレイアウトが変わらないよう、1 行の CSS を出力します。通常はオンのままにしてください。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_switch_row( 'repair_wrappers', __( '他のプラグインが画像を包み直しても対応', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['repair_wrappers'], __( 'ライトボックスなどが画像を別の要素で包むと、WebP / AVIF が使われず元の画像のままになります。それを自動で直す小さなスクリプトを出力します。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		self::ui_card_close();

		echo '</div>'; // グリッド（上の 4 枚）。

		// 5. 詳細設定（折りたたみ）。
		echo '<details class="kashiwazaki-next-gen-images-card kashiwazaki-next-gen-images-card-wide kashiwazaki-next-gen-images-advanced">';
		echo '<summary><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><span class="kashiwazaki-next-gen-images-card-title">' . esc_html__( '詳細設定', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span><span class="kashiwazaki-next-gen-images-card-sub">' . esc_html__( '通常は変更不要です', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span></summary>';
		echo '<div class="kashiwazaki-next-gen-images-card-body">';

		$opt = Kashiwazaki_Next_Gen_Images_Settings::OPTION;
		self::ui_row_open( 'engine_preference', __( '変換エンジン', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '画像を作る道具です。「自動」なら、使えるものを優先順に使います。指定した道具で失敗したときは、使える別の道具で自動的にやり直します。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		echo '<select id="kashiwazaki-next-gen-images-field-engine_preference" name="' . esc_attr( $opt ) . '[engine_preference]">';
		foreach ( array(
			'auto'    => __( '自動（おすすめ）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'gd'      => 'GD',
			'imagick' => 'Imagick',
		) as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $s['engine_preference'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		self::ui_row_close();

		self::ui_range_row( 'max_megapixels', __( '変換する画像の最大サイズ', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['max_megapixels'], 1, 200, __( '単位は百万画素。これを超える巨大な画像は、サーバーのメモリ不足を避けるため変換しません。共用レンタルサーバーでは 30 前後が安全です。', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '小さい', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '大きい', 'wp-plugin-kashiwazaki-next-gen-images' ), ' ' . __( '百万画素', 'wp-plugin-kashiwazaki-next-gen-images' ) );

		self::ui_row_open( '', __( '変換しない画像サイズ', 'wp-plugin-kashiwazaki-next-gen-images' ), __( 'チェックしたサイズは変換しません。通常は何もチェックしなくて構いません。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		echo '<div class="kashiwazaki-next-gen-images-chips">';
		foreach ( Kashiwazaki_Next_Gen_Images_Settings::known_sizes() as $size ) {
			printf(
				'<label class="kashiwazaki-next-gen-images-chip"><input type="checkbox" name="%1$s[exclude_sizes][]" value="%2$s" %3$s><span>%4$s</span></label>',
				esc_attr( $opt ),
				esc_attr( $size ),
				checked( in_array( $size, (array) $s['exclude_sizes'], true ), true, false ),
				esc_html( 'full' === $size ? __( 'フルサイズ', 'wp-plugin-kashiwazaki-next-gen-images' ) : $size )
			);
		}
		echo '</div>';
		self::ui_row_close();

		self::ui_row_open( 'exclude_classes', __( '除外する CSS クラス', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '1 行に 1 つ。このクラスが付いた画像は、元のまま表示します。スライダーなど、画像の親要素を JavaScript で探すものに不具合が出たときに使います。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		printf( '<textarea id="kashiwazaki-next-gen-images-field-exclude_classes" name="%1$s[exclude_classes]" rows="3" class="large-text code">%2$s</textarea>', esc_attr( $opt ), esc_textarea( $s['exclude_classes'] ) );
		self::ui_row_close();

		self::ui_row_open( 'exclude_urls', __( '除外する画像 URL', 'wp-plugin-kashiwazaki-next-gen-images' ), __( '1 行に 1 つ。画像の URL にこの文字列が含まれていたら、元のまま表示します（例: /logo/）。', 'wp-plugin-kashiwazaki-next-gen-images' ) );
		printf( '<textarea id="kashiwazaki-next-gen-images-field-exclude_urls" name="%1$s[exclude_urls]" rows="3" class="large-text code">%2$s</textarea>', esc_attr( $opt ), esc_textarea( $s['exclude_urls'] ) );
		self::ui_row_close();

		self::ui_switch_row( 'delete_on_uninstall', __( 'プラグイン削除時に変換ファイルも削除', 'wp-plugin-kashiwazaki-next-gen-images' ), $s['delete_on_uninstall'], __( '削除されるのは、このプラグインが作った WebP / AVIF だけです。元の画像は削除されません。', 'wp-plugin-kashiwazaki-next-gen-images' ) );

		echo '</div></details>';

		echo '<div class="kashiwazaki-next-gen-images-savebar">';
		submit_button( __( '変更を保存', 'wp-plugin-kashiwazaki-next-gen-images' ), 'primary large', 'submit', false );
		echo '<span class="kashiwazaki-next-gen-images-savebar-note">' . esc_html__( '元の画像は、どの設定でも削除・変更されません。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';
		echo '</div>';
		echo '</form>';
	}

	/**
	 * カードの開始（見出し付きの区画）。
	 *
	 * @param string $title 見出し。
	 * @param string $sub   見出しの補足（1 行）。
	 * @param string $icon  Dashicons の名前（dashicons- を除いたもの）。
	 * @return void
	 */
	private static function ui_card_open( $title, $sub, $icon ) {
		echo '<section class="kashiwazaki-next-gen-images-card"><header class="kashiwazaki-next-gen-images-card-head">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<div><h2 class="kashiwazaki-next-gen-images-card-title">' . esc_html( $title ) . '</h2><p class="kashiwazaki-next-gen-images-card-sub">' . esc_html( $sub ) . '</p></div>';
		echo '</header><div class="kashiwazaki-next-gen-images-card-body">';
	}

	/**
	 * カードの終了。
	 *
	 * @return void
	 */
	private static function ui_card_close() {
		echo '</div></section>';
	}

	/**
	 * ツールチップ付きの「？」ボタン。
	 *
	 * 参照: WAI-ARIA Authoring Practices「Tooltip」。role=tooltip、トリガーから aria-describedby で参照、
	 * ホバーとフォーカスで表示、Esc で閉じる。ツールチップ自体はフォーカスを受けない。
	 *
	 * @param string $id   ツールチップの id。
	 * @param string $text 説明。
	 * @return string HTML（エスケープ済み）。
	 */
	private static function ui_tip( $id, $text ) {
		return '<span class="kashiwazaki-next-gen-images-tip"><button type="button" class="kashiwazaki-next-gen-images-tip-button" aria-describedby="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( '説明を表示', 'wp-plugin-kashiwazaki-next-gen-images' ) . '">?</button><span role="tooltip" id="' . esc_attr( $id ) . '" class="kashiwazaki-next-gen-images-tip-text">' . esc_html( $text ) . '</span></span>';
	}

	/**
	 * 1 項目の行の開始（左にラベルと「？」、右に入力）。
	 *
	 * @param string $key   入力の項目名（ラベルの for 用。無ければ空）。
	 * @param string $label ラベル。
	 * @param string $tip   ツールチップの説明（空なら出さない）。
	 * @return void
	 */
	private static function ui_row_open( $key, $label, $tip = '' ) {
		$id = 'kashiwazaki-next-gen-images-field-' . $key;
		echo '<div class="kashiwazaki-next-gen-images-row"><div class="kashiwazaki-next-gen-images-row-label">';
		if ( '' !== $key ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo '<span class="kashiwazaki-next-gen-images-label-text">' . esc_html( $label ) . '</span>';
		}
		if ( '' !== $tip ) {
			echo self::ui_tip( 'kashiwazaki-next-gen-images-tip-' . ( '' !== $key ? $key : sanitize_title( $label ) ), $tip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ui_tip() 内でエスケープ済み。
		}
		echo '</div><div class="kashiwazaki-next-gen-images-row-control">';
	}

	/**
	 * 行の終了。
	 *
	 * @return void
	 */
	private static function ui_row_close() {
		echo '</div></div>';
	}

	/**
	 * オン・オフのスイッチの行。
	 *
	 * @param string $key   設定のキー。
	 * @param string $label ラベル。
	 * @param mixed  $value 現在の値。
	 * @param string $tip   ツールチップの説明。
	 * @param string $extra スイッチの横に出す HTML（エスケープ済みのもの）。
	 * @return void
	 */
	private static function ui_switch_row( $key, $label, $value, $tip, $extra = '' ) {
		$id = 'kashiwazaki-next-gen-images-field-' . $key;
		self::ui_row_open( $key, $label, $tip );
		if ( '' !== $extra ) {
			echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し元でエスケープ済み。
		}
		printf(
			'<label class="kashiwazaki-next-gen-images-switch"><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s><span class="kashiwazaki-next-gen-images-switch-track" aria-hidden="true"></span></label>',
			esc_attr( $id ),
			esc_attr( Kashiwazaki_Next_Gen_Images_Settings::OPTION ),
			esc_attr( $key ),
			checked( ! empty( $value ), true, false )
		);
		self::ui_row_close();
	}

	/**
	 * スライダー（ボリュームバー）の行。現在値を横に表示する。
	 *
	 * @param string $key    設定のキー。
	 * @param string $label  ラベル。
	 * @param int    $value  現在の値。
	 * @param int    $min    最小。
	 * @param int    $max    最大。
	 * @param string $tip    ツールチップの説明。
	 * @param string $low    左端の説明。
	 * @param string $high   右端の説明。
	 * @param string $suffix 値のあとに付ける単位。
	 * @return void
	 */
	private static function ui_range_row( $key, $label, $value, $min, $max, $tip, $low = '', $high = '', $suffix = '' ) {
		$id   = 'kashiwazaki-next-gen-images-field-' . $key;
		$low  = ( '' === $low ) ? __( '軽い', 'wp-plugin-kashiwazaki-next-gen-images' ) : $low;
		$high = ( '' === $high ) ? __( 'きれい', 'wp-plugin-kashiwazaki-next-gen-images' ) : $high;
		self::ui_row_open( $key, $label, $tip );
		printf(
			'<div class="kashiwazaki-next-gen-images-range"><input type="range" id="%1$s" name="%2$s[%3$s]" min="%4$d" max="%5$d" step="1" value="%6$d"><output for="%1$s" data-suffix="%7$s">%6$d%7$s</output></div><div class="kashiwazaki-next-gen-images-range-scale"><span>%8$s</span><span>%9$s</span></div>',
			esc_attr( $id ),
			esc_attr( Kashiwazaki_Next_Gen_Images_Settings::OPTION ),
			esc_attr( $key ),
			(int) $min,
			(int) $max,
			(int) $value,
			esc_attr( $suffix ),
			esc_html( $low ),
			esc_html( $high )
		);
		self::ui_row_close();
	}

	/**
	 * 2〜3 択のボタン風の選択（ラジオボタン）の行。
	 *
	 * @param string $key     設定のキー。
	 * @param string $label   ラベル。
	 * @param string $value   現在の値。
	 * @param array  $choices 値 => 表示名。
	 * @param string $tip     ツールチップの説明。
	 * @return void
	 */
	private static function ui_segmented_row( $key, $label, $value, array $choices, $tip ) {
		self::ui_row_open( '', $label, $tip );
		echo '<div class="kashiwazaki-next-gen-images-segmented" role="radiogroup" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $choices as $choice => $text ) {
			printf(
				'<label><input type="radio" name="%1$s[%2$s]" value="%3$s" %4$s><span>%5$s</span></label>',
				esc_attr( Kashiwazaki_Next_Gen_Images_Settings::OPTION ),
				esc_attr( $key ),
				esc_attr( $choice ),
				checked( $value, $choice, false ),
				esc_html( $text )
			);
		}
		echo '</div>';
		self::ui_row_close();
	}

	/**
	 * 「どの道具で作るか」を、初めて見る人にも分かる文章にする。
	 *
	 * @param string[] $engines 使えるエンジン（使う順）。
	 * @return string
	 */
	private static function engine_summary( array $engines ) {
		$engines = array_values( $engines );
		if ( empty( $engines ) ) {
			return '';
		}
		$first = self::engine_label( $engines[0] );
		if ( count( $engines ) >= 2 ) {
			return sprintf(
				/* translators: 1: first engine name, 2: second engine name */
				__( '画像を作る道具は %1$s を使い、うまくいかないときは %2$s で作り直します。', 'wp-plugin-kashiwazaki-next-gen-images' ),
				$first,
				self::engine_label( $engines[1] )
			);
		}
		return sprintf(
			/* translators: %s: engine name */
			__( '画像を作る道具は %s だけです。', 'wp-plugin-kashiwazaki-next-gen-images' ),
			$first
		);
	}

	/**
	 * エンジン名の表示。
	 *
	 * @param string $engine エンジンの内部名。
	 * @return string
	 */
	private static function engine_label( $engine ) {
		return ( 'imagick' === $engine ) ? 'Imagick' : 'GD';
	}

	/**
	 * 一括変換タブ。
	 *
	 * 進み具合はサーバーに保存されているので、別のページへ移って戻っても、同じ進捗バーが出る。
	 *
	 * @return void
	 */
	private static function render_bulk_tab() {
		$stats   = Kashiwazaki_Next_Gen_Images_Converter::stats();
		$formats = Kashiwazaki_Next_Gen_Images_Converter::active_formats();
		$job     = Kashiwazaki_Next_Gen_Images_Job::summary( Kashiwazaki_Next_Gen_Images_Job::get() );
		$state   = $job ? $job['state'] : 'none';

		if ( empty( $formats ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( '変換できる形式がありません。「設定」タブで形式を有効にするか、「診断」タブでサーバーの対応状況を確認してください。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p></div>';
		}

		// 既存の画像を一括変換。
		echo '<section class="kashiwazaki-next-gen-images-card kashiwazaki-next-gen-images-card-wide" style="margin-top:0">';
		echo '<header class="kashiwazaki-next-gen-images-card-head"><span class="dashicons dashicons-images-alt2" aria-hidden="true"></span><div><h2 class="kashiwazaki-next-gen-images-card-title">' . esc_html__( '既存の画像を一括変換', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</h2><p class="kashiwazaki-next-gen-images-card-sub">' . esc_html__( 'メディアライブラリの画像を、1 枚ずつ順番に変換します', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p></div></header>';
		echo '<div class="kashiwazaki-next-gen-images-card-body">';

		$pending = max( 0, (int) $stats['images'] - (int) $stats['attachments'] );
		echo '<div class="kashiwazaki-next-gen-images-stats">';
		foreach ( array(
			array( (int) $stats['images'], __( '画像の総数', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
			array( (int) $stats['attachments'], __( '変換済み', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
			array( $pending, __( '未変換（目安）', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
		) as $stat ) {
			echo '<div class="kashiwazaki-next-gen-images-stat"><strong>' . esc_html( number_format_i18n( $stat[0] ) ) . '</strong><span>' . esc_html( $stat[1] ) . '</span></div>';
		}
		echo '</div>';

		// 開始前のオプション（実行中は変えられない）。
		echo '<div class="kashiwazaki-next-gen-images-row"><div class="kashiwazaki-next-gen-images-row-label"><label for="kashiwazaki-next-gen-images-retry">' . esc_html__( '以前に失敗した画像も試す', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</label>' . self::ui_tip( 'kashiwazaki-next-gen-images-tip-retry', __( '前回うまく変換できなかった画像を、もう一度試します。', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="kashiwazaki-next-gen-images-row-control"><label class="kashiwazaki-next-gen-images-switch"><input type="checkbox" id="kashiwazaki-next-gen-images-retry"><span class="kashiwazaki-next-gen-images-switch-track" aria-hidden="true"></span></label></div></div>';
		echo '<div class="kashiwazaki-next-gen-images-row"><div class="kashiwazaki-next-gen-images-row-label"><label for="kashiwazaki-next-gen-images-force">' . esc_html__( 'すべて作り直す', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</label>' . self::ui_tip( 'kashiwazaki-next-gen-images-tip-force', __( '変換済みの画像も含めて、すべて作り直します。画質などの設定を変えたあとに使います。', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="kashiwazaki-next-gen-images-row-control"><label class="kashiwazaki-next-gen-images-switch"><input type="checkbox" id="kashiwazaki-next-gen-images-force"><span class="kashiwazaki-next-gen-images-switch-track" aria-hidden="true"></span></label></div></div>';

		$running = ( 'running' === $state );
		$paused  = ( 'paused' === $state && $job && 'convert' === $job['kind'] );
		echo '<p class="kashiwazaki-next-gen-images-actions">';
		echo '<button type="button" class="button button-primary button-large" id="kashiwazaki-next-gen-images-start"' . ( ( empty( $formats ) || $running ) ? ' disabled' : '' ) . '>' . esc_html( $paused ? __( '続きから再開', 'wp-plugin-kashiwazaki-next-gen-images' ) : __( '一括変換を開始', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</button> ';
		echo '<button type="button" class="button button-large" id="kashiwazaki-next-gen-images-stop"' . ( $running ? '' : ' disabled' ) . '>' . esc_html__( '停止', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</button> ';
		echo '<button type="button" class="button button-large" id="kashiwazaki-next-gen-images-restart"' . ( $paused ? '' : ' hidden' ) . '>' . esc_html__( '最初からやり直す', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</button>';
		echo '</p>';

		// 進捗（サーバーに保存された状態をそのまま表示する）。
		echo '<div id="kashiwazaki-next-gen-images-job"' . ( $job ? '' : ' hidden' ) . '>';
		echo '<div class="kashiwazaki-next-gen-images-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $job ? (string) $job['percent'] : '0' ) . '"><div class="kashiwazaki-next-gen-images-progress-bar' . ( 'done' === $state ? ' is-done' : ( 'running' === $state ? ' is-running' : '' ) ) . '" style="width:' . esc_attr( $job ? (string) $job['percent'] : '0' ) . '%"></div></div>';
		echo '<p class="kashiwazaki-next-gen-images-status' . ( $running ? ' is-running' : '' ) . '" aria-live="off">' . esc_html( $job ? $job['percent'] . '% — ' . $job['text'] : '' ) . '</p>';
		echo '<p class="kashiwazaki-next-gen-images-meta" aria-hidden="true"></p>';
		echo '<ul class="kashiwazaki-next-gen-images-log">';
		if ( $job ) {
			foreach ( $job['log'] as $row ) {
				echo '<li' . ( $row['e'] ? ' class="is-error"' : '' ) . '>' . esc_html( $row['m'] ) . '</li>';
			}
		}
		echo '</ul></div>';
		echo '<p class="kashiwazaki-next-gen-images-hint">' . esc_html__( '別のページに移っても、管理画面を開いている間は処理が進みます。止めても、続きから再開できます。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p>';
		echo '</div></section>';

		// 変換済みファイルの削除。
		echo '<section class="kashiwazaki-next-gen-images-card kashiwazaki-next-gen-images-card-wide">';
		echo '<header class="kashiwazaki-next-gen-images-card-head"><span class="dashicons dashicons-trash" aria-hidden="true"></span><div><h2 class="kashiwazaki-next-gen-images-card-title">' . esc_html__( '変換済みファイルの削除', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</h2><p class="kashiwazaki-next-gen-images-card-sub">' . esc_html__( 'このプラグインが作った WebP / AVIF だけを消します。元の画像は消えません', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p></div></header>';
		echo '<div class="kashiwazaki-next-gen-images-card-body"><div class="kashiwazaki-next-gen-images-row"><div class="kashiwazaki-next-gen-images-row-label"><span class="kashiwazaki-next-gen-images-label-text">' . esc_html__( 'すべての変換済みファイルを削除', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>' . self::ui_tip( 'kashiwazaki-next-gen-images-tip-remove', __( '削除後は、サイトは元の画像で表示されます。ページキャッシュ系のプラグインやサーバーのキャッシュを使っている場合は、削除したあとでキャッシュも消してください。古い表示が残ると、対応ブラウザで画像が表示されなくなります。', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="kashiwazaki-next-gen-images-row-control"><button type="button" class="button button-secondary" id="kashiwazaki-next-gen-images-remove"' . ( $running ? ' disabled' : '' ) . '>' . esc_html__( '削除する', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</button></div></div></div>';
		echo '</section>';
	}

	/**
	 * 診断タブ。
	 *
	 * @return void
	 */
	private static function render_status_tab() {
		$info    = Kashiwazaki_Next_Gen_Images_Engine::library_info();
		$support = Kashiwazaki_Next_Gen_Images_Engine::support();
		$tests   = Kashiwazaki_Next_Gen_Images_Engine::test_results();
		$stats   = Kashiwazaki_Next_Gen_Images_Converter::stats( isset( $_GET['recount'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mime    = get_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, array() );
		$yes     = '<span class="kashiwazaki-next-gen-images-ok">' . esc_html__( '○ 利用可', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';
		$no      = '<span class="kashiwazaki-next-gen-images-ng">' . esc_html__( '× 利用不可', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';

		// 変換エンジン。
		echo '<div class="kashiwazaki-next-gen-images-box"><h2>' . esc_html__( '変換エンジン（このサーバーの対応状況）', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'ライブラリ', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th><th>WebP</th><th>AVIF</th><th>' . esc_html__( '備考', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th></tr></thead><tbody>';
		echo '<tr><td>GD</td><td>' . ( ! empty( $tests['gd']['webp'] ) ? $yes : $no ) . '</td><td>' . ( ! empty( $tests['gd']['avif'] ) ? $yes : $no ) . '</td><td>' . esc_html( $info['gd_loaded'] ? $info['gd_version'] : __( '未導入', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><td>Imagick</td><td>' . ( ! empty( $tests['imagick']['webp'] ) ? $yes : $no ) . '</td><td>' . ( ! empty( $tests['imagick']['avif'] ) ? $yes : $no ) . '</td><td>' . esc_html( $info['imagick_loaded'] ? $info['imagick_version'] : __( '未導入', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( '「利用可」は、実際に小さな画像を書き出せたことを確認した結果です（ライブラリが入っているだけでは「利用可」になりません）。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p>';
		if ( empty( $support['avif'] ) ) {
			echo '<p class="description">' . esc_html__( 'AVIF が利用不可でも、WebP だけで動作します。AVIF は PHP 8.1 以上かつ libavif 対応の GD、または AVIF 対応の Imagick が必要です。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p>';
		}
		echo '<p><button type="button" class="button" id="kashiwazaki-next-gen-images-engine-test">' . esc_html__( '動作確認をやり直す', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</button> <span id="kashiwazaki-next-gen-images-engine-test-result"></span></p>';
		echo '</div>';

		// サーバー環境。
		$max_exec = (int) ini_get( 'max_execution_time' );
		echo '<div class="kashiwazaki-next-gen-images-box"><h2>' . esc_html__( 'サーバー環境', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</h2><table class="widefat striped"><tbody>';
		$rows = array(
			array( 'PHP', PHP_VERSION ),
			array( 'memory_limit', (string) ini_get( 'memory_limit' ) ),
			array( 'max_execution_time', $max_exec > 0 ? $max_exec . ' ' . __( '秒', 'wp-plugin-kashiwazaki-next-gen-images' ) : __( '無制限', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
			array( __( '1 回の処理に使う時間（自動）', 'wp-plugin-kashiwazaki-next-gen-images' ), round( Kashiwazaki_Next_Gen_Images_Engine::time_budget() ) . ' ' . __( '秒', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
			array( 'WP-Cron', ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( '無効（DISABLE_WP_CRON）。「あとで自動」は、サーバーの cron から wp-cron.php を呼ぶ設定が無いと動きません。「アップロード時（おすすめ）」をおすすめします。', 'wp-plugin-kashiwazaki-next-gen-images' ) : __( '有効', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
			array( __( 'アップロードフォルダ', 'wp-plugin-kashiwazaki-next-gen-images' ), wp_is_writable( Kashiwazaki_Next_Gen_Images_Converter::uploads_basedir() ) ? __( '書き込み可', 'wp-plugin-kashiwazaki-next-gen-images' ) : __( '書き込み不可（変換ファイルを保存できません）', 'wp-plugin-kashiwazaki-next-gen-images' ) ),
		);
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row" style="width:240px">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		// 配信テスト。
		echo '<div class="kashiwazaki-next-gen-images-box"><h2>' . esc_html__( '配信テスト（サーバーが WebP・AVIF を正しく配信できるか）', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</h2>';
		echo '<p>' . esc_html__( 'サーバーが .webp / .avif を正しい種類（Content-Type）で配信しない場合、ブラウザが画像を表示できません。そのため、テストで問題が見つかった形式は自動的に配信を止めます。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( array( 'webp', 'avif' ) as $f ) {
			$state = ( is_array( $mime ) && isset( $mime[ $f ] ) ) ? $mime[ $f ] : 'unknown';
			$note  = ( is_array( $mime ) && isset( $mime[ $f . '_note' ] ) ) ? $mime[ $f . '_note' ] : '';
			echo '<tr><th scope="row" style="width:240px">' . esc_html( strtoupper( $f ) ) . '</th><td id="kashiwazaki-next-gen-images-mime-' . esc_attr( $f ) . '">' . esc_html( self::mime_state_label( $state ) . ( '' !== $note ? ' — ' . $note : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="kashiwazaki-next-gen-images-mime-test">' . esc_html__( '配信テストを実行', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</button> <span id="kashiwazaki-next-gen-images-mime-test-result"></span></p>';
		echo '<details><summary>' . esc_html__( '配信テストに失敗したときの対処（Apache 系のレンタルサーバー向け）', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</summary>';
		echo '<p>' . esc_html__( 'サイトのトップフォルダ（WordPress のフォルダ）の .htaccess に、次の 3 行を追記してから、もう一度テストしてください。', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</p>';
		echo '<pre class="kashiwazaki-next-gen-images-code">&lt;IfModule mod_mime.c&gt;
AddType image/webp .webp
AddType image/avif .avif
&lt;/IfModule&gt;</pre></details>';
		echo '</div>';

		// 実績。
		echo '<div class="kashiwazaki-next-gen-images-box"><h2>' . esc_html__( '変換の実績', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( '形式', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th><th>' . esc_html__( 'ファイル数', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th><th>' . esc_html__( '元の合計', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th><th>' . esc_html__( '変換後の合計', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th><th>' . esc_html__( '削減', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</th></tr></thead><tbody>';
		foreach ( array( 'webp', 'avif' ) as $f ) {
			$src   = (int) $stats['src_bytes'][ $f ];
			$out   = (int) $stats['out_bytes'][ $f ];
			$ratio = $src > 0 ? round( ( 1 - $out / $src ) * 100 ) : 0;
			echo '<tr><td>' . esc_html( strtoupper( $f ) ) . '</td><td>' . esc_html( (string) $stats['files'][ $f ] ) . '</td><td>' . esc_html( size_format( $src ) ?: '0 B' ) . '</td><td>' . esc_html( size_format( $out ) ?: '0 B' ) . '</td><td>' . esc_html( size_format( max( 0, $src - $out ) ) ?: '0 B' ) . ' (' . esc_html( (string) $ratio ) . '%)</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: image count, 2: converted attachments, 3: failed attachments */
				__( '画像 %1$d 枚中、変換済み %2$d 枚、失敗 %3$d 枚。集計は 10 分ごとに更新されます。', 'wp-plugin-kashiwazaki-next-gen-images' ),
				$stats['images'],
				$stats['attachments'],
				$stats['failed']
			)
		) . ' <a href="' . esc_url( add_query_arg( array( 'page' => KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG, 'tab' => 'status', 'recount' => 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( '今すぐ再集計', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * 配信テストの状態名。
	 *
	 * @param string $state ok|warn|ng|unknown。
	 * @return string
	 */
	private static function mime_state_label( $state ) {
		switch ( $state ) {
			case 'ok':
				return __( '正常（配信できます）', 'wp-plugin-kashiwazaki-next-gen-images' );
			case 'warn':
				return __( '注意（種類の指定が不正ですが、ブラウザが補って表示できる見込みです）', 'wp-plugin-kashiwazaki-next-gen-images' );
			case 'ng':
				return __( '問題あり（この形式の配信を止めています）', 'wp-plugin-kashiwazaki-next-gen-images' );
			default:
				return __( '未確認', 'wp-plugin-kashiwazaki-next-gen-images' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* 配信テスト                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * 状態が未確認のときだけ、設定画面を開いたついでに 1 回テストする。
	 *
	 * @return void
	 */
	private static function maybe_auto_mime_test() {
		$mime = get_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, null );
		if ( is_array( $mime ) && ! empty( $mime['time'] ) ) {
			return;
		}
		if ( ! Kashiwazaki_Next_Gen_Images_Settings::get( 'delivery_enabled' ) ) {
			return;
		}
		self::run_mime_test();
	}

	/**
	 * .webp / .avif の小さなテストファイルを置いて、サーバーが返す Content-Type を確かめる。
	 *
	 * @return array 形式ごとの状態。
	 */
	public static function run_mime_test() {
		$uploads = wp_get_upload_dir();
		$result  = array(
			'webp' => 'unknown',
			'avif' => 'unknown',
			'time' => time(),
		);
		if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
			update_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, $result, true );
			return $result;
		}

		$sub  = 'kashiwazaki-next-gen-images-test-' . wp_generate_password( 8, false );
		$dir  = trailingslashit( $uploads['basedir'] ) . $sub;
		$url  = trailingslashit( $uploads['baseurl'] ) . $sub;
		$made = wp_mkdir_p( $dir );
		if ( ! $made ) {
			$result['webp_note'] = $result['avif_note'] = __( 'テスト用のフォルダを作れませんでした', 'wp-plugin-kashiwazaki-next-gen-images' );
			update_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, $result, true );
			return $result;
		}

		$bodies = array(
			'webp' => 'RIFF' . pack( 'V', 4 + 8 + 10 ) . 'WEBPVP8 ' . pack( 'V', 10 ) . str_repeat( "\0", 10 ),
			'avif' => pack( 'N', 24 ) . 'ftypavif' . pack( 'N', 0 ) . 'avifmif1' ,
		);
		foreach ( $bodies as $ext => $body ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/t.' . $ext, $body );
		}

		$wanted = array(
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);
		foreach ( $wanted as $ext => $type ) {
			$response = wp_remote_head(
				$url . '/t.' . $ext . '?_=' . time(),
				array(
					'timeout'     => 8,
					'redirection' => 3,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				)
			);
			if ( is_wp_error( $response ) ) {
				$result[ $ext ]           = 'unknown';
				$result[ $ext . '_note' ] = $response->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$ctype = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			if ( 200 !== $code ) {
				$result[ $ext ]           = 'unknown';
				$result[ $ext . '_note' ] = 'HTTP ' . $code;
				continue;
			}
			if ( 0 === strpos( $ctype, $type ) ) {
				$result[ $ext ] = 'ok';
			} else {
				$nosniff                  = ( false !== stripos( (string) wp_remote_retrieve_header( $response, 'x-content-type-options' ), 'nosniff' ) );
				$result[ $ext ]           = $nosniff ? 'ng' : 'warn';
				$result[ $ext . '_note' ] = 'Content-Type: ' . ( '' !== $ctype ? $ctype : '(none)' );
			}
		}

		foreach ( array_keys( $bodies ) as $ext ) {
			wp_delete_file( $dir . '/t.' . $ext );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir

		update_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, $result, true );
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * AJAX の共通確認（nonce と権限）。失敗したら即終了する。
	 *
	 * @param string   $capability 必要な権限。
	 * @param int|null $post_id    対象の添付ファイル（あればその編集権限を確認）。
	 * @return void
	 */
	private static function ajax_guard( $capability, $post_id = null ) {
		// 途中で notice 等が出力されても JSON が壊れないよう、出力をいったん溜める。
		ob_start();
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::respond( array( 'message' => 'nonce' ), false, 403 );
		}
		$allowed = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( $capability );
		if ( ! $allowed ) {
			self::respond( array( 'message' => 'forbidden' ), false, 403 );
		}
	}

	/**
	 * JSON を返して終了する。溜まった余計な出力は捨てる。
	 *
	 * @param array $data    返すデータ。
	 * @param bool  $success 成功か。
	 * @param int   $status  HTTP ステータス。
	 * @return void
	 */
	private static function respond( array $data, $success = true, $status = 200 ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( $success ) {
			wp_send_json_success( $data, $status );
		}
		wp_send_json_error( $data, $status );
	}

	/**
	 * 1 枚変換する。
	 *
	 * @return void
	 */
	public static function ajax_convert() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::ajax_guard( 'upload_files', $id ?: -1 );
		if ( ! $id || ! Kashiwazaki_Next_Gen_Images_Converter::is_supported_attachment( $id ) ) {
			self::respond( array( 'message' => 'unsupported' ), false, 400 );
		}

		// 共用サーバーの実行時間制限を延ばせるなら延ばす（禁止されていても処理は時間を区切るので問題ない）。
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$force        = ! empty( $_POST['force'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$retry        = ! empty( $_POST['retry_failed'] ) || $force; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$force_token  = isset( $_POST['force_token'] ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['force_token'] ) ) ), 0, 16 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$res = Kashiwazaki_Next_Gen_Images_Converter::convert_attachment(
			$id,
			array(
				'force'        => $force,
				'retry_failed' => $retry,
				'force_token'  => $force_token,
				'deadline'     => Kashiwazaki_Next_Gen_Images_Engine::deadline(),
			)
		);

		self::respond(
			array(
				'id'           => $id,
				'title'        => wp_strip_all_tags( get_the_title( $id ) ),
				'state'        => $res['state'],
				'converted'    => (int) $res['converted'],
				'saved'        => (int) $res['saved'],
				'errors'       => array_slice( array_map( 'strval', (array) $res['errors'] ), 0, 5 ),
				'force_token'  => isset( $res['force_token'] ) ? (string) $res['force_token'] : '',
				'message'      => (string) $res['message'],
				'status_html'  => self::status_html( $id ),
			)
		);
	}

	/**
	 * 一括処理（変換・削除）を始める。停止中の同じ処理があれば、続きから再開する。
	 *
	 * @return void
	 */
	public static function ajax_job_start() {
		self::ajax_guard( 'manage_options' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$kind = ( isset( $_POST['kind'] ) && 'remove' === sanitize_key( wp_unslash( $_POST['kind'] ) ) ) ? 'remove' : 'convert';
		$job  = Kashiwazaki_Next_Gen_Images_Job::start(
			$kind,
			array(
				'force'   => ! empty( $_POST['force'] ),
				'retry'   => ! empty( $_POST['retry_failed'] ),
				'restart' => ! empty( $_POST['restart'] ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		self::respond(
			array(
				'job'  => Kashiwazaki_Next_Gen_Images_Job::summary( $job ),
				'busy' => false,
			)
		);
	}

	/**
	 * 一括処理を少し進める。画面から繰り返し呼ばれる。
	 *
	 * @return void
	 */
	public static function ajax_job_tick() {
		self::ajax_guard( 'manage_options' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		self::respond( Kashiwazaki_Next_Gen_Images_Job::tick( 10.0 ) );
	}

	/**
	 * 一括処理の今の状態を返す（何も進めない。画面が数字を更新するために、短い間隔で呼ぶ）。
	 *
	 * @return void
	 */
	public static function ajax_job_status() {
		self::ajax_guard( 'manage_options' );
		self::respond(
			array(
				'job'  => Kashiwazaki_Next_Gen_Images_Job::summary( Kashiwazaki_Next_Gen_Images_Job::get() ),
				'busy' => false,
			)
		);
	}

	/**
	 * 一括処理を止める（続きから再開できる）。
	 *
	 * @return void
	 */
	public static function ajax_job_stop() {
		self::ajax_guard( 'manage_options' );
		$job = Kashiwazaki_Next_Gen_Images_Job::stop();
		self::respond(
			array(
				'job'  => Kashiwazaki_Next_Gen_Images_Job::summary( $job ),
				'busy' => false,
			)
		);
	}

	/**
	 * 一括処理の途中経過を、管理画面のどのページにも出す。
	 *
	 * @return void
	 */
	public static function render_job_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// 設定画面の中では、その画面自身が進捗を出す。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$job = Kashiwazaki_Next_Gen_Images_Job::summary( Kashiwazaki_Next_Gen_Images_Job::get() );
		if ( ! $job || 'running' !== $job['state'] ) {
			return;
		}
		$url = add_query_arg(
			array(
				'page' => KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG,
				'tab'  => 'bulk',
			),
			admin_url( 'admin.php' )
		);
		echo '<div class="notice notice-info kashiwazaki-next-gen-images-job-notice" id="kashiwazaki-next-gen-images-job-notice"><p><strong>' . esc_html( self::MENU_TITLE ) . '</strong></p>';
		echo '<div class="kashiwazaki-next-gen-images-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $job['percent'] ) . '"><div class="kashiwazaki-next-gen-images-progress-bar" style="width:' . esc_attr( (string) $job['percent'] ) . '%"></div></div>';
		echo '<p class="kashiwazaki-next-gen-images-status is-running">' . esc_html( $job['percent'] . '% — ' . $job['text'] ) . '</p>';
		echo '<p class="kashiwazaki-next-gen-images-meta" aria-hidden="true"></p>';
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( '詳しく見る', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</a></p></div>';
	}

	/**
	 * 配信テストを実行する。
	 *
	 * @return void
	 */
	public static function ajax_mime_test() {
		self::ajax_guard( 'manage_options' );
		$res = self::run_mime_test();
		$out = array();
		foreach ( array( 'webp', 'avif' ) as $f ) {
			$out[ $f ] = self::mime_state_label( $res[ $f ] ) . ( ! empty( $res[ $f . '_note' ] ) ? ' — ' . $res[ $f . '_note' ] : '' );
		}
		self::respond( $out );
	}

	/**
	 * 変換エンジンの動作確認をやり直す。
	 *
	 * @return void
	 */
	public static function ajax_engine_test() {
		self::ajax_guard( 'manage_options' );
		Kashiwazaki_Next_Gen_Images_Engine::support( true );
		self::respond( array( 'reload' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* メディアライブラリ                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * メディア一覧に列を足す。
	 *
	 * @param array $columns 列。
	 * @return array
	 */
	public static function add_media_column( $columns ) {
		$columns['kashiwazaki_next_gen_images'] = self::MENU_TITLE;
		return $columns;
	}

	/**
	 * メディア一覧の列の中身。
	 *
	 * @param string $column 列名。
	 * @param int    $id     添付ファイル ID。
	 * @return void
	 */
	public static function render_media_column( $column, $id ) {
		if ( 'kashiwazaki_next_gen_images' !== $column ) {
			return;
		}
		echo '<div class="kashiwazaki-next-gen-images-cell" data-id="' . esc_attr( (string) $id ) . '">';
		echo self::status_html( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各部分は status_html() 内でエスケープ済み。
		echo '</div>';
	}

	/**
	 * 添付ファイルの編集画面・メディアモーダルに項目を足す。
	 *
	 * @param array   $fields フィールド。
	 * @param WP_Post $post   添付ファイル。
	 * @return array
	 */
	public static function attachment_fields( $fields, $post ) {
		if ( ! $post || ! Kashiwazaki_Next_Gen_Images_Converter::is_supported_attachment( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$fields['kashiwazaki_next_gen_images'] = array(
			'label' => self::MENU_TITLE,
			'input' => 'html',
			'html'  => '<div class="kashiwazaki-next-gen-images-cell" data-id="' . esc_attr( (string) $post->ID ) . '">' . self::status_html( $post->ID ) . '</div>',
		);
		return $fields;
	}

	/**
	 * 「変換しなかった理由」の表示名。
	 *
	 * @param string $code 理由コード（空なら、そもそも対象の形式が無い場合）。
	 * @return string
	 */
	private static function reason_label( $code ) {
		$labels = array(
			'larger'         => __( '変換なし（軽くならないため）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'animated'       => __( '変換なし（アニメーション画像）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'too_large'      => __( '変換なし（画素数が上限超過）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'memory'         => __( '変換できず（メモリ不足）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'icc'            => __( '変換なし（sRGB 以外の色設定）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'cmyk'           => __( '変換なし（CMYK 画像）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'lossless_avif'  => __( '変換なし（劣化なしの設定のため。AVIF は劣化なしで作れません）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'lossless_unavailable' => __( '変換なし（劣化なしの設定ですが、このサーバーは劣化なしの WebP を書き出せません）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'mime_mismatch'  => __( '変換なし（拡張子と中身が不一致）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'corrupt'        => __( '変換なし（画像として読めません）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'decode'         => __( '変換できず（読み込み失敗）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'unsupported'    => __( '変換なし（このサーバーが未対応）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'missing'        => __( '変換できず（元ファイルが無い）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'invalid_output' => __( '変換できず（出力の検証に失敗）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'engine_error'   => __( '変換できず（エンジンのエラー）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'write_failed'   => __( '変換できず（書き込み失敗）', 'wp-plugin-kashiwazaki-next-gen-images' ),
			'conflict'       => __( '変換なし（同名の別の画像があるため）', 'wp-plugin-kashiwazaki-next-gen-images' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		return __( '変換なし（作る形式がありません）', 'wp-plugin-kashiwazaki-next-gen-images' );
	}

	/**
	 * 1 枚分の状態表示（HTML）。ボタン付き。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return string
	 */
	public static function status_html( $id ) {
		if ( ! Kashiwazaki_Next_Gen_Images_Converter::is_supported_attachment( $id ) ) {
			return '<span class="description">' . esc_html__( '対象外の形式', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';
		}
		$meta  = Kashiwazaki_Next_Gen_Images_Converter::get_meta( $id );
		$html  = '';
		$saved = 0;
		$have  = array();
		if ( ! empty( $meta['files'] ) ) {
			foreach ( $meta['files'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				foreach ( array( 'webp', 'avif' ) as $f ) {
					if ( isset( $entry[ $f ]['size'] ) ) {
						$have[ $f ] = true;
						$saved     += max( 0, (int) $entry['src_size'] - (int) $entry[ $f ]['size'] );
					}
				}
			}
		}
		if ( empty( $have ) ) {
			$label = '<span class="kashiwazaki-next-gen-images-badge">' . esc_html__( '未変換', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';
			if ( ! empty( $meta ) ) {
				$counts = array();
				foreach ( $meta['files'] as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['skip'] ) ) {
						foreach ( $entry['skip'] as $code ) {
							$counts[ $code ] = isset( $counts[ $code ] ) ? $counts[ $code ] + 1 : 1;
						}
					}
				}
				arsort( $counts );
				$top   = $counts ? (string) key( $counts ) : '';
				$class = ( ! empty( $meta['state'] ) && 'failed' === $meta['state'] ) ? ' kashiwazaki-next-gen-images-badge-ng' : '';
				$label = '<span class="kashiwazaki-next-gen-images-badge' . $class . '">' . esc_html( self::reason_label( $top ) ) . '</span>';
			}
			$html .= $label;
		} else {
			foreach ( array( 'avif', 'webp' ) as $f ) {
				if ( isset( $have[ $f ] ) ) {
					$html .= '<span class="kashiwazaki-next-gen-images-badge kashiwazaki-next-gen-images-badge-ok">' . esc_html( strtoupper( $f ) ) . '</span> ';
				}
			}
			$html .= '<br><span class="description">' . esc_html( sprintf( /* translators: %s: size */ __( '合計 %s 削減', 'wp-plugin-kashiwazaki-next-gen-images' ), size_format( $saved ) ) ) . '</span>';
		}
		if ( ! empty( $meta['state'] ) && 'partial' === $meta['state'] ) {
			$html .= '<br><span class="description">' . esc_html__( '一部のサイズが未処理', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</span>';
		}
		$html .= '<br><button type="button" class="button button-small kashiwazaki-next-gen-images-convert-button" data-id="' . esc_attr( (string) $id ) . '" data-force="' . ( empty( $have ) ? '0' : '1' ) . '">' . esc_html( empty( $have ) ? __( '今すぐ変換', 'wp-plugin-kashiwazaki-next-gen-images' ) : __( '再変換', 'wp-plugin-kashiwazaki-next-gen-images' ) ) . '</button>';
		return $html;
	}
}
