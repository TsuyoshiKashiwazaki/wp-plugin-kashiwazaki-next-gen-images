<?php
/**
 * 画像を実際に WebP / AVIF へ変換する部分（GD と Imagick の両対応）。
 *
 * - 元画像は読み取るだけで、書き換えない。
 * - 出力は一時ファイルに書き、検証に通ったものだけ呼び出し側が本来の名前へ移す。
 * - 外部コマンド（exec 等）は使わない。レンタルサーバーで禁止されていても動く。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 変換エンジン。
 */
final class Kashiwazaki_Next_Gen_Images_Engine {

	const TEST_TRANSIENT = 'kashiwazaki_next_gen_images_engine_test_';

	/**
	 * 変換元として扱う MIME タイプ。
	 */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * 動作確認用の小さな PNG（32x32）。
	 */
	const SELF_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGYktHRAD/AP8A/6C9p5MAAAAHdElNRQfqChwEDAH3z7WEAAAAQ0lEQVRIx2P8z0BbwPKdgYO2FvwYtYCQBd8ZOIe4D4a+BaNxMAIsGI3kgbdgNA5GgAWjkTzwFozGwYBbwMjAQNsGPADmjCB4fRErqgAAAABJRU5ErkJggg==';

	/**
	 * 作成中の一時ファイル（異常終了時に消すため）。
	 *
	 * @var string[]
	 */
	private static $tmp_files = array();

	/**
	 * 終了時の掃除を登録済みか。
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * 動作確認の結果を保存するキー。
	 *
	 * コマンドライン（WP-CLI・cron）と Web では、同じサーバーでも PHP の拡張が違うことがよくある
	 * （例: コマンドラインだけ Imagick が有効）。結果を共有すると誤った判断になるため、
	 * 実行環境の特徴ごとに別々に保存する。
	 *
	 * @return string
	 */
	private static function test_key() {
		$gd  = function_exists( 'gd_info' ) ? wp_json_encode( gd_info() ) : '';
		$sig = PHP_VERSION . '|' . ( 'cli' === PHP_SAPI ? 'cli' : 'web' ) . '|' . phpversion( 'imagick' ) . '|' . phpversion( 'gd' ) . '|' . $gd;
		if ( class_exists( 'Imagick' ) ) {
			try {
				$version = Imagick::getVersion();
				$sig    .= '|' . ( isset( $version['versionNumber'] ) ? $version['versionNumber'] : '' );
			} catch ( Throwable $e ) {
				$sig .= '|imagick-error';
			}
		}
		return self::TEST_TRANSIENT . substr( md5( $sig ), 0, 12 );
	}

	/**
	 * 実行時のサポート状況のキャッシュ。
	 *
	 * @var array|null
	 */
	private static $support = null;

