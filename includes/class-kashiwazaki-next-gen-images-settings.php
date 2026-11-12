<?php
/**
 * Kashiwazaki Next-Gen Images の設定の保存・取得・検証。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定を扱うクラス。
 */
final class Kashiwazaki_Next_Gen_Images_Settings {

	const OPTION       = 'kashiwazaki_next_gen_images_settings';
	const GROUP        = 'kashiwazaki_next_gen_images_group';
	const MIME_OPTION  = 'kashiwazaki_next_gen_images_mime_status';
	const FORMATS      = array( 'avif', 'webp' ); // 配信の優先順（左ほど優先）。

	/**
	 * 取得済み設定のキャッシュ。
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * 設定の初期値。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enable_webp'         => 1,
			'enable_avif'         => 1,
			'webp_quality'        => 82,
			'avif_quality'        => 60,
			'png_lossless'        => 1,
			'only_if_smaller'     => 1,
			'engine_preference'   => 'auto', // auto|gd|imagick|cavif。
			'cavif_path'          => '', // cavif（外部の AVIF 変換プログラム）の場所。空なら使わない。
			'max_megapixels'      => 30,
			'auto_convert'        => 1,
			'convert_timing'      => 'immediate', // immediate|background。
			'exclude_sizes'       => array(),
			'delivery_enabled'    => 1,
			'delivery_scope'      => 'content', // content|page。
			'delivery_css'        => 1,
			'repair_wrappers'     => 1,
			'exclude_classes'     => "no-nextgen\nskip-lazy",
			'exclude_urls'        => '',
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * 現在の設定（初期値で補完済み）を返す。
	 *
	 * @param string|null $key 指定すればその値だけ返す。
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			self::$cache = array_merge( self::defaults(), $saved );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * キャッシュを捨てる。
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
		// 設定（形式・劣化なし・サイズ）が変わると「処理が必要」の数も変わるので、集計の一時保存を捨てる。
		if ( class_exists( 'Kashiwazaki_Next_Gen_Images_Converter' ) ) {
			delete_transient( Kashiwazaki_Next_Gen_Images_Converter::STATS_KEY );
		}
	}

	/**
	 * 設定を WordPress に登録する。
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * 変換の対象にする形式（有効化されているもの）。
	 *
	 * @return string[]
	 */
	public static function enabled_formats() {
		$formats = array();
		if ( self::get( 'enable_avif' ) ) {
			$formats[] = 'avif';
		}
		if ( self::get( 'enable_webp' ) ) {
			$formats[] = 'webp';
		}
		return $formats;
	}

	/**
	 * 登録されている画像サイズ名（フルサイズを含む）。
	 *
	 * @return string[]
	 */
	public static function known_sizes() {
		$sizes = array( 'full' );
		if ( function_exists( 'get_intermediate_image_sizes' ) ) {
			$sizes = array_merge( $sizes, get_intermediate_image_sizes() );
		}
		return array_values( array_unique( $sizes ) );
	}