	/**
	 * 1 回の処理に使ってよい秒数。レンタルサーバーの実行時間制限（既定 30 秒程度）に収まるよう控えめにする。
	 *
	 * @return float
	 */
	public static function time_budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = ( $max > 0 ) ? min( 20.0, $max * 0.5 ) : 20.0;
		$budget = max( 5.0, $budget );
		return (float) apply_filters( 'kashiwazaki_next_gen_images_time_budget', $budget );
	}

	/**
	 * 処理の締め切り（microtime）。
	 *
	 * 「今から数秒」だけでなく、リクエストの開始時刻からの経過も見る。アップロードでは、このプラグインの
	 * 前に WordPress が画像のサイズ違いを作るのに時間を使っているため、今からの数秒だけで決めると、
	 * サーバーの実行時間制限（max_execution_time）を越えてしまうことがある。
	 *
	 * @return float
	 */
	public static function deadline() {
		$now      = microtime( true );
		$deadline = $now + self::time_budget();
		$max      = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 && 'cli' !== PHP_SAPI && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $max * 0.6 );
		}
		return $deadline;
	}

	/**
	 * 形式ごとに使えるエンジンを、優先順に返す。
	 *
	 * 「ライブラリが入っている」だけでなく、実際に小さな画像を書き出せたものだけを数える
	 * （AVIF は読み込みだけできて書き出せない環境があるため）。
	 *
	 * @param bool $refresh true なら動作確認をやり直す。
	 * @return array array( 'webp' => string[], 'avif' => string[] )
	 */
	public static function support( $refresh = false ) {
		if ( ! $refresh && null !== self::$support ) {
			return self::$support;
		}

		$tests = $refresh ? false : get_transient( self::test_key() );
		if ( ! is_array( $tests ) ) {
			$tests = self::run_self_tests();
			set_transient( self::test_key(), $tests, 12 * HOUR_IN_SECONDS );
		}

		$support = array(
			'webp' => array(),
			'avif' => array(),
		);
		foreach ( array( 'webp', 'avif' ) as $format ) {
			foreach ( array( 'imagick', 'gd' ) as $engine ) {
				if ( ! empty( $tests[ $engine ][ $format ] ) ) {
					$support[ $format ][] = $engine;
				}
			}
		}
		self::$support = $support;
		return $support;
	}

	/**
	 * 動作確認の結果（診断画面用）。
	 *
	 * @return array
	 */
	public static function test_results() {
		$tests = get_transient( self::test_key() );
		if ( ! is_array( $tests ) ) {
			self::support( true );
			$tests = get_transient( self::test_key() );
		}
		return is_array( $tests ) ? $tests : array();
	}

	/**
	 * 使えるエンジンの一覧（設定の優先指定を反映）。
	 *
	 * @param string $format webp|avif。
	 * @return string[]
	 */
	public static function engines_for( $format ) {
		$support = self::support();
		$engines = isset( $support[ $format ] ) ? $support[ $format ] : array();
		$pref    = Kashiwazaki_Next_Gen_Images_Settings::get( 'engine_preference' );
		if ( in_array( $pref, array( 'gd', 'imagick' ), true ) && in_array( $pref, $engines, true ) ) {
			// 優先指定したエンジンを先頭にする。もう片方は失敗したときの予備として残す。
			$engines = array_merge( array( $pref ), array_diff( $engines, array( $pref ) ) );
		}
		return (array) apply_filters( 'kashiwazaki_next_gen_images_engines', array_values( $engines ), $format );
	}

	/**
	 * 1 つ以上のエンジンで書き出せる形式。
	 *
	 * @return string[]
	 */
	public static function available_formats() {
		$support = self::support();
		$formats = array();
		foreach ( array( 'avif', 'webp' ) as $format ) {
			if ( ! empty( $support[ $format ] ) ) {
				$formats[] = $format;
			}
		}
		return $formats;
	}

	/**
	 * ライブラリの情報（診断画面用）。
	 *
	 * @return array
	 */
	public static function library_info() {
		$info = array(
			'imagick_loaded'  => extension_loaded( 'imagick' ) && class_exists( 'Imagick' ),
			'imagick_version' => '',
			'imagick_webp'    => false,
			'imagick_avif'    => false,
			'gd_loaded'       => extension_loaded( 'gd' ) && function_exists( 'gd_info' ),
			'gd_version'      => '',
			'gd_webp'         => false,
			'gd_avif'         => false,
		);
		if ( $info['imagick_loaded'] ) {
			try {
				$formats                 = Imagick::queryFormats();
				$info['imagick_webp']    = in_array( 'WEBP', $formats, true );
				$info['imagick_avif']    = in_array( 'AVIF', $formats, true );
				$version                 = Imagick::getVersion();
				$info['imagick_version'] = isset( $version['versionString'] ) ? $version['versionString'] : '';
			} catch ( Throwable $e ) {
				$info['imagick_loaded'] = false;
			}
		}
		if ( $info['gd_loaded'] ) {
			$gd                 = gd_info();
			$info['gd_version'] = isset( $gd['GD Version'] ) ? $gd['GD Version'] : '';
			$info['gd_webp']    = ! empty( $gd['WebP Support'] ) && function_exists( 'imagewebp' );
			$info['gd_avif']    = ! empty( $gd['AVIF Support'] ) && function_exists( 'imageavif' );
		}
		return $info;
	}

	/**
	 * 小さな画像を実際に書き出して、各エンジン・各形式が使えるか調べる。
	 *
	 * @return array
	 */
	private static function run_self_tests() {
		$results = array(
			'imagick' => array(
				'webp' => false,
				'avif' => false,
			),
			'gd'      => array(
				'webp' => false,
				'avif' => false,
			),
		);

		$dir = get_temp_dir();
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			$uploads = wp_get_upload_dir();
			$dir     = isset( $uploads['basedir'] ) ? $uploads['basedir'] : $dir;
		}
		$dir = trailingslashit( $dir );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return $results;
		}

		$src = $dir . 'kashiwazaki-next-gen-images-selftest-' . wp_generate_password( 8, false ) . '.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $src, base64_decode( self::SELF_TEST_PNG ) ) ) {
			return $results;
		}

		foreach ( array( 'imagick', 'gd' ) as $engine ) {
			foreach ( array( 'webp', 'avif' ) as $format ) {
				$dest = $src . '.' . $format . '.tmp';
				self::register_tmp( $dest );
				try {
					$size = getimagesize( $src );
					$res  = ( 'imagick' === $engine )
						? self::encode_imagick( $src, 'image/png', $dest, $format, array( 'quality' => 60, 'lossless' => false ) )
						: self::encode_gd( $src, 'image/png', $dest, $format, array( 'quality' => 60, 'lossless' => false ), $size );
					if ( ! is_wp_error( $res ) && self::verify_output( $dest, $format, 32, 32 ) ) {
						$results[ $engine ][ $format ] = true;
					}
				} catch ( Throwable $e ) {
					$results[ $engine ][ $format ] = false;
				}
				self::remove_tmp( $dest );
			}
		}
		wp_delete_file( $src );
		return $results;
	}

	/**
	 * 画像を 1 枚変換する。
	 *
	 * @param string $src    変換元ファイルの絶対パス。
	 * @param string $mime   変換元の MIME タイプ（実際の中身と一致している必要がある）。
	 * @param string $dest   書き出し先（一時ファイル）。
	 * @param string $format webp|avif。
	 * @param array  $opts   quality(int), lossless(bool)。
	 * @return array|WP_Error 成功時は engine / width / height。
	 */
	public static function convert( $src, $mime, $dest, $format, array $opts ) {
		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			return new WP_Error( 'unsupported', 'unsupported format' );
		}

		$guard = self::guard_source( $src, $mime );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$size = $guard;

		$engines = self::engines_for( $format );
		if ( empty( $engines ) ) {
			return new WP_Error( 'unsupported', 'no engine for ' . $format );
		}

		self::register_tmp( $dest );
		$last_error  = new WP_Error( 'engine_error', 'no engine tried' );
		$first_error = null; // 最初に失敗したエンジンのエラー。

		foreach ( $engines as $engine ) {
			self::remove_tmp( $dest );
			self::register_tmp( $dest ); // remove_tmp は登録も外す。エンコード中に時間切れで終わっても、終了時の掃除で消せるよう、登録し直す。
			try {
				if ( 'imagick' === $engine ) {
					$result = self::encode_imagick( $src, $mime, $dest, $format, $opts );
				} else {
					$result = self::encode_gd( $src, $mime, $dest, $format, $opts, $size );
				}
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'engine_error', $engine . ': ' . $e->getMessage() );
			}

			if ( is_wp_error( $result ) ) {
				if ( null === $first_error ) {
					$first_error = $result;
				}
				$last_error = $result;
				continue;
			}

			// 向きの補正で縦横が入れ替わることはあるが、画素数は変わらないはず。
			$ok_dims = ( $result['width'] === $size[0] && $result['height'] === $size[1] ) || ( $result['width'] === $size[1] && $result['height'] === $size[0] );
			if ( ! $ok_dims || ! self::verify_output( $dest, $format, $result['width'], $result['height'] ) ) {
				$last_error = new WP_Error( 'invalid_output', $engine . ': output verification failed' );
				if ( null === $first_error ) {
					$first_error = $last_error;
				}
				continue;
			}

			$result['engine'] = $engine;
			return $result;
		}

		self::remove_tmp( $dest );
		// 最後のエンジンが「色を保てない」(icc)で見送り、先に試したエンジンが一時的な失敗(メモリ・資源の制限など)だったときは、先の失敗を返す。
		// icc は『変換しない』として記録され、古い有効な変換ファイルが片付けられる。一時的な失敗のせいで、有効な変換ファイルを失わないようにする。
		if ( $last_error instanceof WP_Error && 'icc' === $last_error->get_error_code() && $first_error instanceof WP_Error && in_array( $first_error->get_error_code(), array( 'engine_error', 'memory', 'invalid_output', 'decode' ), true ) ) {
			return $first_error;
		}
		return $last_error;
	}

	/**
	 * CMYK の JPEG か（getimagesize の channels が 4）。CMYK は色がずれるので変換しない。
	 *
	 * @param string $path パス。
	 * @return bool
	 */
	public static function is_cmyk_jpeg( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $info ) && isset( $info['channels'], $info['mime'] ) && 'image/jpeg' === $info['mime'] && 4 === (int) $info['channels'];
	}

	/**
	 * 変換前の安全確認。ファイルの拡張子ではなく、中身で種類と大きさを確かめる。
	 *
	 * @param string $src  パス。
	 * @param string $mime 期待する MIME。
	 * @return array|WP_Error getimagesize の結果。
	 */
	private static function guard_source( $src, $mime ) {
		if ( ! is_file( $src ) || ! is_readable( $src ) ) {
			return new WP_Error( 'missing', 'source not readable' );
		}
		if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
			return new WP_Error( 'unsupported', 'source mime not supported' );
		}
		$size = @getimagesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return new WP_Error( 'corrupt', 'not a readable image' );
		}
		if ( empty( $size['mime'] ) || $size['mime'] !== $mime ) {
			// 拡張子と中身が食い違うファイル（偽装・誤保存）は処理しない。
			return new WP_Error( 'mime_mismatch', 'content type differs from declared type' );
		}

		$max_pixels = (int) Kashiwazaki_Next_Gen_Images_Settings::get( 'max_megapixels' ) * 1000000;
		if ( (int) $size[0] * (int) $size[1] > $max_pixels ) {
			return new WP_Error( 'too_large', 'image exceeds pixel limit' );
		}

		if ( 'image/gif' === $mime && self::is_animated_gif( $src ) ) {
			return new WP_Error( 'animated', 'animated gif' );
		}
		if ( 'image/png' === $mime && self::is_animated_png( $src ) ) {
			return new WP_Error( 'animated', 'animated png' );
		}
		if ( 'image/webp' === $mime && self::is_animated_webp( $src ) ) {
			return new WP_Error( 'animated', 'animated webp' );
		}
		return $size;
	}

	/**
	 * アニメーション GIF か（2 コマ以上）。
	 *
	 * GIF の構造（ヘッダー → 拡張ブロック・画像ブロックの連なり）を順にたどって、画像ブロックを数える。
	 * 拡張ブロックの有無（NETSCAPE 等）には頼らない。構造が読み取れないときは「対象外」に倒す。
	 *
	 * @param string $path パス。
	 * @return bool
	 */
	private static function is_animated_gif( $path ) {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size > 30 * MB_IN_BYTES ) {
			return true;
		}
		// ファイル全体を文字列に読み込むので、残りのメモリに収まらないときは読まない（致命的エラーを避け、「対象外」に倒す）。
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit > 0 && ( memory_get_usage( true ) + (int) ( $size * 1.2 ) + 2 * MB_IN_BYTES ) > $limit ) {
			return true;
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $data ) || strlen( $data ) < 14 || 'GIF' !== substr( $data, 0, 3 ) ) {
			return true;
		}
		$len   = strlen( $data );
		$flags = ord( $data[10] );
		$pos   = 13;
		if ( $flags & 0x80 ) {
			$pos += 3 * ( 1 << ( ( $flags & 0x07 ) + 1 ) ); // グローバルカラーテーブル。
		}
		$frames = 0;
		while ( $pos < $len ) {
			$byte = ord( $data[ $pos ] );
			if ( 0x3B === $byte ) { // 終端。
				break;
			}
			if ( 0x21 === $byte ) { // 拡張ブロック: 導入子 + ラベル + サブブロックの連なり。
				$pos += 2;
				while ( $pos < $len ) {
					$n    = ord( $data[ $pos ] );
					$pos += 1 + $n;
					if ( 0 === $n ) {
						break;
					}
				}
				continue;
			}
			if ( 0x2C === $byte ) { // 画像ブロック。
				if ( ++$frames > 1 || $pos + 10 > $len ) {
					return true;
				}
				$local = ord( $data[ $pos + 9 ] );
				$pos  += 10;
				if ( $local & 0x80 ) {
					$pos += 3 * ( 1 << ( ( $local & 0x07 ) + 1 ) ); // ローカルカラーテーブル。
				}
				$pos += 1; // LZW の最小コードサイズ。
				while ( $pos < $len ) {
					$n    = ord( $data[ $pos ] );
					$pos += 1 + $n;
					if ( 0 === $n ) {
						break;
					}
				}
				continue;
			}
			return true; // 想定外のバイト。判断できないので対象外にする。
		}
		return false;
	}

	/**
	 * アニメーション PNG（APNG）か。acTL チャンクは最初の IDAT より前に置かれる。
	 *
	 * チャンクを「ファイル上の位置」で順にたどる。回数にも上限を置き、細工されたファイルで止まらなくならないようにする。
	 *
	 * @param string $path パス。
	 * @return bool
	 */
	private static function is_animated_png( $path ) {
		$total = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fh    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh || false === $total ) {
			return true; // 判断できないときは変換しない側に倒す。
		}
		$offset   = 8;
		$anim     = false;
		$resolved = false; // IDAT・IEND・ファイルの終わりのどれかに着いて、アニメかどうかを判断できたか。
		for ( $i = 0; $i < 4096; $i++ ) {
			if ( $offset + 8 > $total || 0 !== fseek( $fh, $offset ) ) {
				$resolved = true; // 最初の IDAT より前でファイルが終わっている。壊れた PNG なので、後の読み込みが失敗として扱う。
				break;
			}
			$header = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( ! is_string( $header ) || strlen( $header ) < 8 ) {
				$resolved = true; // 読み切れない（壊れた PNG）。
				break;
			}
			$chunk_len = unpack( 'N', substr( $header, 0, 4 ) );
			$type      = substr( $header, 4, 4 );
			if ( 'acTL' === $type ) {
				$anim     = true;
				$resolved = true;
				break;
			}
			if ( 'IDAT' === $type || 'IEND' === $type ) {
				$resolved = true; // acTL は最初の IDAT より前にしか置かれない。ここまで無ければ静止画。
				break;
			}
			$offset += 12 + (int) $chunk_len[1]; // 長さ(4) + 種類(4) + 本体 + CRC(4)。必ず前に進む。
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		// 上限の回数に達しても IDAT・IEND に着かなかったときは、判断できていない。アニメとして扱い、変換しない側に倒す。
		return $anim || ! $resolved;
	}

	/**
	 * アニメーション WebP か。拡張ヘッダー（VP8X）のアニメーションフラグで判断する。
	 *
	 * @param string $path パス。
	 * @return bool
	 */
	private static function is_animated_webp( $path ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return true;
		}
		$head = fread( $fh, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! is_string( $head ) || strlen( $head ) < 21 ) {
			return true;
		}
		if ( 'VP8X' === substr( $head, 12, 4 ) && ( ord( $head[20] ) & 0x02 ) ) {
			return true;
		}
		// 念のため、先頭付近に ANIM チャンクがあるものも対象外にする。
		return false !== strpos( $head, 'ANIM' );
	}

	/**
	 * sRGB 以外の ICC プロファイルを持つか（GD は ICC を引き継げず、色が変わるため）。
	 * 構造をたどり切れず、プロファイルの有無を確かめられなかったときも true（GD では変換しない側に倒す）。
	 *
	 * @param string $path パス。
	 * @param string $mime MIME。
	 * @return bool
	 */
	private static function has_non_srgb_icc( $path, $mime ) {
		if ( 'image/png' === $mime ) {
			return self::png_has_non_srgb_icc( $path, false );
		}
		if ( 'image/webp' === $mime ) {
			return self::webp_has_non_srgb_icc( $path );
		}
		$info = self::jpeg_info( $path );
		if ( ! $info['complete'] ) {
			return true;
		}
		if ( null === $info['icc'] ) {
			return false;
		}
		// プロファイル本体の先頭部分に説明文（desc）が入っている。
		return false === stripos( substr( $info['icc'], 0, 4096 ), 'srgb' );
	}

	/**
	 * 静止 WebP の ICCP チャンクを、RIFF のチャンクを先頭から順にたどって探し、sRGB 以外か。
	 * ICCP の本体は圧縮されていない ICC プロファイルなので、JPEG と同じく先頭部分の説明文で判断する。
	 * たどり切れなかったとき(壊れている・チャンクが多すぎる・画像データに着く前にファイルが終わる)は true。VP8X の ICC フラグが立っているのに ICCP が見つからないときも true。EXIF の向きが 1 以外・確かめ切れないときも true(GD は WebP の向きを補正しない)。
	 *
	 * @param string $path パス。
	 * @return bool
	 */
	private static function webp_has_non_srgb_icc( $path ) {
		$total = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fh    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return true;
		}
		$result = true;
		$head   = fread( $fh, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( is_string( $head ) && 12 === strlen( $head ) && 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) && $total >= 20 ) {
			$pos       = 12;
			$icc_flag  = false;
			$icc_state = null; // null=ICCP なし / true=非 sRGB / false=sRGB。
			$bad       = false;
			$done      = false;
			// 画像データのチャンクの後ろにある EXIF(向き)も見るので、ファイルの終わりまでチャンクをたどる。
			for ( $n = 0; $n < 4096; $n++ ) {
				if ( $pos + 8 > $total ) {
					$done = ( $pos >= $total - 1 ); // ちょうど終わっていれば、たどり切れた(パディングの 1 バイトの余りは許す)。
					break;
				}
				if ( 0 !== fseek( $fh, $pos ) ) {
					break;
				}
				$h = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( ! is_string( $h ) || strlen( $h ) < 8 ) {
					break;
				}
				$type = substr( $h, 0, 4 );
				$size = unpack( 'V', substr( $h, 4, 4 ) );
				$size = (int) $size[1];
				if ( $pos + 8 + $size > $total ) {
					break; // チャンクの長さがファイルの終わりを越える(壊れた・切れた WebP)。たどり切れなかったので true。
				}
				if ( 'VP8X' === $type ) {
					$flags    = fread( $fh, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$icc_flag = is_string( $flags ) && '' !== $flags && ( ord( $flags ) & 0x20 );
				} elseif ( 'ICCP' === $type ) {
					$body      = (string) fread( $fh, min( max( $size, 1 ), 4096 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$icc_state = ( false === stripos( $body, 'srgb' ) );
				} elseif ( 'EXIF' === $type ) {
					// GD は EXIF の向きを引き継げず、WebP の向きの補正もしない。向きが 1 以外・確かめ切れないときは、GD で変換しない。
					$body = (string) fread( $fh, min( max( $size, 1 ), 65536 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$tiff = ( 'Exif' . "\0\0" === substr( $body, 0, 6 ) ) ? 6 : 0;
					$ori  = self::exif_orientation( $body, $tiff );
					if ( null === $ori || 1 !== $ori ) {
						$bad = true;
					}
				}
				$pos += 8 + $size + ( $size & 1 ); // チャンクは偶数バイトにそろえられる。
			}
			if ( $done ) {
				if ( $bad ) {
					$result = true;
				} elseif ( null !== $icc_state ) {
					$result = $icc_state;
				} else {
					$result = (bool) $icc_flag; // VP8X の ICC フラグが立っているのに ICCP が無い = 未確定(true)。
				}
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $result;
	}

	/**
	 * PNG の iCCP チャンクを、チャンクを先頭から順にたどって探し、sRGB 以外か。IEND までたどる。
	 * iCCP も sRGB も無いときは、gAMA(約 1/2.2)・cHRM(sRGB の原色・白色点)が sRGB 相当かも見る。
	 * たどり切れなかったとき（壊れている・チャンクが多すぎる）は true。
	 * $icc_preserved(Imagick: ICC を付け直し、向きも補正する)のときは、最初の IDAT(または IEND)までの gAMA・cHRM だけで判定する。
	 * そこに着く前に打ち切られたときは true。
	 *
	 * @param string $path          パス。
	 * @param bool   $icc_preserved ICC を引き継ぐエンジン(Imagick)か。
	 * @return bool
	 */
	private static function png_has_non_srgb_icc( $path, $icc_preserved = false ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return true;
		}
		$result      = true;
		$icc_state   = null; // null=iCCP なし / true=非 sRGB / false=sRGB。
		$bad         = false;
		$done        = false;
		$seen_idat   = false; // 最初の IDAT に着いた(PNG の仕様では、色の情報のチャンクはそれより前に出そろう)。
		$srgb_chunk  = false; // sRGB チャンクがある(PNG の仕様では、あれば gAMA・cHRM は無視される)。
		$gamma       = null;  // gAMA の値(×100000)。
		$chrm        = null;  // cHRM の 8 つの値(×100000: 白色点 x,y、赤 x,y、緑 x,y、青 x,y)。
		if ( "\x89PNG\r\n\x1a\n" === fread( $fh, 8 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$pos = 8;
			// eXIf(EXIF の向き)は IDAT の後ろにも置けるので、IEND までチャンクをたどる。IDAT は長さだけ見て飛ばす(上限 200000 チャンク)。
			for ( $n = 0; $n < 200000; $n++ ) {
				if ( 0 !== fseek( $fh, $pos ) ) {
					break;
				}
				$h = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( ! is_string( $h ) || strlen( $h ) < 8 ) {
					break;
				}
				$len  = ( ord( $h[0] ) << 24 ) | ( ord( $h[1] ) << 16 ) | ( ord( $h[2] ) << 8 ) | ord( $h[3] );
				$type = substr( $h, 4, 4 );
				if ( 'IEND' === $type ) {
					$done = true;
					break;
				}
				if ( 'IDAT' === $type && $icc_preserved ) {
					$seen_idat = true; // Imagick は ICC を付け直し、向きも補正するので、最初の IDAT までの色の情報だけで足りる。
					break;
				}
				if ( 'iCCP' === $type && null === $icc_state ) {
					$name      = ( $len > 0 ) ? (string) fread( $fh, min( $len, 80 ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$icc_state = ( false === stripos( $name, 'srgb' ) ); // iCCP は圧縮されていて中身を読めない。プロファイル名で判断する。
				} elseif ( 'sRGB' === $type ) {
					$srgb_chunk = true;
				} elseif ( 'gAMA' === $type && 4 === $len && null === $gamma ) {
					$v     = unpack( 'N', (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$gamma = is_array( $v ) ? (int) $v[1] : -1;
				} elseif ( 'cHRM' === $type && 32 === $len && null === $chrm ) {
					$v    = unpack( 'N8', (string) fread( $fh, 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$chrm = is_array( $v ) ? array_values( $v ) : array();
				} elseif ( 'eXIf' === $type ) {
					// GD は EXIF の向きを引き継げず、PNG の向きの補正もしない。向きが 1 以外・確かめ切れないときは、GD で変換しない。
					$body = ( $len > 0 ) ? (string) fread( $fh, min( $len, 65536 ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$tiff = ( 'Exif' . "\0\0" === substr( $body, 0, 6 ) ) ? 6 : 0;
					$ori  = self::exif_orientation( $body, $tiff );
					if ( null === $ori || 1 !== $ori ) {
						$bad = true;
					}
				}
				$pos += 12 + $len;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		// PNG の色の情報(iCCP・sRGB・gAMA・cHRM)は、仕様上、最初の IDAT より前に置かれる。IEND までたどれなくても(チャンクが多すぎる・壊れている)、
		// Imagick の経路では、そこまでに読めた値で判定する(たどり切れないことを理由に、色の検査を飛ばさない)。GD は、たどり切れなければ変換しない。
		if ( $icc_preserved && ! $done && ! $seen_idat ) {
			return true; // Imagick: 最初の IDAT にも IEND にも着く前に終わった(チャンクが多すぎる・壊れている)。色の情報を読み切れたと言えないので、変換しない。
		}
		if ( $done || $icc_preserved ) {
			// Imagick は ICC を引き継ぎ、EXIF の向きも補正する。引き継げない gAMA・cHRM だけを見る($icc_preserved)。GD は ICC も向きも引き継げない。
			if ( $bad && ! $icc_preserved ) {
				return true;
			}
			if ( null !== $icc_state ) {
				return $icc_preserved ? false : $icc_state;
			}
			if ( $srgb_chunk ) {
				return false; // sRGB チャンクがあれば、gAMA・cHRM は無視される。
			}
			// iCCP も sRGB も無いとき、gAMA・cHRM が sRGB 相当でなければ、色が変わる(GD は gAMA・cHRM を反映した変換も、プロファイルの付与もしない)。
			// sRGB 相当: gAMA が約 45455(1/2.2)、cHRM が sRGB の原色・白色点。読めない・形が違う値は、非 sRGB として GD で変換しない側に倒す。
			if ( null !== $gamma && abs( $gamma - 45455 ) > 100 ) {
				return true;
			}
			if ( null !== $chrm ) {
				$srgb = array( 31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000 );
				if ( 8 !== count( $chrm ) ) {
					return true;
				}
				foreach ( $srgb as $i => $want ) {
					if ( abs( (int) $chrm[ $i ] - $want ) > 100 ) {
						return true;
					}
				}
			}
			return false;
		}
		return $result; // GD だけ: IEND に着く前に終わった(壊れている・チャンクが多すぎる)。確かめ切れないので true。
	}

	/**
	 * JPEG のセグメントを先頭から順にたどり、EXIF の向きと ICC プロファイルを集める（画像データ SOS の手前まで）。
	 * 読む量を固定の大きさで打ち切らない（大きな APP セグメントの後ろに EXIF・ICC があっても見つける）。
	 * セグメントの数・詰め物の量に上限を置き、上限に達したときや構造が壊れているときは complete=false（未確定）で返す。
	 *
	 * @param string $path パス。
	 * @return array complete(bool 最後までたどれたか)・orientation(int 1〜8。見つからないか読めなければ 1)・icc(string|null プロファイルの先頭部分)。
	 */
	private static function jpeg_info( $path ) {
		$out = array(
			'complete'    => false,
			'orientation' => 1,
			'icc'         => null,
		);
		$fh  = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return $out;
		}
		if ( "\xFF\xD8" === fread( $fh, 2 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$pos     = 2;
			$padding = 0;
			$seen_ex = false;
			$unknown = false;
			for ( $n = 0; $n < 4096; $n++ ) {
				if ( 0 !== fseek( $fh, $pos ) ) {
					break;
				}
				$h = fread( $fh, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( ! is_string( $h ) || strlen( $h ) < 2 || "\xFF" !== $h[0] ) {
					break;
				}
				$marker = ord( $h[1] );
				if ( 0xFF === $marker ) {
					// 詰め物(0xFF の連続)は、まとめて読み飛ばす。量に上限を置く。
					if ( ++$padding > 256 ) {
						break;
					}
					if ( 0 !== fseek( $fh, $pos ) ) {
						break;
					}
					$chunk = fread( $fh, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$run   = is_string( $chunk ) ? strspn( $chunk, "\xFF" ) : 0;
					// 連続した 0xFF の最後の 1 つは、次のセグメントの先頭(マーカーの前置き)。そこから次の読み込みを始める。
					$pos += max( 1, $run - 1 );
					continue;
				}
				if ( 0xD8 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
					$pos += 2;
					continue;
				}
				if ( 0xDA === $marker || 0xD9 === $marker ) {
					$out['complete'] = ! $unknown; // 画像データに到達。ここまでの APP セグメントは全部見た(EXIF の向きを確かめ切れなかったときは未確定)。
					break;
				}
				if ( strlen( $h ) < 4 ) {
					break;
				}
				$seg = ( ord( $h[2] ) << 8 ) | ord( $h[3] );
				if ( $seg < 2 ) {
					break;
				}
				if ( 0xE1 === $marker && ! $seen_ex && $seg >= 16 ) {
					$buf = fread( $fh, $seg - 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( is_string( $buf ) && 'Exif' . "\0\0" === substr( $buf, 0, 6 ) ) {
						$o = self::exif_orientation( $buf, 6 );
						if ( null === $o ) {
							$unknown = true; // 向きを確かめ切れなかった。向き 1 とは扱わない(complete を真にしない)。
						} else {
							$out['orientation'] = $o;
						}
						$seen_ex = true;
					}
				} elseif ( 0xE2 === $marker && null === $out['icc'] && $seg >= 16 ) {
					$buf = fread( $fh, min( $seg - 2, 4200 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( is_string( $buf ) && 'ICC_PROFILE' . "\0" === substr( $buf, 0, 12 ) ) {
						$out['icc'] = substr( $buf, 14 );
					}
				}
				$pos += 2 + $seg;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * JPEG の EXIF の向き（1〜8）を読む。exif 拡張が無くても動くよう自前で解析する。見つからない・読めないときは 1。
	 *
	 * @param string $path パス。
	 * @return int
	 */
	public static function read_jpeg_orientation( $path ) {
		$info = self::jpeg_info( $path );
		return (int) $info['orientation'];
	}

	/**
	 * EXIF のセグメントの中身から、向き（1〜8）を読む。
	 *
	 * @param string $data 「Exif\0\0」で始まるセグメントの中身。
	 * @param int    $tiff TIFF ヘッダーの位置（「Exif\0\0」の直後）。
	 * @return int|null 向き。向きのタグが無いと確かめられたときは 1。IFD が長すぎる・壊れていて、最後まで確かめられなかったときは null（未確定。1 と同一視しない）。
	 */
	private static function exif_orientation( $data, $tiff ) {
		$len = strlen( $data );
		$bo  = substr( $data, $tiff, 2 );
		if ( 'II' !== $bo && 'MM' !== $bo ) {
			return null;
		}
		$fmt16 = ( 'II' === $bo ) ? 'v' : 'n';
		$fmt32 = ( 'II' === $bo ) ? 'V' : 'N';
		$off_b = substr( $data, $tiff + 4, 4 );
		if ( 4 !== strlen( $off_b ) ) {
			return null; // IFD のオフセットまで届かない(壊れている)。
		}
		$off   = unpack( $fmt32, $off_b );
		$ifd   = $tiff + (int) $off[1];
		if ( $ifd + 2 > $len ) {
			return null;
		}
		$count = unpack( $fmt16, substr( $data, $ifd, 2 ) );
		$count = (int) $count[1];
		if ( $count > 2000 ) {
			return null; // 異常に長い IFD。確かめ切れない。
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = $ifd + 2 + $i * 12;
			if ( $entry + 12 > $len ) {
				return null; // IFD がデータの外に出ている。
			}
			$tag = unpack( $fmt16, substr( $data, $entry, 2 ) );
			if ( 0x0112 === (int) $tag[1] ) {
				// 値の型は SHORT(3) が標準。LONG(4) で書く非準拠のファイルもあるので、型に応じて読む。他の型・個数が 1 でないものは確かめ切れない。
				$type  = unpack( $fmt16, substr( $data, $entry + 2, 2 ) );
				$cnt   = unpack( $fmt32, substr( $data, $entry + 4, 4 ) );
				if ( 1 !== (int) $cnt[1] ) {
					return null;
				}
				if ( 3 === (int) $type[1] ) {
					$val = unpack( $fmt16, substr( $data, $entry + 8, 2 ) );
				} elseif ( 4 === (int) $type[1] ) {
					$val = unpack( $fmt32, substr( $data, $entry + 8, 4 ) );
				} else {
					return null;
				}
				$val = (int) $val[1];
				return ( $val >= 1 && $val <= 8 ) ? $val : 1; // 範囲外の値は、ブラウザも回転なし(1)として扱う。
			}
		}
		return 1;
	}

	/**
	 * GD が劣化なしの WebP を書けるか(IMG_WEBP_LOSSLESS は PHP 8.1.0 から)。
	 *
	 * @return bool
	 */
	private static function gd_lossless_webp_supported() {
		// フィルターは「書けないことにする」(false を返す)用途だけ。定数が無い環境では、フィルターが true を返しても、書けるとは扱わない。
		return defined( 'IMG_WEBP_LOSSLESS' ) && (bool) apply_filters( 'kashiwazaki_next_gen_images_gd_lossless_webp', true );
	}

	/**
	 * GD で変換する。
	 *
	 * @param string $src    元ファイル。
	 * @param string $mime   MIME。
	 * @param string $dest   書き出し先。
	 * @param string $format webp|avif。
	 * @param array  $opts   オプション。
	 * @param mixed  $size   getimagesize の結果。
	 * @return array|WP_Error
	 */
	private static function encode_gd( $src, $mime, $dest, $format, array $opts, $size ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'unsupported', 'gd missing' );
		}
		if ( 'webp' === $format && ! function_exists( 'imagewebp' ) ) {
			return new WP_Error( 'unsupported', 'gd webp missing' );
		}
		// 劣化なしを求められたのに、GD が劣化なしで書けない(IMG_WEBP_LOSSLESS は PHP 8.1 から)なら、画質を指定した劣化のある WebP にはしない。
		// 失敗として返す(予備のエンジンがあれば、そちらで劣化なしを試す。無ければ、変換しない)。
		// 判定は 1 回だけ行い、その結果を書き出しでも使う(書き出しで判定し直すと、フィルターが途中で値を変えたとき、劣化のある WebP を書いてしまう)。
		$want_lossless = ( 'webp' === $format && ! empty( $opts['lossless'] ) );
		$gd_lossless   = $want_lossless && self::gd_lossless_webp_supported();
		if ( $want_lossless && ! $gd_lossless ) {
			return new WP_Error( 'lossless_unavailable', 'gd cannot write lossless webp' );
		}
		if ( 'avif' === $format && ! function_exists( 'imageavif' ) ) {
			return new WP_Error( 'unsupported', 'gd avif missing' );
		}
		if ( ! is_array( $size ) ) {
			return new WP_Error( 'corrupt', 'size unknown' );
		}
		if ( 'image/jpeg' === $mime && isset( $size['channels'] ) && 4 === (int) $size['channels'] ) {
			return new WP_Error( 'cmyk', 'cmyk jpeg is not supported by gd' );
		}
		if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) && self::has_non_srgb_icc( $src, $mime ) ) {
			return new WP_Error( 'icc', 'non-sRGB color profile cannot be preserved by gd' );
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];

		// GD はまず全体をメモリに展開する。足りないまま続けると致命的エラーで止まるので先に見積もる。
		$need = (int) ( $width * $height * 4 * 3 ) + 8 * MB_IN_BYTES;
		if ( ! self::has_memory( $need ) ) {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'image' );
			}
			if ( ! self::has_memory( $need ) ) {
				return new WP_Error( 'memory', 'not enough memory' );
			}
		}

		switch ( $mime ) {
			case 'image/jpeg':
				$img = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $src ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'image/png':
				$img = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $src ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'image/gif':
				$img = function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $src ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'image/webp':
				$img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $src ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			default:
				$img = false;
		}
		if ( ! $img ) {
			return new WP_Error( 'decode', 'gd could not decode the image' );
		}

		// 透明を保ったまま真のカラー画像にそろえる（パレット画像・GIF の透明が黒くならないように）。
		if ( ! imageistruecolor( $img ) ) {
			$canvas = imagecreatetruecolor( $width, $height );
			if ( ! $canvas ) {
				imagedestroy( $img );
				return new WP_Error( 'memory', 'could not allocate canvas' );
			}
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			$clear = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
			imagefilledrectangle( $canvas, 0, 0, $width, $height, $clear );
			imagecopy( $canvas, $img, 0, 0, 0, 0, $width, $height );
			imagedestroy( $img );
			$img = $canvas;
		}
		imagealphablending( $img, false );
		imagesavealpha( $img, true );

		if ( 'image/jpeg' === $mime ) {
			$jinfo = self::jpeg_info( $src );
			if ( ! $jinfo['complete'] ) {
				// 向きを確かめ切れない JPEG は、向きを無視した画像を出さないよう、ここでも変換しない(ICC 検査の順序に頼らない)。
				imagedestroy( $img );
				return new WP_Error( 'icc', 'jpeg structure could not be fully inspected (orientation unknown)' );
			}
			$img = self::gd_apply_orientation( $img, (int) $jinfo['orientation'] );
			if ( ! $img ) {
				return new WP_Error( 'engine_error', 'orientation failed' );
			}
			imagealphablending( $img, false );
			imagesavealpha( $img, true );
		}

		$out_w = imagesx( $img );
		$out_h = imagesy( $img );

		$quality = isset( $opts['quality'] ) ? (int) $opts['quality'] : 80;
		$ok      = false;
		if ( 'webp' === $format ) {
			if ( $gd_lossless ) {
				$ok = imagewebp( $img, $dest, IMG_WEBP_LOSSLESS );
			} else {
				$ok = imagewebp( $img, $dest, $quality );
			}
		} else {
			// speed は大きいほど速く、画質は少し下がる。共用サーバーの時間制限に合わせて速めにする。
			$ok = imageavif( $img, $dest, $quality, 7 );
		}
		imagedestroy( $img );

		if ( ! $ok ) {
			return new WP_Error( 'engine_error', 'gd could not write the file' );
		}
		return array(
			'width'  => $out_w,
			'height' => $out_h,
		);
	}

	/**
	 * EXIF の向きに合わせて GD 画像を回転・反転する。
	 *
	 * @param resource|GdImage $img         画像。
	 * @param int              $orientation 1〜8。
	 * @return resource|GdImage|false
	 */
	private static function gd_apply_orientation( $img, $orientation ) {
		if ( $orientation <= 1 || $orientation > 8 ) {
			return $img;
		}
		// 向きごとの補正（先に回転、あとで反転）。ImageMagick の -auto-orient の結果と画素単位で一致することを確認済み。
		// 5 は転置（反時計回りに 90 度回して上下反転）、7 は反転転置（時計回りに 90 度回して上下反転）。
		$steps = array(
			2 => array( 0, IMG_FLIP_HORIZONTAL ),
			3 => array( 180, 0 ),
			4 => array( 0, IMG_FLIP_VERTICAL ),
			5 => array( 90, IMG_FLIP_VERTICAL ),
			6 => array( 270, 0 ),
			7 => array( 270, IMG_FLIP_VERTICAL ),
			8 => array( 90, 0 ),
		);
		list( $angle, $flip ) = $steps[ $orientation ];

		if ( $angle ) {
			if ( ! function_exists( 'imagerotate' ) ) {
				return false;
			}
			$rotated = imagerotate( $img, $angle, 0 ); // 反時計回りに回転する。
			if ( ! $rotated ) {
				return false;
			}
			imagedestroy( $img );
			$img = $rotated;
		}
		if ( $flip ) {
			if ( ! function_exists( 'imageflip' ) ) {
				return false;
			}
			imageflip( $img, $flip );
		}
		return $img;
	}

	/**
	 * Imagick で変換する。
	 *
	 * @param string $src    元ファイル。
	 * @param string $mime   MIME。
	 * @param string $dest   書き出し先。
	 * @param string $format webp|avif。
	 * @param array  $opts   オプション。
	 * @return array|WP_Error
	 */
	private static function encode_imagick( $src, $mime, $dest, $format, array $opts ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return new WP_Error( 'unsupported', 'imagick missing' );
		}

		// 形式を明示して読み込む。拡張子や中身の見かけに任せると、画像のふりをしたファイルから
		// ImageMagick の別の読み込み機能が動いてしまうため。
		$coders = array(
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/gif'  => 'GIF',
			'image/webp' => 'WEBP',
		);
		if ( ! isset( $coders[ $mime ] ) ) {
			return new WP_Error( 'unsupported', 'mime not supported' );
		}

		// PNG の gAMA・cHRM(iCCP も sRGB チャンクも無いときの色の情報)は、ImageMagick も反映せず、書き出しでも付け直さない(stripImage で捨てる)。
		// sRGB 相当でなければ、GD と同じく変換しない(色が変わるため)。ICC(iCCP)は、下で付け直すので、ここでは見ない。
		if ( 'image/png' === $mime && self::png_has_non_srgb_icc( $src, true ) ) {
			return new WP_Error( 'icc', 'png gamma/chromaticities cannot be preserved' );
		}

		$im = new Imagick();
		try {
			$im->readImage( $coders[ $mime ] . ':' . $src . '[0]' );

			if ( $im->getImageColorspace() === Imagick::COLORSPACE_CMYK ) {
				// GD と同じく、CMYK は変換しない。埋め込みの ICC を使わない単純な式で sRGB にすると、表示の色がずれる。
				$im->clear();
				$im->destroy();
				return new WP_Error( 'cmyk', 'cmyk image is not converted' );
			}
			$icc = array();
			try {
				$icc = $im->getImageProfiles( 'icc', true );
			} catch ( Throwable $e ) {
				$icc = array();
			}

			$orientation = $im->getImageOrientation();
			self::imagick_apply_orientation( $im, $orientation );
			$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );

			$im->stripImage();
			if ( ! empty( $icc['icc'] ) ) {
				$im->profileImage( 'icc', $icc['icc'] );
			}

			$im->setImageFormat( $format );
			$quality = isset( $opts['quality'] ) ? (int) $opts['quality'] : 80;
			$im->setImageCompressionQuality( $quality );
			if ( 'webp' === $format ) {
				$im->setOption( 'webp:method', '4' );
				if ( ! empty( $opts['lossless'] ) ) {
					$im->setOption( 'webp:lossless', 'true' );
				}
			} else {
				$im->setOption( 'heic:speed', '7' );
			}

			$width  = $im->getImageWidth();
			$height = $im->getImageHeight();
			$ok     = $im->writeImage( $format . ':' . $dest );
			$im->clear();
			$im->destroy();
		} catch ( Throwable $e ) {
			try {
				$im->clear();
				$im->destroy();
			} catch ( Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			return new WP_Error( 'engine_error', 'imagick: ' . $e->getMessage() );
		}

		if ( ! $ok ) {
			return new WP_Error( 'engine_error', 'imagick could not write the file' );
		}
		return array(
			'width'  => (int) $width,
			'height' => (int) $height,
		);
	}

	/**
	 * Imagick 画像を EXIF の向きに合わせる。
	 *
	 * @param Imagick $im          画像。
	 * @param int     $orientation Imagick の向き定数。
	 * @return void
	 */
	private static function imagick_apply_orientation( Imagick $im, $orientation ) {
		$transparent = new ImagickPixel( 'none' );
		switch ( $orientation ) {
			case Imagick::ORIENTATION_TOPRIGHT: // 2。
				$im->flopImage();
				break;
			case Imagick::ORIENTATION_BOTTOMRIGHT: // 3。
				$im->rotateImage( $transparent, 180 );
				break;
			case Imagick::ORIENTATION_BOTTOMLEFT: // 4。
				$im->flipImage();
				break;
			case Imagick::ORIENTATION_LEFTTOP: // 5。
				$im->transposeImage();
				break;
			case Imagick::ORIENTATION_RIGHTTOP: // 6。
				$im->rotateImage( $transparent, 90 );
				break;
			case Imagick::ORIENTATION_RIGHTBOTTOM: // 7。
				$im->transverseImage();
				break;
			case Imagick::ORIENTATION_LEFTBOTTOM: // 8。
				$im->rotateImage( $transparent, 270 );
				break;
			default:
				break;
		}
	}

	/**
	 * 書き出したファイルが本当にその形式の画像か確かめる。
	 *
	 * @param string $path   パス。
	 * @param string $format webp|avif。
	 * @param int    $width  期待する幅（0 なら比べない）。
	 * @param int    $height 期待する高さ。
	 * @return bool
	 */
	public static function verify_output( $path, $format, $width, $height ) {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || filesize( $path ) < 32 ) {
			return false;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! is_string( $head ) || strlen( $head ) < 16 ) {
			return false;
		}
		if ( 'webp' === $format ) {
			if ( 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) ) {
				return false;
			}
		} else {
			if ( 'ftyp' !== substr( $head, 4, 4 ) || ! in_array( substr( $head, 8, 4 ), array( 'avif', 'avis' ), true ) ) {
				return false;
			}
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $info ) && ! empty( $info[0] ) ) {
			// getimagesize が読める環境（WebP は PHP 7.1+、AVIF は 8.1+）では寸法も照合する。
			if ( $width && $height && ( (int) $info[0] !== (int) $width || (int) $info[1] !== (int) $height ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * あとどれだけメモリを使えるか見て、必要量を満たすか返す。
	 *
	 * @param int $bytes 必要なバイト数。
	 * @return bool
	 */
	private static function has_memory( $bytes ) {
		$limit = ini_get( 'memory_limit' );
		if ( false === $limit || '' === $limit || '-1' === (string) $limit ) {
			return true;
		}
		$limit_bytes = wp_convert_hr_to_bytes( $limit );
		if ( $limit_bytes <= 0 ) {
			return true;
		}
		return ( $limit_bytes - memory_get_usage( true ) ) > $bytes;
	}

	/**
	 * 一時ファイルを登録する（途中で異常終了しても残らないように）。
	 *
	 * @param string $path パス。
	 * @return void
	 */
	public static function register_tmp( $path ) {
		self::$tmp_files[ $path ] = true;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'cleanup_tmp' ) );
		}
	}

	/**
	 * 一時ファイルを消して登録を外す。
	 *
	 * @param string $path パス。
	 * @return void
	 */
	public static function remove_tmp( $path ) {
		unset( self::$tmp_files[ $path ] );
		if ( '.tmp' === substr( $path, -4 ) && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * 終了時に残っている一時ファイルを消す。
	 *
	 * @return void
	 */
	public static function cleanup_tmp() {
		foreach ( array_keys( self::$tmp_files ) as $path ) {
			self::remove_tmp( $path );
		}
	}
}