	/**
	 * 保存前の検証。フォームから来る値は信用せず、すべて範囲内に収める。
	 *
	 * @param mixed $input 送信された値。
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			return self::get();
		}

		$out = array();

		foreach ( array( 'enable_webp', 'enable_avif', 'png_lossless', 'only_if_smaller', 'auto_convert', 'delivery_enabled', 'delivery_css', 'repair_wrappers', 'delete_on_uninstall' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['webp_quality'] = self::clamp_int( isset( $input['webp_quality'] ) ? $input['webp_quality'] : null, 1, 100, $defaults['webp_quality'] );
		$out['avif_quality'] = self::clamp_int( isset( $input['avif_quality'] ) ? $input['avif_quality'] : null, 1, 100, $defaults['avif_quality'] );
		$out['max_megapixels'] = self::clamp_int( isset( $input['max_megapixels'] ) ? $input['max_megapixels'] : null, 1, 200, $defaults['max_megapixels'] );

		$out['engine_preference'] = self::pick( isset( $input['engine_preference'] ) ? $input['engine_preference'] : '', array( 'auto', 'gd', 'imagick', 'cavif' ), $defaults['engine_preference'] );

		// cavif（外部の AVIF 変換プログラム）の場所。空欄なら使わない。
		// 変えられるのは、プラグインをインストールできる人だけ（can_set_cavif_path）。
		// 欄が送られてこないとき（wp-config.php で指定していて入力できないとき）・変える権限が無いときは、保存済みの値を保つ。
		$saved_path        = self::get( 'cavif_path' );
		$saved_path        = is_string( $saved_path ) ? $saved_path : '';
		$out['cavif_path'] = ( isset( $input['cavif_path'] ) && self::can_set_cavif_path() ) ? self::sanitize_path( $input['cavif_path'], $saved_path ) : $saved_path;

		$out['convert_timing']    = self::pick( isset( $input['convert_timing'] ) ? $input['convert_timing'] : '', array( 'immediate', 'background' ), $defaults['convert_timing'] );
		$out['delivery_scope']    = self::pick( isset( $input['delivery_scope'] ) ? $input['delivery_scope'] : '', array( 'content', 'page' ), $defaults['delivery_scope'] );

		$known_sizes = self::known_sizes();
		$excluded    = array();
		if ( isset( $input['exclude_sizes'] ) && is_array( $input['exclude_sizes'] ) ) {
			foreach ( $input['exclude_sizes'] as $size ) {
				$size = is_string( $size ) ? $size : '';
				if ( in_array( $size, $known_sizes, true ) ) {
					$excluded[] = $size;
				}
			}
		}
		$out['exclude_sizes'] = array_values( array_unique( $excluded ) );

		$out['exclude_classes'] = self::sanitize_lines( isset( $input['exclude_classes'] ) ? $input['exclude_classes'] : '', true );
		$out['exclude_urls']    = self::sanitize_lines( isset( $input['exclude_urls'] ) ? $input['exclude_urls'] : '', false );

		// 「PNG・GIF は劣化なしで変換」を切り替えたときは、すでにある画像に反映するには一括変換が要ることを知らせる。
		if ( function_exists( 'add_settings_error' ) && (int) $out['png_lossless'] !== (int) self::get( 'png_lossless' ) ) {
			$bulk_url = add_query_arg(
				array(
					'page' => KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG,
					'tab'  => 'bulk',
				),
				admin_url( 'admin.php' )
			);
			add_settings_error(
				self::OPTION,
				'kashiwazaki_next_gen_images_png_lossless_changed',
				sprintf(
					/* translators: %s: URL of the bulk conversion tab */
					__( '「PNG・GIF は劣化なしで変換」を切り替えました。すでにある画像に反映するには、<a href="%s">「一括変換」タブ</a>で「一括変換を開始」を押してください（「すべて作り直す」は不要です）。', 'wp-plugin-kashiwazaki-next-gen-images' ),
					esc_url( $bulk_url )
				),
				'info'
			);
		}

		return $out;
	}

	/**
	 * いまの利用者が、設定画面で cavif（外部の AVIF 変換プログラム）の場所を変えられるか。
	 *
	 * 変えられるのは、プラグインをインストールできる人だけ。プラグインをインストールできる人は、
	 * もともとサーバーでプログラムを動かせるので、この欄で新しくできることは増えない。
	 * マルチサイトの各サイトの管理者や、ファイルの変更を禁止しているサイト（DISALLOW_FILE_MODS）の管理者は変えられない
	 * （その場合は wp-config.php で指定する）。
	 *
	 * @return bool
	 */
	public static function can_set_cavif_path() {
		return current_user_can( 'install_plugins' );
	}

	/**
	 * 設定画面で入力されたファイルの場所を確かめる。
	 *
	 * 中身は書き換えない（書き換えると、別の場所を指すことになるため）。受け付けられない値のときは、保存済みの値のままにする。
	 * 実際に使えるかどうか（絶対パスか・ファイルがあるか・実行できるか・権限は安全か）は、使うときに Engine::cavif_status() が確かめる。
	 *
	 * @param mixed  $value    入力。
	 * @param string $fallback 受け付けられないときに使う値（保存済みの値）。
	 * @return string
	 */
	private static function sanitize_path( $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = trim( $value );
		// 制御文字（改行・NUL など）を含むもの・長すぎるものは受け付けない。
		if ( strlen( $value ) > 1024 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return $fallback;
		}
		return $value;
	}

	/**
	 * 整数を範囲内に収める。
	 *
	 * @param mixed $value   値。
	 * @param int   $min     下限。
	 * @param int   $max     上限。
	 * @param int   $default 不正なときの値。
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $default ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * 許可した値の中から選ぶ。
	 *
	 * @param mixed    $value   値。
	 * @param string[] $allowed 許可する値。
	 * @param string   $default 不正なときの値。
	 * @return string
	 */
	private static function pick( $value, array $allowed, $default ) {
		return ( is_string( $value ) && in_array( $value, $allowed, true ) ) ? $value : $default;
	}

	/**
	 * 1 行 1 項目のテキストを整える。
	 *
	 * @param mixed $value      入力。
	 * @param bool  $css_tokens true なら CSS クラス名として使える文字だけにする。
	 * @return string
	 */
	private static function sanitize_lines( $value, $css_tokens ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$clean = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( $css_tokens ) {
				$line = preg_replace( '/[^A-Za-z0-9_\-]/', '', $line );
				if ( '' === $line ) {
					continue;
				}
			}
			$clean[] = $line;
			if ( count( $clean ) >= 100 ) {
				break;
			}
		}
		return implode( "\n", array_unique( $clean ) );
	}

	/**
	 * 設定画面の複数行テキストを配列にする。
	 *
	 * @param string $key 設定キー。
	 * @return string[]
	 */
	public static function lines( $key ) {
		$value = self::get( $key );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ), 'strlen' ) );
	}
}
