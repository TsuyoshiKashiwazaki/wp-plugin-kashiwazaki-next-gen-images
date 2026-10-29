<?php
/**
 * 添付ファイル（メディアライブラリの 1 枚）単位の変換処理。
 *
 * 変換後のファイルは元画像と同じフォルダに「元のファイル名.webp」「元のファイル名.avif」として置く。
 * 元画像は読むだけで、書き換えも削除もしない。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 変換の本体。
 */
final class Kashiwazaki_Next_Gen_Images_Converter {

	const META_KEY    = '_kashiwazaki_next_gen_images';
	const LOCK_PREFIX = 'kashiwazaki_next_gen_images_lock_';
	const LOCK_TTL    = 300;
	const STATS_KEY   = 'kashiwazaki_next_gen_images_stats';
	const CONFLICT_OPTION = 'kashiwazaki_next_gen_images_name_conflicts';

	/**
	 * 台帳が古いかもしれないことを示す「汚れ印」（時刻）の option 名と、台帳に刻む計算開始時刻のキー（相対パスのキーとは衝突しない）。
	 */
	const LEDGER_DIRTY_OPTION = 'kashiwazaki_next_gen_images_name_conflicts_dirty';
	const LEDGER_STAMP_KEY    = '#t';
	const STALE_OPTION    = 'kashiwazaki_next_gen_images_stale_variants';

	/**
	 * 条件が変われば再挑戦してよい失敗の種類。
	 */
	const RETRYABLE = array( 'memory', 'engine_error', 'invalid_output', 'write_failed', 'decode' );

	/**
	 * 削除される添付ファイルのうち、削除の後に台帳を作り直すもの（ID => true）。
	 *
	 * @var array
	 */
	private static $pending_refresh = array();

	/**
	 * いま名前付きロックを持っているか（入れ子で取らないため。古い MySQL は 2 つ目のロックで 1 つ目を手放す）。
	 *
	 * @var bool
	 */
	private static $holding_lock = false;

	/**
	 * 添付ファイルを削除する前に控えた、自分で作った(所有の記録がある)変換ファイルの絶対パス(キー)。元ファイルの削除に連動して消すのは、このパスのものだけ。
	 *
	 * @var array
	 */
	private static $owned_on_delete = array();

	/**
	 * このリクエストで、台帳を確定できなかったか（conflict_files が立てる。配信側が、変換ファイルを使わない側に倒すために見る）。
	 *
	 * @var bool
	 */
	private static $ledger_unknown = false;

	/**
	 * run() がこの呼び出しで新しく公開した変換ファイル(path)と、その削減バイト(saved)。所有記録を保存できなかったとき・例外で終わったとき、片付ける。
	 *
	 * @var array
	 */
	private static $created_now = array();

	/**
	 * 名前付きロック（MySQL の GET_LOCK）の中で処理を実行する。テーブルの形式に依らず、すべての接続で効く。
	 * GET_LOCK のロックはトランザクションの確定では解放されず、RELEASE_LOCK かセッションの終了で解放される（MySQL 公式）。
	 *
	 * @param string   $suffix   ロックの種類（ledger|stale|job）。サイトごとに別の名前にする。
	 * @param callable $callback 実行する処理。
	 * @param int      $wait     ロックを待つ秒数。
	 * @return mixed ロックが取れなかったときは null。取れたときは処理の戻り値。
	 */
	public static function with_lock( $suffix, $callback, $wait = 5 ) {
		global $wpdb;
		if ( self::$holding_lock ) {
			return call_user_func( $callback ); // すでに持っている（同じリクエストの中）。
		}
		$name = 'kngi_' . md5( $wpdb->dbname . '|' . $wpdb->prefix ) . '_' . $suffix;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, (int) $wait ) );
		if ( 1 !== (int) $got ) {
			return null;
		}
		self::$holding_lock = true;
		try {
			return call_user_func( $callback );
		} finally {
			self::$holding_lock = false;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * options の 1 行を直接書く（無ければ作る）。書いたら WordPress のキャッシュを捨てる。
	 *
	 * @param string $name     option 名。
	 * @param mixed  $value    値。
	 * @param string $autoload yes|no。
	 * @param bool   $newer_only 真なら、値を数（時刻）として扱い、すでにある値より大きいときだけ書き換える（古い時刻を取った処理が、あとから新しい時刻を上書きしない）。
	 * @return bool 書けたら true。SQL が失敗したら false（wpdb::query は失敗で false を返す。呼び出し元は保存できなかったものとして扱う）。
	 */
	public static function write_option_row( $name, $value, $autoload = 'yes', $newer_only = false ) {
		global $wpdb;
		$update = $newer_only
			? 'option_value = IF(CAST(option_value AS DECIMAL(24,6)) < CAST(VALUES(option_value) AS DECIMAL(24,6)), VALUES(option_value), option_value)'
			: 'option_value = VALUES(option_value)';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$res = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE {$update}", $name, maybe_serialize( $value ), $autoload ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $name, 'options' );
		return false !== $res;
	}

	/**
	 * この項目（1 ファイル分の記録）で、その形式の変換ファイルを自分が所有しているか（所有の記録か、作り直しの途中の po）。
	 *
	 * @param array  $entry  1 ファイル分の記録。
	 * @param string $format webp|avif。
	 * @return bool
	 */
	public static function owns_entry( $entry, $format ) {
		return is_array( $entry ) && ( ! empty( $entry[ $format ]['owned'] ) || ! empty( $entry['po'][ $format ] ) );
	}

	/**
	 * 配信可否を変えたあとに、同じリクエスト内の配信キャッシュと PHP のファイル情報のキャッシュを捨てる。
	 *
	 * @return void
	 */
	public static function variants_changed() {
		clearstatcache( true );
		if ( class_exists( 'Kashiwazaki_Next_Gen_Images_Delivery' ) ) {
			Kashiwazaki_Next_Gen_Images_Delivery::reset_variant_cache();
		}
	}

	/**
	 * 変換の対象になる添付ファイルか。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return bool
	 */
	public static function is_supported_attachment( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}
		return in_array( $post->post_mime_type, Kashiwazaki_Next_Gen_Images_Engine::SOURCE_MIMES, true );
	}

	/**
	 * アップロードフォルダの絶対パス（末尾スラッシュ無し）。
	 *
	 * @return string
	 */
	public static function uploads_basedir() {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
			return '';
		}
		return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}

	/**
	 * アップロードフォルダからの相対パスとして安全か（上位フォルダへ抜けない等）。
	 *
	 * @param string $rel 相対パス。
	 * @return bool
	 */
	public static function is_safe_relative_path( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) ) {
			return false;
		}
		if ( '/' === $rel[0] || preg_match( '#^[A-Za-z]:#', $rel ) || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return false;
		}
		return true;
	}

	/**
	 * 添付ファイルに属する変換元ファイル（フルサイズ＋各サイズ）を集める。実在するものだけ返す。
	 *
	 * @param int        $id       添付ファイル ID。
	 * @param array|null $metadata 保存前のメタデータ（アップロード直後に使う）。
	 * @param bool       $all      true なら「変換しないサイズ」の設定を無視して全部返す。
	 * @return array array( array( key, rel, path, mime ), ... )
	 */
	public static function source_files( $id, $metadata = null, $all = false ) {
		$base = self::uploads_basedir();
		$rel  = get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $base || ! self::is_safe_relative_path( $rel ) ) {
			return array();
		}
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $id );
		}
		$exclude = $all ? array() : (array) Kashiwazaki_Next_Gen_Images_Settings::get( 'exclude_sizes' );

		$candidates = array();
		$full_mime  = get_post_mime_type( $id );
		if ( ! in_array( 'full', $exclude, true ) ) {
			$candidates[] = array( 'full', $rel, $full_mime );
		}
		$dir = dirname( $rel );
		$dir = ( '.' === $dir ) ? '' : $dir . '/';
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $data ) {
				if ( in_array( $name, $exclude, true ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
					continue;
				}
				$mime = ( ! empty( $data['mime-type'] ) && is_string( $data['mime-type'] ) ) ? $data['mime-type'] : $full_mime;
				// 2 つめ以降は基準フォルダ直下のファイル名だけを信用する。
				$candidates[] = array( (string) $name, $dir . wp_basename( $data['file'] ), $mime );
			}
		}

		$real_base = realpath( $base );
		$seen      = array();
		$files     = array();
		foreach ( $candidates as $candidate ) {
			list( $key, $crel, $mime ) = $candidate;
			if ( ! self::is_safe_relative_path( $crel ) || isset( $seen[ $crel ] ) ) {
				continue;
			}
			$path = $base . '/' . $crel;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$real = realpath( $path );
			if ( false === $real || false === $real_base || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $real_base ) . '/' ) ) {
				continue; // シンボリックリンク等でアップロードフォルダの外を指すものは触らない。
			}
			if ( ! in_array( $mime, Kashiwazaki_Next_Gen_Images_Engine::SOURCE_MIMES, true ) ) {
				continue;
			}
			$seen[ $crel ] = true;
			$files[]       = array(
				'key'  => $key,
				'rel'  => $crel,
				'path' => $path,
				'mime' => $mime,
			);
		}
		return $files;
	}

	/**
	 * 保存済みの変換記録を取り出す。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return array
	 */
	public static function get_meta( $id ) {
		$meta = get_post_meta( $id, self::META_KEY, true );
		return ( is_array( $meta ) && isset( $meta['files'] ) && is_array( $meta['files'] ) ) ? $meta : array();
	}

	/**
	 * この形式をこのファイルに作る意味があるか（WebP の画像を WebP にしても意味がない）。
	 *
	 * @param string $mime   変換元の MIME。
	 * @param string $format 形式。
	 * @return bool
	 */
	private static function pair_applies( $mime, $format ) {
		return ! ( 'webp' === $format && 'image/webp' === $mime );
	}

	/**
	 * 「PNG・GIF は劣化なしで変換」の設定で、この元画像の WebP を劣化なしで作るべきか。
	 * 劣化なしの設定に合わない古い変換ファイル(非可逆)を、新しい出力が「軽くならない」で捨てられたときに残さない判断にも使う。
	 *
	 * @param string $mime   元画像の MIME。
	 * @param string $format webp|avif。
	 * @return bool
	 */
	public static function lossless_wanted( $mime, $format ) {
		return 'webp' === $format && in_array( $mime, array( 'image/png', 'image/gif' ), true ) && ! empty( Kashiwazaki_Next_Gen_Images_Settings::get( 'png_lossless' ) );
	}

	/**
	 * 「PNG・GIF は劣化なしで変換」の設定のため、この元画像の AVIF を作らない(配信もしない)か。
	 * AVIF は劣化なしで書き出せず、配信は AVIF を WebP より先に選ぶので、AVIF 対応のブラウザに劣化した画像が出てしまう。
	 *
	 * @param string $mime   元画像の MIME。
	 * @param string $format webp|avif。
	 * @return bool
	 */
	public static function lossless_blocks_avif( $mime, $format ) {
		return 'avif' === $format && in_array( $mime, array( 'image/png', 'image/gif' ), true ) && ! empty( Kashiwazaki_Next_Gen_Images_Settings::get( 'png_lossless' ) );
	}

	/**
	 * 実際に作る形式（設定で有効、かつこのサーバーで書き出せるもの）。速い WebP を先にする。
	 *
	 * @return string[]
	 */
	public static function active_formats() {
		$enabled   = Kashiwazaki_Next_Gen_Images_Settings::enabled_formats();
		$available = Kashiwazaki_Next_Gen_Images_Engine::available_formats();
		$formats   = array();
		foreach ( array( 'webp', 'avif' ) as $format ) {
			if ( in_array( $format, $enabled, true ) && in_array( $format, $available, true ) ) {
				$formats[] = $format;
			}
		}
		return $formats;
	}

	/**
	 * 処理が必要か（未変換・一部だけ・元画像が差し替わった、など）。
	 *
	 * @param int   $id   添付ファイル ID。
	 * @param array $args retry_failed(bool)、metadata(array: 保存前の新しいメタデータ。メタデータ更新のフィルターで渡されたものを判定に使う)。
	 * @return bool
	 */
	public static function needs_work( $id, array $args = array() ) {
		if ( ! self::is_supported_attachment( $id ) ) {
			return false;
		}
		$formats = self::active_formats();
		if ( empty( $formats ) ) {
			return false;
		}
		$files = self::source_files( $id, ( isset( $args['metadata'] ) && is_array( $args['metadata'] ) ) ? $args['metadata'] : null );
		if ( empty( $files ) ) {
			return false;
		}
		$meta = self::get_meta( $id );
		if ( empty( $meta ) ) {
			return true;
		}
		$retry = ! empty( $args['retry_failed'] );
		foreach ( $files as $f ) {
			$entry = isset( $meta['files'][ $f['rel'] ] ) ? $meta['files'][ $f['rel'] ] : null;
			if ( ! is_array( $entry ) ) {
				return true;
			}
			clearstatcache( true, $f['path'] );
			if ( (int) $entry['src_size'] !== (int) filesize( $f['path'] ) || (int) $entry['src_mtime'] !== (int) filemtime( $f['path'] ) ) {
				return true;
			}
			foreach ( $formats as $format ) {
				if ( ! self::pair_applies( $f['mime'], $format ) ) {
					continue;
				}
				// 「劣化なし」の設定は、あとから変わり得る。設定に合わなくなった記録は、処理が必要と判定する(片付け・作り直し)。
				if ( self::lossless_blocks_avif( $f['mime'], $format ) ) {
					if ( isset( $entry[ $format ] ) ) {
						return true; // 以前に作った AVIF(劣化している)の片付け。
					}
				} elseif ( isset( $entry['skip'][ $format ] ) && 'lossless_avif' === $entry['skip'][ $format ] ) {
					return true; // 設定が戻った。AVIF を作り直す。
				}
				if ( isset( $entry[ $format ] ) ) {
					if ( ! is_file( $f['path'] . '.' . $format ) ) {
						return true; // 変換済みのはずのファイルが消えている。
					}
					continue;
				}
				if ( isset( $entry['skip'][ $format ] ) ) {
					if ( $retry && in_array( $entry['skip'][ $format ], self::RETRYABLE, true ) ) {
						return true;
					}
					continue;
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * 自分が取った鍵の値（リクエスト内）。他者に奪われた鍵を、うっかり消さないために使う。
	 *
	 * @var array
	 */
	private static $lock_values = array();

	/**
	 * 同じ画像を同時に処理しないための鍵を取る。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return bool 取れたら true。
	 */
	private static function lock( $id ) {
		global $wpdb;
		$name  = self::LOCK_PREFIX . (int) $id;
		$value = time() . '-' . wp_generate_password( 8, false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		if ( 1 === (int) $got ) {
			self::$lock_values[ (int) $id ] = $value;
			return true;
		}
		// 既にある鍵が古ければ（処理が途中で落ちた）奪う。奪えるのは 1 つのリクエストだけ。
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null !== $old && ( time() - (int) $old ) > self::LOCK_TTL ) {
			$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old ) );
			if ( 1 === (int) $swapped ) {
				self::$lock_values[ (int) $id ] = $value;
				return true;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return false;
	}

	/**
	 * 鍵を返す（自分が取った鍵のときだけ）。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return void
	 */
	private static function unlock( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! isset( self::$lock_values[ $id ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_PREFIX . $id,
				'option_value' => self::$lock_values[ $id ],
			)
		);
		unset( self::$lock_values[ $id ] );
	}

	/**
	 * 添付ファイル 1 枚を変換する。
	 *
	 * @param int   $id   添付ファイル ID。
	 * @param array $args force(bool), retry_failed(bool), deadline(float: microtime の締め切り), metadata(array), force_token(string)。
	 * @return array state(done|partial|failed|skipped|locked), converted, saved, errors, message。
	 */
	public static function convert_attachment( $id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'force'        => false,
				'retry_failed' => false,
				'deadline'     => 0,
				'metadata'     => null,
				'force_token'  => '',
				'strict_deadline' => false, // true なら、1 組目からでも締め切りを守る（アップロード直後の即時変換用）。
			)
		);
		// 強制再変換には 1 回ごとに固有の札を付ける。この札を付け終えた分は作り直し済みとみなすので、
		// 途中で時間切れになっても続きから再開でき、同じ画像を何度も作り直すことがない。
		if ( $args['force'] && '' === (string) $args['force_token'] ) {
			$args['force_token'] = wp_generate_password( 10, false, false );
		}

		$result = array(
			'state'     => 'skipped',
			'converted' => 0,
			'saved'     => 0,
			'errors'    => array(),
			'message'   => '',
			'progress'  => 0,
			'force_token'  => (string) $args['force_token'],
		);

		if ( ! self::is_supported_attachment( $id ) ) {
			$result['message'] = 'unsupported_attachment';
			return $result;
		}
		if ( ! apply_filters( 'kashiwazaki_next_gen_images_should_convert_attachment', true, $id ) ) {
			$result['message'] = 'filtered';
			return $result;
		}
		$formats = self::active_formats();
		if ( empty( $formats ) ) {
			$result['message'] = 'no_format';
			return $result;
		}
		$files = self::source_files( $id, $args['metadata'] );
		if ( empty( $files ) ) {
			$result['message'] = 'no_file';
			return $result;
		}
		if ( ! self::lock( $id ) ) {
			$result['state']   = 'locked';
			$result['message'] = 'locked';
			return $result;
		}

		try {
			$result = self::run( $id, $files, $formats, $args, $result );
		} catch ( Throwable $e ) {
			// 公開のあとで例外が出た(所有記録を保存する前)。作った変換ファイルを片付ける。片付けの中で例外が出ても、鍵の解除(下)を飛ばさない。
			try {
				self::rollback_created( $result );
			} catch ( Throwable $e2 ) {
				self::$created_now = array();
			}
			$result['state']    = 'failed';
			$result['errors'][] = $e->getMessage();
		}
		self::unlock( $id );
		delete_transient( self::STATS_KEY );
		return $result;
	}

	/**
	 * この呼び出しで新しく公開した変換ファイルを片付け、数えた変換数・削減バイトも戻す。
	 *
	 * これらは、公開の直前に登録済みの名前でないと確かめて(process_pair。新しい名前は排他的に確保した上で、所有済みのファイルを置き換えるときも)公開したファイルなので、
	 * 片付けのときに登録確認をやり直さない(DB が不調のときに確認が失敗して、片付けが飛ばされるため)。
	 * disable_variant は、消せなければ空にし、空にもできなければ使わない一覧に載せる。
	 *
	 * @param array $result 結果(converted・saved を戻す)。
	 * @return void
	 */
	private static function rollback_created( array &$result ) {
		foreach ( self::$created_now as $cr ) {
			if ( is_file( $cr['path'] ) ) {
				self::disable_variant( $cr['path'] );
			}
			if ( isset( $result['converted'] ) ) {
				$result['converted'] = max( 0, (int) $result['converted'] - 1 );
			}
			if ( isset( $result['saved'] ) ) {
				$result['saved'] = max( 0, (int) $result['saved'] - (int) $cr['saved'] );
			}
		}
		self::$created_now = array();
	}

	/**
	 * 鍵を取った後の実処理。
	 *
	 * @param int   $id      添付ファイル ID。
	 * @param array $files   変換元。
	 * @param array $formats 形式。
	 * @param array $args    引数。
	 * @param array $result  結果の初期値。
	 * @return array
	 */
	private static function run( $id, array $files, array $formats, array $args, array $result ) {
		// 鍵を取った後に、古い post_meta のキャッシュを捨てて記録を読み直す（一括処理が先に読み込んだ古い記録で、別の処理が足した所有の記録を失わない）。
		wp_cache_delete( $id, 'post_meta' );
		$prev       = self::get_meta( $id );
		$prev_files = isset( $prev['files'] ) ? $prev['files'] : array();
		$new_files  = array();
		$unresolved = 0;
		$ok_count   = 0;
		$err_count  = 0;
		$deadline   = (float) $args['deadline'];
		$base       = self::uploads_basedir();
		self::$created_now = array(); // この呼び出しで新しく公開した変換ファイル。所有の記録を保存できなかったとき・例外で終わったとき、片付ける。
		$attempted  = 0; // この呼び出しで、実際に変換エンジンを動かした回数。1 回も動かさないまま時間切れにならないための基準。

		if ( ! empty( $files ) && 1 === wp_rand( 1, 20 ) ) {
			self::cleanup_stale_tmp( dirname( $files[0]['path'] ) );
		}

		foreach ( $files as $f ) {
			clearstatcache( true, $f['path'] );
			$src_size  = (int) filesize( $f['path'] );
			$src_mtime = (int) filemtime( $f['path'] );
			$old       = ( isset( $prev_files[ $f['rel'] ] ) && is_array( $prev_files[ $f['rel'] ] ) ) ? $prev_files[ $f['rel'] ] : array();
			$same_src  = ! empty( $old ) && (int) $old['src_size'] === $src_size && (int) $old['src_mtime'] === $src_mtime;

			$entry = array(
				'src_size'  => $src_size,
				'src_mtime' => $src_mtime,
			);
			// 元画像が同じなら前回の記録を引き継ぐ。
			if ( $same_src ) {
				foreach ( array( 'webp', 'avif', 'skip', 'ft', 'po' ) as $k ) {
					if ( isset( $old[ $k ] ) ) {
						$entry[ $k ] = $old[ $k ];
					}
				}
			} else {
				// 差し替わっていたら記録は捨てて作り直す。ただし「自分で作ったファイルだ」という事実は
				// 作り直しが終わるまで 'po' に残す（途中で止まっても、あとで消せなくなる事態を防ぐ）。
				foreach ( array( 'webp', 'avif' ) as $k ) {
					if ( ! empty( $old[ $k ]['owned'] ) || ! empty( $old['po'][ $k ] ) ) {
						$entry['po'][ $k ] = 1;
					}
				}
			}

			foreach ( $formats as $format ) {
				if ( ! self::pair_applies( $f['mime'], $format ) ) {
					continue;
				}
				$pair_args          = $args;
				$pair_args['force'] = $args['force'] && ( ! isset( $entry['ft'][ $format ] ) || (string) $entry['ft'][ $format ] !== (string) $args['force_token'] );

				// 次の 1 回（特に AVIF）が締め切りを越えそうなら、始めない。
				$reserve = ( 'avif' === $format ) ? 3.0 : 1.0;
				// ただし、この呼び出しでまだ 1 回も変換していなければ、締め切りを無視して 1 つは進める。
				// （締め切りが極端に短くても、毎回「何もできず時間切れ」にならないように。
				// すでに変換済みのペアを確認しただけでは、進んだことにしない。）
				if ( $deadline > 0 && ( $attempted > 0 || ! empty( $args['strict_deadline'] ) ) && ( microtime( true ) + $reserve ) >= $deadline ) {
					if ( $pair_args['force'] || ( ! isset( $entry[ $format ] ) && ! isset( $entry['skip'][ $format ] ) ) ) {
						++$unresolved;
					}
					// 元画像が差し替わっていたのに、時間切れでこの形式を作り直せない。旧画像から作った自作の変換は、その場で片付ける
					// （残すと、記録だけ新しい元画像のものになり、次の呼び出しで旧い変換を新しい画像の変換として採用してしまう）。
					if ( ! $same_src && self::owns_entry( $old, $format ) ) {
						$stale_variant = $f['path'] . '.' . $format;
						$stale_failed  = false;
						if ( is_file( $stale_variant ) ) {
							// 登録済み('yes')なら別の添付の本体なので触らない。確認の SQL が失敗('error')したときは、片付けられていないものとして扱う（記録を残して再試行する）。
							$stale_reg = self::registration_state( $stale_variant );
							if ( 'error' === $stale_reg ) {
								$stale_failed = true;
							} elseif ( 'no' === $stale_reg ) {
								$stale_failed = ( 'removed' !== self::disable_variant( $stale_variant ) );
							}
						}
						if ( $stale_failed ) {
							$entry['po'][ $format ] = 1; // 消せなかった。所有の記録は残して、次の呼び出しで再試行する。
						} else {
							unset( $entry['po'][ $format ] );
							if ( isset( $entry['po'] ) && empty( $entry['po'] ) ) {
								unset( $entry['po'] );
							}
						}
					}
					continue;
				}

				$owned     = self::owns_entry( $old, $format );
				$retain_po = false;
				$pair      = self::process_pair( $f, $format, $old, $same_src, $pair_args, $src_size, $src_mtime, $owned );
				if ( ! empty( $pair['attempted'] ) ) {
					++$attempted;
				}
				if ( $args['force'] ) {
					$entry['ft'][ $format ] = (string) $args['force_token'];
				}

				// 今回は作れなかったが、以前に自分で作ったファイルが残っている場合。
				// ただし skip が conflict のときは、片付けも再採用もしない。名前を別の処理が取った（変換の最中に作られた等）ので、そのファイルは他者のもの。
				// ファイルには触らず、所有の記録（owned・po）も持ち越さない（以後、他者のものとして扱う）。
				if ( ! isset( $pair['ok'] ) && $owned && ( ! isset( $pair['skip'] ) || 'conflict' !== $pair['skip'] ) ) {
					$variant = $f['path'] . '.' . $format;
					// 登録の確認が失敗('error')したときは、登録済みでも未登録でもない。片付けられていないものとして、所有の記録(po)を残す（次の呼び出しで再試行する）。
					// 確認は 1 回だけ行い、その結果で分岐する（2 回に分けると、2 回目だけ失敗したとき error を「登録済み」と読んで枝を飛ばし、po を落とす）。
					$reg_state = is_file( $variant ) ? self::registration_state( $variant ) : 'n/a';
					if ( 'error' === $reg_state ) {
						$retain_po = true;
					} elseif ( 'no' === $reg_state ) {
						$skip_code = isset( $pair['skip'] ) ? $pair['skip'] : '';
						// 元画像が同じで、cmyk でなく、更新時刻も元画像以上のときだけ残す。差し替わっていたら（$same_src が偽）、旧画像から作ったものなので残さない。
						// また、所有の記録が確定していない（po だけ。旧ファイルを消せなかった等で残っている）ものは、この元画像から作ったとは言えないので、残さない。
						if ( $same_src && ! empty( $old[ $format ]['owned'] ) && ! in_array( $skip_code, array( 'cmyk', 'icc', 'lossless_avif', 'lossless_unavailable' ), true ) && ! ( 'larger' === $skip_code && self::lossless_wanted( $f['mime'], $format ) ) && filesize( $variant ) > 0 && filemtime( $variant ) >= $src_mtime ) {
							// まだ有効。残して、所有の記録も維持する（記録が消えると、あとで削除できなくなる）。
							$pair = array(
								'ok'     => true,
								'size'   => (int) filesize( $variant ),
								'engine' => ! empty( $old[ $format ]['engine'] ) ? $old[ $format ]['engine'] : 'unknown',
								'owned'  => true,
							);
						} else {
							// 旧画像から作った自作の変換ファイルを片付ける。消せなければ、空にするか「使わない一覧」に載せて、配信させない。
							if ( 'removed' !== self::disable_variant( $variant ) ) {
								$retain_po = true;
								$pair      = array(
									'skip'    => 'write_failed',
									'message' => 'old variant could not be removed',
								);
							}
						}
					}
				}

				unset( $entry[ $format ] );
				if ( isset( $entry['po'][ $format ] ) ) {
					unset( $entry['po'][ $format ] );
					if ( empty( $entry['po'] ) ) {
						unset( $entry['po'] );
					}
				}
				// 旧ファイルを消せなかった・登録確認が失敗した場合は、所有の記録（po）を残して、次の変換で再試行できるようにする。
				if ( $retain_po || ! empty( $pair['keep_owned'] ) ) {
					$entry['po'][ $format ] = 1;
				}
				if ( isset( $entry['skip'][ $format ] ) ) {
					unset( $entry['skip'][ $format ] );
					if ( empty( $entry['skip'] ) ) {
						unset( $entry['skip'] );
					}
				}

				if ( isset( $pair['ok'] ) ) {
					$entry[ $format ] = array(
						'size'   => (int) $pair['size'],
						'engine' => (string) $pair['engine'],
						'owned'  => ! empty( $pair['owned'] ) ? 1 : 0,
					);
					++$ok_count;
					if ( ! empty( $pair['created'] ) ) {
						$saved_now           = max( 0, $src_size - (int) $pair['size'] );
						self::$created_now[] = array(
							'path'  => $f['path'] . '.' . $format,
							'saved' => $saved_now,
						);
						++$result['converted'];
						$result['saved'] += $saved_now;
					}
				} else {
					$entry['skip'][ $format ] = $pair['skip'];
					if ( in_array( $pair['skip'], self::RETRYABLE, true ) ) {
						++$err_count;
						$result['errors'][] = $f['rel'] . ' → ' . $format . ': ' . $pair['skip'] . ( ! empty( $pair['message'] ) ? ' (' . $pair['message'] . ')' : '' );
					}
				}
			}
			$new_files[ $f['rel'] ] = $entry;
		}

		// 今回の対象から外れた（変換しないサイズに追加された等）が、自分で作ったファイルが残っているものの記録は残す。
		// 記録を消すと、「すべて削除」やアンインストールでそのファイルを消せなくなる。
		if ( '' !== $base ) {
			foreach ( $prev_files as $rel => $pe ) {
				if ( isset( $new_files[ $rel ] ) || ! is_array( $pe ) || ! self::is_safe_relative_path( $rel ) ) {
					continue;
				}
				$keep = array();
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					if ( self::owns_entry( $pe, $fmt ) && is_file( $base . '/' . $rel . '.' . $fmt ) ) {
						if ( ! empty( $pe[ $fmt ]['owned'] ) ) {
							$keep[ $fmt ] = $pe[ $fmt ];
						} else {
							$keep['po'][ $fmt ] = 1;
						}
					}
				}
				if ( ! empty( $keep ) ) {
					$keep['src_size']  = isset( $pe['src_size'] ) ? (int) $pe['src_size'] : 0;
					$keep['src_mtime'] = isset( $pe['src_mtime'] ) ? (int) $pe['src_mtime'] : 0;
					$new_files[ $rel ] = $keep;
				}
			}
		}

		if ( $unresolved > 0 ) {
			$state = 'partial';
		} elseif ( $err_count > 0 && 0 === $ok_count ) {
			$state = 'failed';
		} else {
			$state = 'done';
		}

		if ( 'done' === $state ) {
			foreach ( $new_files as $rel => $entry ) {
				unset( $new_files[ $rel ]['ft'] ); // 再開用の札は、完了したら不要。
			}
		}

		$record = array(
			'v'       => 1,
			'state'   => $state,
			'time'    => time(),
			'formats' => $formats,
			'files'   => $new_files,
		);
		// 所有の記録を保存する。update_post_meta は、保存に失敗したときだけでなく、値が変わらなかったときも false を返すので、
		// false のときは保存された値を読み直して確かめる。保存できていなければ、変換は完了としない（実ファイルだけが残り、所有の記録が無い状態を、完了と報告しない）。
		if ( false === update_post_meta( $id, self::META_KEY, $record ) ) {
			wp_cache_delete( $id, 'post_meta' );
			$saved = get_post_meta( $id, self::META_KEY, true );
			if ( ! is_array( $saved ) || $saved != $record ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 配列の中身の比較。
				// 所有の記録を保存できなかった。公開済みの変換ファイルを残すと、次の実行で、所有記録のない他者のファイルとして採用され、
				// 強制再変換・一括削除・アンインストールで管理できなくなる。この実行で新しく公開したものを片付け、数えた変換数・削減バイトも戻す。
				self::rollback_created( $result );
				$result['state']    = 'failed';
				$result['errors'][] = 'could not save the conversion record';
				$result['progress'] = $attempted;
				return $result;
			}
		}

		self::$created_now  = array(); // 所有記録を保存できた。片付けの対象は無くなった。
		$result['state']    = $state;
		$result['progress'] = $attempted;
		return $result;
	}

	/**
	 * 1 ファイル × 1 形式を処理する。
	 *
	 * @param array  $f         変換元の情報。
	 * @param string $format    形式。
	 * @param array  $old       前回の記録。
	 * @param bool   $same_src  元画像が前回と同じか。
	 * @param array  $args      引数。
	 * @param int    $src_size  元のサイズ。
	 * @param int    $src_mtime 元の更新時刻。
	 * @param bool   $owned     この変換ファイルを、以前このプラグインが作ったか。
	 * @return array ok/size/engine/owned/created もしくは skip/message。
	 */
	private static function process_pair( array $f, $format, array $old, $same_src, array $args, $src_size, $src_mtime, $owned ) {
		$dest = $f['path'] . '.' . $format;

		// 「PNG・GIF は劣化なしで変換」のとき、AVIF は作らない: AVIF は劣化なしで書き出せず(画質の指定だけで、常に劣化する)、
		// 配信は AVIF を WebP より先に選ぶので、劣化なしの WebP があっても、AVIF 対応のブラウザには劣化した画像が出てしまう。
		// この見送りは設定に依る(毎回判定する)ので、記録された見送りの理由としては尊重しない(下)。すでにある自作の AVIF は、呼び出し元(run)が片付ける。
		if ( self::lossless_blocks_avif( $f['mime'], $format ) ) {
			return array( 'skip' => 'lossless_avif' );
		}

		// 前回「これは作らない」と決めた理由が今も有効なら、そのまま尊重する。
		// ただし、自分が所有している write_failed（旧ファイルを消せなかった等）は、権限が戻った後の通常の呼び出しでも再試行する。
		// lossless_avif は設定に依るので、ここまで来たなら(設定が変わって)もう有効ではない。作り直す。
		if ( $same_src && ! $args['force'] && isset( $old['skip'][ $format ] ) && 'lossless_avif' !== $old['skip'][ $format ] ) {
			$code        = $old['skip'][ $format ];
			$retry_owned = ( $owned && 'write_failed' === $code );
			if ( ! $retry_owned && ! ( $args['retry_failed'] && in_array( $code, self::RETRYABLE, true ) ) ) {
				return array( 'skip' => $code );
			}
		}

		// CMYK の JPEG は、GD と同じく変換しない（色がずれるため）。すでにある自作の変換ファイルは、呼び出し元（run）が片付ける。
		if ( 'image/jpeg' === $f['mime'] && Kashiwazaki_Next_Gen_Images_Engine::is_cmyk_jpeg( $f['path'] ) ) {
			return array( 'skip' => 'cmyk' );
		}

		clearstatcache( true, $dest );
		$exists = file_exists( $dest );
		$size   = $exists ? (int) filesize( $dest ) : 0;

		if ( $exists && $size > 0 ) {
			$fresh = filemtime( $dest ) >= $src_mtime;
			if ( $owned ) {
				// 自分が作ったファイル。記録した大きさと同じなら自分のものとみなして、登録の確認（SQL）を省く。
				// 違えば、別のファイルに置き換わった可能性があるので確認する。
				$recorded = isset( $old[ $format ]['size'] ) ? (int) $old[ $format ]['size'] : -1;
				if ( $recorded !== $size ) {
					$reg = self::registration_state( $dest );
					if ( 'yes' === $reg ) {
						return array(
							'skip'    => 'conflict',
							'message' => 'another attachment uses this file name',
						);
					}
					if ( 'error' === $reg ) {
						return array(
							'skip'       => 'write_failed',
							'message'    => 'registration check failed',
							'keep_owned' => true,
						);
					}
				}
				// 自分で作ったものは、強制再変換のときと、元画像が差し替わっていたときだけ作り直す。
				// 所有の記録(owned)が確定していない(po だけ。旧ファイルを消せなかった等で残っている)ものは、この元画像から作ったとは言えないので、採用せず作り直す。
				$outdated = ! $same_src || empty( $old[ $format ]['owned'] );
				if ( $fresh && ! $outdated && ! $args['force'] ) {
					// 新しい変換を作れたあと「使わない一覧」から外す保存に失敗していても、ここで毎回やり直す（載ったままだと配信されない）。
					self::unmark_stale( $dest );
					return array(
						'ok'     => true,
						'size'   => $size,
						'engine' => ! empty( $old[ $format ]['engine'] ) ? $old[ $format ]['engine'] : 'unknown',
						'owned'  => true,
					);
				}
			} else {
				// 他のプラグインなどが作ったファイル（所有の記録なし）。登録済みの別添付の本体ではないか確かめる。
				// 新しければそのまま使い、古ければ触らない（上書きしない）。
				$reg = self::registration_state( $dest );
				if ( 'yes' === $reg ) {
					return array(
						'skip'    => 'conflict',
						'message' => 'another attachment uses this file name',
					);
				}
				if ( 'error' === $reg ) {
					return array(
						'skip'    => 'write_failed',
						'message' => 'registration check failed',
					);
				}
				if ( $fresh ) {
					return array(
						'ok'     => true,
						'size'   => $size,
						'engine' => 'external',
						'owned'  => false,
					);
				}
				return array(
					'skip'    => 'conflict',
					'message' => 'a file made by something else exists and is older than the source',
				);
			}
		}

		// ここから先は dest に書き込む。別の添付ファイルの本体として登録されている名前なら、ファイルが無くても絶対に作らない。
		// その確認（SQL。確認が失敗したときも書かない）は、名前を確保した直後と rename の直前の 1 回だけ行う（下）。
		// 変換の前には確認しない: 新しく作る 1 組ごとに、メタデータ全体への LIKE 検索が 2 回走るのを 1 回にするため。
		// 登録済みの名前だったときは、変換を無駄に行うだけで、書き込みはしない。

		$dir = dirname( $f['path'] );
		if ( ! wp_is_writable( $dir ) ) {
			return array(
				'skip'    => 'write_failed',
				'message' => 'directory not writable',
			);
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < 5 * MB_IN_BYTES ) {
			return array(
				'skip'    => 'write_failed',
				'message' => 'low disk space',
			);
		}

		$settings = Kashiwazaki_Next_Gen_Images_Settings::get();
		$opts     = array(
			'quality'  => (int) ( 'webp' === $format ? $settings['webp_quality'] : $settings['avif_quality'] ),
			'lossless' => self::lossless_wanted( $f['mime'], $format ),
		);

		$tmp = $dest . '.' . wp_generate_password( 8, false ) . '.tmp';
		$res = Kashiwazaki_Next_Gen_Images_Engine::convert( $f['path'], $f['mime'], $tmp, $format, $opts );
		if ( is_wp_error( $res ) ) {
			Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
			return array(
				'skip'      => self::skip_code( $res->get_error_code() ),
				'message'   => $res->get_error_message(),
				'attempted' => true,
			);
		}

		clearstatcache( true, $tmp );
		$out_size = (int) filesize( $tmp );
		if ( ! empty( $settings['only_if_smaller'] ) && $out_size >= $src_size ) {
			Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
			return array(
				'skip'      => 'larger',
				'attempted' => true,
			);
		}

		// 元画像と同じ権限にそろえる（一時ファイルのままだと、サーバーによっては配信側から読めない）。
		$perms = @fileperms( $f['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $tmp, false !== $perms ? ( $perms & 0666 ) : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		// 変換先の名前が無いときは、先に空のファイルを排他的に作って名前を確保する（既にあれば失敗する。上書きしない）。
		// 確保した後で、もう一度登録を確認する。確保した名前は、WordPress のアップロードが既存の名前を避けるので、他の添付に取られにくい。
		$claimed = false;
		clearstatcache( true, $dest );
		if ( ! $exists && file_exists( $dest ) ) {
			// 開始のときには無かったのに、変換の最中に他の処理が同じ名前を作った。他者のファイルなので、上書きしない。
			Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
			return array(
				'skip'      => 'conflict',
				'message'   => 'a file appeared while converting',
				'attempted' => true,
			);
		}
		if ( ! file_exists( $dest ) ) {
			$handle = @fopen( $dest, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				clearstatcache( true, $dest );
				Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
				if ( file_exists( $dest ) ) {
					return array(
						'skip'      => 'conflict',
						'message'   => 'the file name was taken',
						'attempted' => true,
					);
				}
				return array(
					'skip'      => 'write_failed',
					'message'   => 'could not reserve the file name',
					'attempted' => true,
				);
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$claimed = true;
		}
		$reg = self::registration_state( $dest );
		if ( 'no' !== $reg ) {
			// 登録済み（または確認の失敗）。確保した空のファイルだけを片付ける（大きさが 0 でなければ、他の添付の本体なので消さない）。
			clearstatcache( true, $dest );
			if ( $claimed && 0 === (int) @filesize( $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
			if ( 'yes' === $reg ) {
				return array(
					'skip'      => 'conflict',
					'message'   => 'another attachment uses this file name',
					'attempted' => true,
				);
			}
			return array(
				'skip'       => 'write_failed',
				'message'    => 'registration check failed',
				'keep_owned' => (bool) $owned, // 所有していなかったファイル名に、所有の記録(po)を付けない。
				'attempted'  => true,
			);
		}

		// 変換の最中に元画像が差し替わっていないことを、公開の直前に確かめる(差し替え前の画像の変換を、新しい元画像の変換として公開しない。
		// 公開した変換ファイルの更新時刻は現在時刻になるので、そのまま公開すると、配信側の新しさの判定を通ってしまう)。差し替わっていたら公開せず、次の変換に任せる。
		clearstatcache( true, $f['path'] ); // filesize・filemtime は結果をキャッシュする(PHP マニュアル clearstatcache)。
		if ( (int) @filesize( $f['path'] ) !== (int) $src_size || (int) @filemtime( $f['path'] ) !== (int) $src_mtime ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
			clearstatcache( true, $dest );
			if ( $claimed && 0 === (int) @filesize( $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return array(
				'skip'       => 'write_failed',
				'message'    => 'the original image changed while converting',
				'keep_owned' => (bool) $owned,
				'attempted'  => true,
			);
		}

		// 完成してから本来の名前へ移す。途中まで書いたファイルが配信されることはない。
		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			// 移し先が塞がっている（Windows 系など）。未登録であることをもう一度確かめてから、消して移し直す。
			// 登録されている（または確認が失敗した）ときは、移し直さずに終わる（確認の拒否を、2 回目の rename で迂回しない）。
			if ( file_exists( $dest ) ) {
				$reg2 = self::registration_state( $dest );
				if ( 'no' !== $reg2 ) {
					Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
					clearstatcache( true, $dest );
					if ( $claimed && 0 === (int) @filesize( $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					}
					if ( 'yes' === $reg2 ) {
						return array(
							'skip'      => 'conflict',
							'message'   => 'another attachment uses this file name',
							'attempted' => true,
						);
					}
					return array(
						'skip'       => 'write_failed',
						'message'    => 'registration check failed',
						'keep_owned' => (bool) $owned,
						'attempted'  => true,
					);
				}
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				Kashiwazaki_Next_Gen_Images_Engine::remove_tmp( $tmp );
				clearstatcache( true, $dest );
				if ( $claimed && file_exists( $dest ) && 0 === (int) @filesize( $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return array(
					'skip'      => 'write_failed',
					'message'   => 'rename failed',
					'attempted' => true,
				);
			}
		}
		// 変換ファイルの更新時刻は元画像以上にしておく（古いファイルと見分けるため）。
		@touch( $dest, max( time(), $src_mtime ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		self::unmark_stale( $dest );
		self::variants_changed();

		return array(
			'ok'        => true,
			'size'      => $out_size,
			'engine'    => $res['engine'],
			'owned'     => true,
			'created'   => true,
			'attempted' => true,
		);
	}

	/**
	 * 作りかけのまま残った一時ファイル（プロセスが強制終了されたとき等）を片付ける。
	 *
	 * @param string $dir フォルダ。
	 * @return void
	 */
	private static function cleanup_stale_tmp( $dir ) {
		$list = glob( $dir . '/*.tmp' );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( $list as $file ) {
			// 名前と経過時間に加えて、添付ファイルとして登録されていない（登録の確認が 'no'）ことも確かめてから消す。
			// （インポーターなどが、この名前の画像を添付の本体として登録していることがある。確認が失敗したときも消さない。）
			if ( preg_match( '/\.(webp|avif)\.[A-Za-z0-9]{8}\.tmp$/', $file ) && ( time() - (int) filemtime( $file ) ) > HOUR_IN_SECONDS && 'no' === self::registration_state( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * エラーコードを記録用の理由コードにそろえる。
	 *
	 * @param string $code エンジンのエラーコード。
	 * @return string
	 */
	private static function skip_code( $code ) {
		$known = array( 'animated', 'too_large', 'memory', 'icc', 'cmyk', 'lossless_avif', 'lossless_unavailable', 'mime_mismatch', 'corrupt', 'decode', 'unsupported', 'missing', 'invalid_output', 'engine_error', 'write_failed', 'conflict' );
		return in_array( $code, $known, true ) ? $code : 'engine_error';
	}

	/**
	 * この絶対パスが、どこかの添付ファイルのファイルとして登録されているかを調べる。
	 *
	 * 変換ファイルを上書き・削除・作成する前の最後の安全確認。次のどれかに当たれば「登録済み」とみなす。
	 * - 添付ファイル本体（_wp_attached_file）
	 * - メタデータに載っているファイル名（各サイズ・縮小前の元画像 original_image・編集前のバックアップ）
	 * 同名の別フォルダのファイルにも当たることがあるが、その場合は消さない・上書きしない側に倒れるだけで安全。
	 *
	 * @param string $path 絶対パス。
	 * @return string 'yes'（登録済み）|'no'（未登録）|'error'（確認の SQL が失敗した）。
	 */
	public static function registration_state( $path ) {
		global $wpdb;
		$base = self::uploads_basedir();
		$path = wp_normalize_path( $path );
		if ( '' === $base || 0 !== strpos( $path, $base . '/' ) ) {
			return 'no';
		}
		$rel = substr( $path, strlen( $base ) + 1 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->last_error = '';
		$found            = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $rel ) );
		if ( '' !== $wpdb->last_error ) {
			return 'error';
		}
		if ( null !== $found ) {
			return 'yes';
		}
		// シリアライズされたメタデータ上では、ファイル名は「s:<バイト数>:"<名前>";」の形で入っている。
		$name   = wp_basename( $rel );
		$needle = 's:' . strlen( $name ) . ':"' . $name . '";';
		$found  = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $needle ) . '%' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( '' !== $wpdb->last_error ) {
			return 'error';
		}
		if ( null !== $found ) {
			return 'yes';
		}
		// 登録側が「2026//10/x.jpg.webp」「2026/./10/x.jpg.webp」のような別の表記で入っていると、上の完全一致に当たらない
		// （update_attached_file() は _wp_relative_upload_path() を通すが、ドットの要素や連続した / は除かない）。
		// 同じ名前で終わる登録だけを取り、`//` と `/./` を除いた形でそろえて比べる。
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wants = self::path_keys( $path );
		if ( empty( $wants ) ) {
			$wants = array( strtolower( self::canon_rel( $rel ) ) );
		}
		// 調べるファイルがリンクのとき、実体の名前(例: X.jpg.webp が Y.webp へのリンクなら Y.webp)で登録されている添付も探す。
		$names = array( $name );
		foreach ( $wants as $w ) {
			$names[] = wp_basename( $w );
		}
		$names = array_values( array_unique( $names ) );
		$cands = array();
		foreach ( $names as $nm ) {
			$got = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $wpdb->esc_like( $nm ) ) );
			if ( '' !== $wpdb->last_error ) {
				return 'error';
			}
			$cands = array_merge( $cands, (array) $got );
			if ( $nm !== $name ) {
				// 実体の名前が、別の添付の各サイズ・バックアップとして登録されていないかも調べる。
				$needle2 = 's:' . strlen( $nm ) . ':"' . $nm . '";';
				$found2  = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $needle2 ) . '%' ) );
				if ( '' !== $wpdb->last_error ) {
					return 'error';
				}
				if ( null !== $found2 ) {
					return 'yes';
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $cands as $cand ) {
			// 登録のパスの鍵(正規の形・実体のパス・末尾の相対パス)のどれかが、このファイルの相対パスと一致すれば登録済み。
			if ( array_intersect( $wants, self::reg_keys( $cand ) ) ) {
				return 'yes';
			}
		}
		// 登録の名前が変換ファイルの名前と違っても、ファイル自体がリンクで、実体がこのファイルになっている登録は、台帳(compute_conflicts)の実体の鍵に載っている。
		// 台帳が古い(汚れ印が新しい)ときは作り直し、確定できなかったときは「未登録」とは言えないので error（呼び出し側は、書かない・消さない側に倒れる）。
		$ledger = self::conflict_files();
		if ( self::ledger_unknown() ) {
			return 'error';
		}
		foreach ( $wants as $w ) {
			if ( isset( $ledger[ $w ] ) ) {
				return 'yes';
			}
		}
		return 'no';
	}

	/**
	 * 登録のパス(_wp_attached_file の値など)を、アップロードフォルダからの相対パスの正規の形にそろえる。
	 *
	 * - 絶対パスで登録されていれば(WordPress は、アップロードフォルダの文字列で始まらないパスを絶対パスのまま保存する)、
	 *   アップロードフォルダ(realpath の形も)を取り除く。取り除けない絶対パス(シンボリックリンク・別の表記の docroot)は、
	 *   最後の「/<アップロードフォルダ名>/」より後ろを相対パスとみなす。
	 * - 連続した / と「.」の要素を除き、「..」は前の要素と相殺する。
	 *
	 * @param string $value 登録のパス。
	 * @return string
	 */
	public static function canon_rel( $value ) {
		$v = wp_normalize_path( (string) $value );
		if ( '' !== $v && ( '/' === $v[0] || preg_match( '#^[A-Za-z]:/#', $v ) ) ) {
			$base = self::uploads_basedir();
			$rel  = null;
			if ( '' !== $base ) {
				if ( 0 === stripos( $v, $base . '/' ) ) {
					$rel = substr( $v, strlen( $base ) + 1 );
				} else {
					$rb = @realpath( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false !== $rb ) {
						$rb = wp_normalize_path( $rb );
						if ( 0 === stripos( $v, $rb . '/' ) ) {
							$rel = substr( $v, strlen( $rb ) + 1 );
						}
					}
				}
				if ( null === $rel ) {
					$marker = '/' . basename( $base ) . '/';
					$at     = strripos( $v, $marker );
					$rel    = ( false !== $at ) ? substr( $v, $at + strlen( $marker ) ) : ltrim( $v, '/' );
				}
			} else {
				$rel = ltrim( $v, '/' );
			}
			$v = $rel;
		}
		$out = array();
		foreach ( explode( '/', $v ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! empty( $out ) && '..' !== end( $out ) ) {
					array_pop( $out );
				} else {
					$out[] = '..';
				}
				continue;
			}
			$out[] = $part;
		}
		return implode( '/', $out );
	}

	/**
	 * アップロードフォルダの realpath（正規化済み）。リクエストの中で 1 回だけ解決する（登録の数だけ呼ばれるため）。
	 *
	 * @param string $base アップロードフォルダ。
	 * @return string|false
	 */
	private static function real_base( $base ) {
		static $cache = array();
		if ( ! array_key_exists( $base, $cache ) ) {
			$rb            = @realpath( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$cache[ $base ] = ( false !== $rb ) ? wp_normalize_path( $rb ) : false;
		}
		return $cache[ $base ];
	}

	/**
	 * いま調べているファイル(アップロードフォルダの中の絶対パス)の「アップロードフォルダからの相対パス」の鍵(小文字)を返す。
	 *
	 * 登録のパス側(reg_keys)だけが実体を解決すると、調べる側がシンボリックリンク経由の表記のとき(例: uploads/alias が uploads/2026/10 を指す)、
	 * 別の添付が実体の側の表記で登録されていても一致しない。そこで、調べる側も次の鍵を持つ。
	 * - 文字列の正規の形(canon_rel)
	 * - 実体のパス: ファイルがあれば realpath、無ければ「フォルダの realpath ＋ ファイル名」(これから作るファイルの名前でも、フォルダのリンクを解決できる)。
	 *
	 * @param string $abs   絶対パス。
	 * @param bool   $lower 真なら小文字の鍵(台帳用)。偽なら大文字小文字を保つ(使わない一覧用)。
	 * @return array 鍵の配列。
	 */
	public static function path_keys( $abs, $lower = true ) {
		$v    = wp_normalize_path( (string) $abs );
		$fold = $lower ? 'strtolower' : 'strval'; // 台帳は小文字(登録は DB の照合で大文字小文字を区別しない)。使わない一覧は大文字小文字を保つ(Linux のファイルは区別する)。
		$base = self::uploads_basedir();
		$keys = array();
		if ( '' === $base || '' === $v ) {
			return $keys;
		}
		if ( 0 === stripos( $v, $base . '/' ) ) {
			$keys[] = $fold( self::canon_rel( substr( $v, strlen( $base ) + 1 ) ) );
		}
		$rb = @realpath( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $rb ) {
			$rb = wp_normalize_path( $rb );
			$rf = @realpath( $v ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $rf ) {
				$rd = @realpath( dirname( $v ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$rf = ( false !== $rd ) ? $rd . '/' . basename( $v ) : false;
			}
			if ( false !== $rf ) {
				$rf = wp_normalize_path( $rf );
				if ( 0 === stripos( $rf, $rb . '/' ) ) {
					$keys[] = $fold( substr( $rf, strlen( $rb ) + 1 ) );
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * 登録のパス(_wp_attached_file の値など)が指し得る「アップロードフォルダからの相対パス」の鍵(小文字)を全部返す。
	 *
	 * 文字列の正規化(canon_rel)だけでは、同じ物理ファイルを指す別の書き方を取りこぼすので、次を足す。
	 * - 実体のパス: そのパスのファイルが実在すれば、realpath(シンボリックリンク・「..」を解決した本当の場所)をアップロードフォルダ(realpath)からの相対にした鍵。
	 * - 末尾の相対パス: 絶対パスでアップロードフォルダの表記が合わないもの(別の docroot の表記・UPLOADS を変える前の旧い場所・アップロードフォルダの外を指す「../」)は、
	 *   どこがアップロードフォルダの位置か分からないので、パスの末尾のすべての区切り(1 要素以上)を鍵にする。過剰に「登録済み」と見なす側(消さない・上書きしない・配信しない)に倒れる。
	 *
	 * @param string $value 登録のパス。
	 * @return array 鍵の配列。
	 */
	public static function reg_keys( $value ) {
		$canon = self::canon_rel( $value );
		$keys  = array( strtolower( $canon ) );
		$v     = wp_normalize_path( (string) $value );
		$base  = self::uploads_basedir();
		$is_abs = ( '' !== $v && ( '/' === $v[0] || preg_match( '#^[A-Za-z]:/#', $v ) ) );
		$rb     = ( '' !== $base ) ? self::real_base( $base ) : false;
		$under  = false;
		if ( $is_abs && '' !== $base ) {
			$under = ( 0 === stripos( $v, $base . '/' ) ) || ( false !== $rb && 0 === stripos( $v, $rb . '/' ) );
		}
		// 実体のパス。
		if ( '' !== $base && '' !== $v && false !== $rb ) {
			$rp = @realpath( $is_abs ? $v : $base . '/' . $v ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $rp ) {
				$rp = wp_normalize_path( $rp );
				if ( 0 === stripos( $rp, $rb . '/' ) ) {
					$keys[] = strtolower( substr( $rp, strlen( $rb ) + 1 ) );
				}
			}
		}
		// 末尾の相対パス(アップロードフォルダの位置が分からない表記)。
		if ( ( $is_abs && ! $under ) || '..' === $canon || 0 === strpos( $canon, '../' ) ) {
			$seg = explode( '/', $canon );
			$n   = count( $seg );
			for ( $i = 1; $i < $n; $i++ ) {
				if ( '..' === $seg[ $i ] || '' === $seg[ $i ] ) {
					continue;
				}
				$keys[] = strtolower( implode( '/', array_slice( $seg, $i ) ) );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * この絶対パスが、どこかの添付ファイルのファイルとして登録されているか（確認が失敗したときも、触らない側に倒して true）。
	 *
	 * @param string $path 絶対パス。
	 * @return bool
	 */
	public static function is_registered_file( $path ) {
		return 'no' !== self::registration_state( $path );
	}

	/**
	 * 「<元画像名>.webp」のような、変換ファイルと同じ形の名前を、自分の本体として持つ添付ファイルの一覧。
	 *
	 * 通常は空。表示のたびに DB を調べずに済むよう、結果を自動読み込みのオプションに保存してある。
	 * 該当する名前の画像（例: 「photo.jpg.webp」をアップロードした）がある場合、同名の別画像
	 * 「photo.jpg」の変換ファイルとして誤って配信しないために使う。
	 *
	 * @return array 小文字にしたアップロードフォルダからの相対パスをキーにした配列。
	 */
	public static function conflict_files() {
		self::$ledger_unknown = false; // 呼ぶたびに評価し直す(一度確定できなかっただけで、そのリクエストの残りが error になり続けないように)。
		$set                  = get_option( self::CONFLICT_OPTION, null );
		$dirty = (float) get_option( self::LEDGER_DIRTY_OPTION, 0 );
		$stamp = ( is_array( $set ) && isset( $set[ self::LEDGER_STAMP_KEY ] ) ) ? (float) $set[ self::LEDGER_STAMP_KEY ] : 0.0;
		// 台帳が無い、または台帳の計算開始より新しい「汚れ印」がある（その計算に含まれていない変更があるかもしれない）ときは、作り直す。
		if ( ! is_array( $set ) || ( $dirty > 0 && $dirty > $stamp ) ) {
			$fresh = self::refresh_conflicts( 1, true ); // 表示の途中なので、ロックは長く待たない。自分では変更を持たない読み手。
			if ( is_array( $fresh ) ) {
				$set = $fresh;
			} else {
				// 台帳を確定できなかった（ロックが取れない・SQL の失敗）。いまある台帳（あれば）も古いと分かっている。「衝突なし」とは言えないので、
				// このリクエストでは、変換ファイルを配信しない側に倒す（配信側が ledger_unknown() を見て、元画像のままにする）。
				self::$ledger_unknown = true;
				if ( ! is_array( $set ) ) {
					$set = array();
				}
			}
		}
		return $set;
	}

	/**
	 * 台帳を確定できなかったか（このリクエストで conflict_files() が、台帳を作れなかった）。真なら、配信側は変換ファイルを使わない。
	 *
	 * @return bool
	 */
	public static function ledger_unknown() {
		return self::$ledger_unknown;
	}

	/**
	 * 台帳を DB の真実から作り直して保存する。名前付きロックの中で、ロックを取った後に計算するので、
	 * 同時の更新があっても、古い計算結果が後から保存されることはない。
	 *
	 * @param int  $wait   ロックを待つ秒数。
	 * @param bool $reader 真なら、表示の途中で台帳が古いと分かって作り直す読み手（自分では変更を持たない）。
	 *                     読み手は、ロックが取れたら先に台帳を DB から読み直し、すでに新しければ計算しない。
	 *                     ロックが取れなくても、再計算できなくても、汚れ印を付けない（付けると、作り直し中の処理の保存した台帳を
	 *                     保存した瞬間に古いと判定させ、作り直しが止まらなくなる）。
	 * @return array|null 作り直した台帳。ロックが取れない・SQL が失敗したときは null（台帳は変えない）。
	 */
	public static function refresh_conflicts( $wait = 5, $reader = false ) {
		$result = self::with_lock(
			'ledger',
			function () use ( $reader ) {
				if ( $reader ) {
					// ロックを待っている間に、ほかの処理が作り直し終えているかもしれない。DB から読み直し、新しければ計算しない。
					list( $cur, $dirty, $read_ok ) = self::read_ledger_row();
					// 読み取りに失敗したとき(汚れ印だけ読めなかった等)は、台帳が新しいとは判断せず、計算し直す。
					if ( $read_ok && is_array( $cur ) && ! ( $dirty > 0 && $dirty > (float) ( isset( $cur[ self::LEDGER_STAMP_KEY ] ) ? $cur[ self::LEDGER_STAMP_KEY ] : 0 ) ) ) {
						return $cur;
					}
				}
				// 計算を始める時刻を、台帳に刻む。これより後に付いた「汚れ印」は、この計算に含まれていない変更があることを示す。
				$t0  = microtime( true );
				$set = self::compute_conflicts();
				if ( null !== $set ) {
					$set[ self::LEDGER_STAMP_KEY ] = $t0;
				}
				if ( null === $set || ! self::write_option_row( self::CONFLICT_OPTION, $set ) ) {
					if ( ! $reader ) {
						self::mark_ledger_dirty(); // 再計算できなかった・保存できなかった。いまある台帳は古いかもしれない。
					}
					return false;
				}
				return $set;
			},
			$wait
		);
		if ( null === $result && ! $reader ) {
			// ロックを取れなかった（別の処理が台帳を作り直している最中）。その処理は、こちらの変更より前の状態を読んでいるかもしれないので、
			// 保存される台帳は古いかもしれない。汚れ印を付ける（台帳の計算開始時刻より新しい印があれば、読む側が作り直す）。
			// 台帳の option 行を捨てる方式では、ロックを持っていた処理が古い計算結果を後から保存すると、古い台帳が復活する。
			self::mark_ledger_dirty();
		}
		return is_array( $result ) ? $result : null;
	}

	/**
	 * 台帳とその汚れ印を、キャッシュを使わず DB から直接読む。
	 *
	 * @return array array( 台帳(配列。無ければ null), 汚れ印(float。無ければ 0), 読み取りが両方成功したか(bool) )。
	 *               SELECT が失敗したときは第 3 要素が false（呼び出し側は、台帳が新しいとは判断しない）。
	 */
	private static function read_ledger_row() {
		global $wpdb;
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::CONFLICT_OPTION, 'options' );
		wp_cache_delete( self::LEDGER_DIRTY_OPTION, 'options' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->last_error = '';
		$raw              = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::CONFLICT_OPTION ) );
		$ok               = ( '' === $wpdb->last_error );
		$wpdb->last_error = '';
		$dirty            = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LEDGER_DIRTY_OPTION ) );
		$ok               = $ok && ( '' === $wpdb->last_error );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		$set = ( null !== $raw ) ? maybe_unserialize( $raw ) : null;
		return array( is_array( $set ) ? $set : null, (float) $dirty, $ok );
	}

	/**
	 * 台帳に「汚れ印」を付ける（いまの時刻を、専用の option に書く）。台帳の計算開始時刻より新しい印は、台帳が古いかもしれないことを示す。
	 *
	 * @return void
	 */
	private static function mark_ledger_dirty() {
		// 時刻を取ってから書くまでの間に別の処理がより新しい印を書いていても、古い時刻で上書きしない（新しい印が消えると、台帳が古いままになる）。
		if ( self::write_option_row( self::LEDGER_DIRTY_OPTION, sprintf( '%.6F', microtime( true ) ), 'yes', true ) ) {
			return;
		}
		// 汚れ印を書けなかった。次善の策として、古いかもしれない台帳の option 行を直接削除する（読む側は台帳が無いものとして作り直し、作れなければ配信しない側に倒す）。
		// これも失敗するのは、DB への書き込みが全部失敗している状態で、R8 の延長として受け入れる。
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::CONFLICT_OPTION ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::CONFLICT_OPTION, 'options' );
	}

	/**
	 * 変換ファイルと同じ形の名前を持つ登録済みファイルを、DB から探す（保存はしない）。
	 * 添付のディレクトリも、リクエスト内のキャッシュを使わず、DB から直接読む。
	 *
	 * @return array|null SQL が失敗したときは null。
	 */
	private static function compute_conflicts() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->last_error = '';
		$set              = array();
		$rows             = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value REGEXP %s", '[.](jpe?g|jpe|png|gif|webp)[.](webp|avif)$' ) );
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		foreach ( (array) $rows as $rel ) {
			foreach ( self::reg_keys( $rel ) as $key ) {
				$set[ $key ] = 1;
			}
		}
		// 登録された本体のうち、ファイル自体がシンボリックリンクなどで、実体の場所が登録の名前と違うもの(例: 登録は 2026/10/b.webp や b.jpg、実体は x.jpg.webp へのリンク)。
		// 変換ファイルと同じ形の名前ではないので上の検索には掛からない。実体の側の鍵を台帳に載せる(拡張子は問わない)。1000 件ずつ読む。
		$after_main = 0;
		do {
			$alias_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_id > %d ORDER BY meta_id ASC LIMIT 1000", $after_main ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( '' !== $wpdb->last_error ) {
				return null;
			}
			foreach ( (array) $alias_rows as $arow ) {
				$after_main = max( $after_main, (int) $arow->meta_id );
				$ks         = self::reg_keys( $arow->meta_value );
				if ( count( $ks ) > 1 ) {
					foreach ( $ks as $key ) {
						$set[ $key ] = 1; // 登録の名前と実体が違うものだけ(同じなら鍵は 1 つ)。
					}
				}
			}
		} while ( 1000 === count( (array) $alias_rows ) );
		// 登録された各サイズ・original_image・バックアップの .webp / .avif のうち、名前と実体が違うもの（リンクなど）の実体の鍵。
		$after = 0;
		do {
			// 一度に大量の行をメモリに読まないよう、meta_id の順に 300 件ずつ読む。
			$meta_alias_rows = $wpdb->get_results( $wpdb->prepare( "SELECT md.meta_id AS mid, mf.meta_value AS main_file, md.meta_value AS metadata FROM {$wpdb->postmeta} md INNER JOIN {$wpdb->postmeta} mf ON mf.post_id = md.post_id AND mf.meta_key = '_wp_attached_file' WHERE md.meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND md.meta_value REGEXP %s AND md.meta_id > %d ORDER BY md.meta_id ASC LIMIT 300", '"[^"]*[.](webp|avif)";', $after ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( '' !== $wpdb->last_error ) {
				return null;
			}
			foreach ( (array) $meta_alias_rows as $row ) {
				$after = max( $after, (int) $row->mid );
				$m     = maybe_unserialize( $row->metadata );
				if ( ! is_array( $m ) || '' === (string) $row->main_file ) {
					continue;
				}
				$d = dirname( (string) $row->main_file );
				foreach ( self::meta_webp_avif_names( $m ) as $name ) {
					$ks = self::reg_keys( ( '.' === $d ? '' : $d . '/' ) . $name );
					if ( count( $ks ) > 1 ) {
						foreach ( $ks as $key ) {
							$set[ $key ] = 1;
						}
					}
				}
			}
		} while ( 300 === count( (array) $meta_alias_rows ) );
		// メタデータ（縮小前の元画像・各サイズ）と、画像編集前のバックアップ（_wp_attachment_backup_sizes）に載っているファイル名のうち、変換ファイルと同じ形のもの。
		// 文字列は「s:<バイト数>:"<名前>";」の形でシリアライズされているので、変換ファイルと同じ形で終わる文字列を持つ行だけを REGEXP で選ぶ。
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT mf.meta_value AS main_file, md.meta_value AS metadata FROM {$wpdb->postmeta} md INNER JOIN {$wpdb->postmeta} mf ON mf.post_id = md.post_id AND mf.meta_key = '_wp_attached_file' WHERE md.meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND md.meta_value REGEXP %s", '"[^"]*[.](jpe?g|jpe|png|gif|webp)[.](webp|avif)";' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		foreach ( (array) $rows as $row ) {
			$meta = maybe_unserialize( $row->metadata );
			$main = (string) $row->main_file;
			if ( ! is_array( $meta ) || '' === $main ) {
				continue;
			}
			$dir = dirname( $main );
			foreach ( self::meta_variant_like_names( $meta ) as $name ) {
				foreach ( self::reg_keys( ( '.' === $dir ? '' : $dir . '/' ) . $name ) as $key ) {
					$set[ $key ] = 1;
				}
			}
		}
		return $set;
	}

	/**
	 * メタデータ（_wp_attachment_metadata）または画像編集前のバックアップ（_wp_attachment_backup_sizes）の中の、
	 * 変換ファイルと同じ形のファイル名（縮小前の元画像 original_image・各サイズの file・バックアップの file）。
	 *
	 * @param array $meta メタの値。
	 * @return array ファイル名（ディレクトリを含まない）の配列。
	 */
	private static function meta_variant_like_names( array $meta ) {
		$names = array();
		$add   = function ( $name ) use ( &$names ) {
			if ( is_string( $name ) && '' !== $name && false === strpos( $name, '/' ) && self::looks_like_variant_name( $name ) ) {
				$names[] = $name;
			}
		};
		$add( isset( $meta['original_image'] ) ? $meta['original_image'] : null );
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				$add( ( is_array( $size ) && isset( $size['file'] ) ) ? $size['file'] : null );
			}
		}
		// バックアップは「サイズ名 => array( file => … )」の形。
		foreach ( $meta as $key => $entry ) {
			if ( 'sizes' !== $key && 'original_image' !== $key && is_array( $entry ) && isset( $entry['file'] ) ) {
				$add( $entry['file'] );
			}
		}
		return $names;
	}

	/**
	 * メタデータ（_wp_attachment_metadata）または画像編集前のバックアップの中の、.webp / .avif のファイル名（ディレクトリを含まない）の全部。
	 * 縮小前の元画像 original_image・各サイズの file・バックアップの file。変換ファイルと同じ形の名前でなくても拾う（リンクで登録された各サイズの実体を調べるため）。
	 *
	 * @param array $meta メタの値。
	 * @return array ファイル名の配列。
	 */
	private static function meta_webp_avif_names( array $meta ) {
		$names = array();
		$add   = function ( $name ) use ( &$names ) {
			if ( is_string( $name ) && '' !== $name && false === strpos( $name, '/' ) && preg_match( '/\.(webp|avif)$/i', $name ) ) {
				$names[] = $name;
			}
		};
		$add( isset( $meta['original_image'] ) ? $meta['original_image'] : null );
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				$add( ( is_array( $size ) && isset( $size['file'] ) ) ? $size['file'] : null );
			}
		}
		foreach ( $meta as $key => $entry ) {
			if ( 'sizes' !== $key && 'original_image' !== $key && is_array( $entry ) && isset( $entry['file'] ) ) {
				$add( $entry['file'] );
			}
		}
		return $names;
	}

	/**
	 * その添付のメタデータ・バックアップに、名前と実体が違う（リンク・絶対パス・「..」）.webp / .avif のファイル名があるか。真なら、台帳を作り直す。
	 *
	 * @param int   $post_id 添付ファイル ID。
	 * @param array $meta    メタの値。
	 * @return bool
	 */
	private static function meta_has_alias_files( $post_id, array $meta ) {
		$names = self::meta_webp_avif_names( $meta );
		if ( array() === $names ) {
			return false;
		}
		$main = get_post_meta( $post_id, '_wp_attached_file', true );
		$dir  = is_string( $main ) ? dirname( $main ) : '.';
		foreach ( $names as $name ) {
			if ( count( self::reg_keys( ( '.' === $dir ? '' : $dir . '/' ) . $name ) ) > 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 台帳を DB から作り直す（以前の名前。refresh_conflicts を呼ぶ）。
	 *
	 * @return array
	 */
	public static function rebuild_conflicts() {
		$set = self::refresh_conflicts();
		if ( is_array( $set ) ) {
			return $set;
		}
		$current = get_option( self::CONFLICT_OPTION, array() );
		return is_array( $current ) ? $current : array();
	}

	/**
	 * 変換ファイルと同じ形の名前か。
	 *
	 * @param string $rel 相対パス。
	 * @return bool
	 */
	private static function looks_like_variant_name( $rel ) {
		return (bool) preg_match( '/\.(jpe?g|jpe|png|gif|webp)\.(webp|avif)$/i', (string) $rel );
	}

	/**
	 * その添付のメタデータ・画像編集前のバックアップに、変換ファイルと同じ形の名前（縮小前の元画像・各サイズ・バックアップ）があるか。
	 *
	 * @param int $post_id 添付ファイル ID。
	 * @return bool
	 */
	private static function has_alias_meta_files( $post_id ) {
		foreach ( array( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) as $key ) {
			$meta = get_post_meta( $post_id, $key, true );
			if ( is_array( $meta ) && self::meta_has_alias_files( $post_id, $meta ) ) {
				return true;
			}
		}
		return false;
	}
	private static function has_variant_like_original( $post_id ) {
		foreach ( array( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) as $key ) {
			$meta = get_post_meta( $post_id, $key, true );
			if ( is_array( $meta ) && array() !== self::meta_variant_like_names( $meta ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 台帳が空でないか（空でなければ、メタの変更で旧い項目が消えるかもしれないので、台帳を作り直す理由になる）。
	 * 台帳がまだ無い（初回の計算前）ときは false。表示のときに計算される。
	 *
	 * @return bool
	 */
	/**
	 * 登録のパスが .webp / .avif で、登録の名前と実体の場所が違う(シンボリックリンク・絶対パス・「..」など)か。真なら、台帳に実体の鍵を足すため、台帳を作り直す。
	 *
	 * @param mixed $value 登録のパス。
	 * @return bool
	 */
	private static function is_alias_registration( $value ) {
		return is_string( $value ) && '' !== $value && count( self::reg_keys( $value ) ) > 1;
	}

	private static function ledger_has_entries() {
		$set = get_option( self::CONFLICT_OPTION, null );
		if ( ! is_array( $set ) ) {
			return false;
		}
		unset( $set[ self::LEDGER_STAMP_KEY ] ); // 計算開始時刻の印は、項目に数えない。
		return array() !== $set;
	}

	/**
	 * 台帳を作り直す理由があるか: 値が変換ファイルと同じ形の名前を含む、その添付に同じ形の名前が残っている、または台帳が空でない。
	 *
	 * @param int   $post_id 添付ファイル ID。
	 * @param mixed $value   保存・削除されたメタの値。
	 * @return bool
	 */
	private static function needs_ledger_refresh( $post_id, $value ) {
		if ( is_string( $value ) && ( self::looks_like_variant_name( $value ) || self::is_alias_registration( $value ) ) ) {
			return true;
		}
		if ( is_array( $value ) && ( array() !== self::meta_variant_like_names( $value ) || self::meta_has_alias_files( $post_id, $value ) ) ) {
			return true;
		}
		// 本体のパスが変わると、保存済みの各サイズの置き場所も変わる。そのとき、各サイズが名前と実体の違う .webp / .avif になっていないか。
		if ( self::has_alias_meta_files( $post_id ) ) {
			return true;
		}
		return self::ledger_has_entries() || self::has_variant_like_original( $post_id );
	}

	/**
	 * 追加された添付ファイルが変換ファイルと同じ形の名前なら、台帳を作り直す。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return void
	 */
	public static function note_attachment( $id ) {
		$rel = get_post_meta( $id, '_wp_attached_file', true );
		if ( ( is_string( $rel ) && ( self::looks_like_variant_name( $rel ) || self::is_alias_registration( $rel ) ) ) || self::has_variant_like_original( $id ) || self::has_alias_meta_files( $id ) ) {
			self::refresh_conflicts();
		}
	}

	/**
	 * メタデータ・バックアップに変換ファイルと同じ形の名前が載っていれば（または台帳が空でなければ）、台帳を作り直す。
	 *
	 * @param int   $id       添付ファイル ID。
	 * @param array $metadata メタデータ。
	 * @return void
	 */
	public static function note_metadata( $id, $metadata ) {
		if ( self::needs_ledger_refresh( $id, $metadata ) ) {
			self::refresh_conflicts();
		}
	}

	/**
	 * 添付ファイルのメタ（_wp_attachment_metadata・_wp_attached_file・_wp_attachment_backup_sizes）が保存されたあとの処理。
	 * 保存済みの値から台帳を作り直すので、保存前の値を扱う心配が無い。
	 *
	 * @param int    $meta_id    メタ ID。
	 * @param int    $post_id    添付ファイル ID。
	 * @param string $meta_key   キー。
	 * @param mixed  $meta_value 値。
	 * @return void
	 */
	public static function on_meta_saved( $meta_id, $post_id, $meta_key, $meta_value ) {
		try {
			if ( in_array( $meta_key, array( '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes' ), true ) && self::needs_ledger_refresh( $post_id, $meta_value ) ) {
				self::refresh_conflicts();
			}
		} catch ( Throwable $e ) {
			unset( $e ); // 台帳は次の機会に直る。保存を妨げない。
		}
	}

	/**
	 * 添付ファイルのメタが削除されたあとの処理（deleted_post_meta。引数は WordPress の定義どおり）。
	 * 削除された値は台帳に載っていたかもしれないので、台帳が空でなければ作り直す。
	 *
	 * @param array|int $meta_ids   削除したメタ ID。
	 * @param int       $post_id    添付ファイル ID。
	 * @param string    $meta_key   キー。
	 * @param mixed     $meta_value 削除したメタの値（指定があったときだけ）。
	 * @return void
	 */
	public static function on_meta_deleted( $meta_ids, $post_id, $meta_key, $meta_value ) {
		try {
			if ( in_array( $meta_key, array( '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes' ), true ) && self::needs_ledger_refresh( $post_id, $meta_value ) ) {
				self::refresh_conflicts();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * 添付ファイルが削除される直前に、削除の後で台帳を作り直すかを控える（この時点ではメタがまだ残っている）。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return void
	 */
	public static function before_delete_attachment( $id ) {
		$main = get_post_meta( $id, '_wp_attached_file', true );
		if ( ( is_string( $main ) && ( self::looks_like_variant_name( $main ) || self::is_alias_registration( $main ) ) ) || self::has_variant_like_original( $id ) || self::has_alias_meta_files( $id ) || self::ledger_has_entries() ) {
			self::$pending_refresh[ (int) $id ] = true;
		}
		// 元ファイルの削除に連動して消すのは、このプラグインが作った(所有の記録がある)変換ファイルだけ。
		// WordPress は添付のメタを先に消してからファイルを消すので、所有の記録を、メタが消える前のここで控える。
		$base = self::uploads_basedir();
		$meta = self::get_meta( (int) $id );
		if ( '' !== $base && ! empty( $meta['files'] ) && is_array( $meta['files'] ) ) {
			foreach ( $meta['files'] as $rel => $entry ) {
				if ( ! is_string( $rel ) || ! self::is_safe_relative_path( $rel ) || ! is_array( $entry ) ) {
					continue;
				}
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					if ( self::owns_entry( $entry, $fmt ) ) {
						self::$owned_on_delete[ wp_normalize_path( $base . '/' . $rel . '.' . $fmt ) ] = true;
					}
				}
			}
		}
	}

	/**
	 * 添付ファイルが削除された後に、控えがあれば台帳を作り直す。
	 *
	 * @param int $id 投稿 ID。
	 * @return void
	 */
	public static function after_delete_post( $id ) {
		$id = (int) $id;
		if ( isset( self::$pending_refresh[ $id ] ) ) {
			unset( self::$pending_refresh[ $id ] );
			try {
				self::refresh_conflicts();
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * 「使わない変換ファイルの一覧」（消せなかった旧い変換ファイル。台帳と同じ形）。
	 *
	 * @return array
	 */
	public static function stale_files() {
		$set = get_option( self::STALE_OPTION, array() );
		return is_array( $set ) ? $set : array();
	}

	/**
	 * アップロードフォルダからの小文字の相対パスにする（一覧のキー）。
	 *
	 * @param string $path 絶対パス。
	 * @return string アップロードフォルダの外なら空。
	 */
	private static function stale_key( $path ) {
		$base = self::uploads_basedir();
		$norm = wp_normalize_path( $path );
		if ( '' === $base || 0 !== strpos( $norm, $base . '/' ) ) {
			return '';
		}
		// 登録のパスが「2026/./10/x.jpg」のような表記でも、配信側が照合する正規の形(map_url が「//」「/./」を除く)と同じキーにそろえる。
		return self::canon_rel( substr( $norm, strlen( $base ) + 1 ) ); // 大文字小文字を保つ(Linux では A.jpg と a.jpg は別のファイル)。
	}

	/**
	 * 一覧の行を DB から直接読む（キャッシュを使わない）。
	 *
	 * @return array
	 */
	private static function read_stale_row() {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::STALE_OPTION ) );
		if ( '' !== $wpdb->last_error ) {
			return null; // 読めなかった。空の一覧として扱って上書きすると、既にある一覧を失う。
		}
		$set = ( null === $raw ) ? array() : maybe_unserialize( $raw );
		return is_array( $set ) ? $set : array();
	}

	/**
	 * 消せなかった変換ファイルを「使わない一覧」に載せる（配信側が使わなくなる）。
	 *
	 * @param string $path 変換ファイルの絶対パス。
	 * @return bool 載せられたら true（ロックが取れなければ false）。
	 */
	public static function mark_stale( $path ) {
		$key = self::stale_key( $path );
		if ( '' === $key ) {
			return false;
		}
		// 文字列の鍵に加えて、実体(シンボリックリンクを解決した場所)の鍵でも載せる。配信側は URL の文字列の鍵と実体の鍵の両方で引くので、
		// 別名(uploads/alias → uploads/2026/10)経由で載せても、実体の側の URL で読まれたときに旧い変換が配信されない。
		$keys = array_values( array_unique( array_merge( array( $key ), self::path_keys( $path, false ) ) ) );
		$ok   = self::with_lock(
			'stale',
			function () use ( $keys ) {
				$set = self::read_stale_row();
				if ( null === $set ) {
					return false; // 一覧を読めなかった。書かない（既にある一覧を、今回の 1 件だけで上書きしない）。
				}
				foreach ( $keys as $k ) {
					$set[ $k ] = 1;
				}
				return self::write_option_row( self::STALE_OPTION, $set );
			}
		);
		self::variants_changed();
		return true === $ok;
	}

	/**
	 * 「使わない一覧」から外す（新しい変換ファイルを作れたとき・旧ファイルが消えたとき）。
	 *
	 * @param string $path 変換ファイルの絶対パス。
	 * @return bool 外せた（または元から載っていない）なら true。保存に失敗したら false（次の呼び出しで、載っている限りやり直す）。
	 */
	public static function unmark_stale( $path ) {
		$key = self::stale_key( $path );
		if ( '' === $key ) {
			return true;
		}
		// 載っているかは DB から直接読む（長く動く処理の中のキャッシュは、別の処理が後から載せた項目を見落とす）。
		$current = self::read_stale_row();
		if ( null === $current ) {
			return false;
		}
		$keys = array_values( array_unique( array_merge( array( $key ), self::path_keys( $path, false ) ) ) );
		$any  = false;
		foreach ( $keys as $k ) {
			if ( isset( $current[ $k ] ) ) {
				$any = true;
			}
		}
		if ( ! $any ) {
			return true;
		}
		$ok = self::with_lock(
			'stale',
			function () use ( $keys ) {
				$set = self::read_stale_row();
				if ( null === $set ) {
					return false; // 読めなかった。書かない（空の一覧で、既にある一覧を上書きしない）。
				}
				foreach ( $keys as $k ) {
					unset( $set[ $k ] );
				}
				return self::write_option_row( self::STALE_OPTION, $set );
			}
		);
		self::variants_changed();
		return true === $ok;
	}

	/**
	 * 旧い・不要になった自作の変換ファイルを配信されない状態にする。消す→消せなければ空にする→空にもできなければ「使わない一覧」に載せる。
	 *
	 * @param string $variant 変換ファイルの絶対パス。
	 * @return string 'removed'（消えた）|'disabled'（空にした。ファイルは残る）|'failed'（一覧に載せた。ファイルは残る）。
	 */
	private static function disable_variant( $variant ) {
		if ( ! file_exists( $variant ) || @unlink( $variant ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			self::unmark_stale( $variant );
			self::variants_changed();
			return 'removed';
		}
		// 消せない（フォルダが書き込み不可など）。ファイルを空にする。大きさ 0 の変換ファイルは配信側が使わない。
		// ただし、名前がシンボリックリンクのときは空にしない(書き込むとリンクの先、つまり別のファイルを空にしてしまう)。「使わない一覧」へ進む(配信側もリンクは使わない)。
		if ( ! is_link( $variant ) && false !== @file_put_contents( $variant, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			self::variants_changed();
			return 'disabled';
		}
		// 空にもできない。「使わない一覧」に載せる（ロックが取れなければ載らないが、記録は呼び出し側が残して、次の変換で再試行する）。
		self::mark_stale( $variant );
		return 'failed';
	}

	/**
	 * この元ファイル（アップロードフォルダからの相対パス）の変換ファイルを、このプラグインが作った（所有の記録がある）か、DB の記録から調べる。
	 * 添付の削除の前に控えた記録（owned_on_delete）が無い削除（サイズの再生成・画像編集で古いサイズを消す、など）でも、自分で作った変換ファイルを片付けるため。
	 *
	 * @param string $rel 元ファイルの相対パス。
	 * @param string $ext webp|avif。
	 * @return bool
	 */
	private static function owned_by_record( $rel, $ext ) {
		global $wpdb;
		$needle = 's:' . strlen( $rel ) . ':"' . $rel . '";';
		$after  = 0;
		do {
			// 同じ相対パスを含む記録が何件あっても見落とさないよう、meta_id の順に 50 件ずつ、全部読む。
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s AND meta_id > %d ORDER BY meta_id ASC LIMIT 50", self::META_KEY, '%' . $wpdb->esc_like( $needle ) . '%', $after ) );
			if ( '' !== $wpdb->last_error ) {
				return false; // 調べられなかった。消さない側に倒す。
			}
			foreach ( (array) $rows as $row ) {
				$after = max( $after, (int) $row->meta_id );
				$meta  = maybe_unserialize( $row->meta_value );
				if ( is_array( $meta ) && isset( $meta['files'][ $rel ] ) && self::owns_entry( $meta['files'][ $rel ], $ext ) ) {
					return true;
				}
			}
		} while ( 50 === count( (array) $rows ) );
		return false;
	}

	/**
	 * 元ファイルに付いている変換ファイル（.webp / .avif）を消す。元ファイルを消す処理から呼ぶ。
	 *
	 * @param string $file 消される元ファイルの絶対パス。
	 * @return void
	 */
	public static function delete_variants_of( $file ) {
		if ( ! is_string( $file ) || '' === $file || '.tmp' === substr( $file, -4 ) ) {
			return;
		}
		$base = self::uploads_basedir();
		$norm = wp_normalize_path( $file );
		if ( '' === $base || 0 !== strpos( $norm, $base . '/' ) || ! self::is_safe_relative_path( substr( $norm, strlen( $base ) + 1 ) ) ) {
			return; // アップロードフォルダの外のファイルには関与しない。
		}
		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$variant = $file . '.' . $ext;
			// 消すのは、このプラグインが作った(所有の記録がある)変換ファイルだけ。他のプラグインが作った同名のファイルは、元ファイルが消えても触らない
			// (登録の別表記・リンクなどの見落としで、別の添付の本体を消す危険を、削除の側で断つ)。登録済みの確認は、念のための二重の確認。
			if ( is_file( $variant ) && ( isset( self::$owned_on_delete[ wp_normalize_path( $variant ) ] ) || self::owned_by_record( substr( $norm, strlen( $base ) + 1 ), $ext ) ) && ! self::is_registered_file( $variant ) ) {
				self::disable_variant( $variant );
			}
		}
	}

	/**
	 * 1 枚分の変換ファイルを消す（自分で作ったものだけ。元画像には触れない）。
	 * 消せなかったものの所有の記録は残す（残さないと、あとで消せなくなる）。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return array removed(件数), bytes, failed(消せなかった件数)。
	 */
	public static function remove_converted( $id ) {
		$out  = array(
			'removed' => 0,
			'bytes'   => 0,
			'failed'  => 0,
		);
		$base = self::uploads_basedir();
		if ( '' === $base ) {
			return $out;
		}
		if ( ! self::lock( $id ) ) {
			// 鍵が取れない（同じ画像を変換中、または落ちた処理の鍵が残っている）。消せなかったものとして数える（一括削除が「処理済み」と数えない）。
			$out['failed'] = 1;
			return $out;
		}
		// 鍵を取った後に、古いキャッシュを捨てて記録を読み直す（鍵を取る前に別の処理が足した記録を取りこぼさない）。
		wp_cache_delete( $id, 'post_meta' );
		$meta = self::get_meta( $id );
		if ( empty( $meta ) ) {
			delete_post_meta( $id, self::META_KEY );
			self::unlock( $id );
			delete_transient( self::STATS_KEY );
			return $out;
		}
		$remaining = $meta;
		foreach ( $meta['files'] as $rel => $entry ) {
			if ( ! self::is_safe_relative_path( $rel ) || ! is_array( $entry ) ) {
				continue;
			}
			foreach ( array( 'webp', 'avif' ) as $format ) {
				if ( ! self::owns_entry( $entry, $format ) ) {
					unset( $remaining['files'][ $rel ][ $format ] ); // 他のプラグインが作ったものは消さない。記録も要らない。
					continue;
				}
				$variant = $base . '/' . $rel . '.' . $format;
				$done    = true;
				if ( is_file( $variant ) ) {
					// 登録済み（別の添付の本体）なら、自分のものではないので触らず、記録も要らない。
					// 確認の SQL が失敗したときは、消さず、記録も残し、失敗に数える（次の削除でやり直す）。
					$reg = self::registration_state( $variant );
					if ( 'error' === $reg ) {
						$done = false;
						++$out['failed'];
					} elseif ( 'no' === $reg ) {
						$bytes  = (int) filesize( $variant );
						$result = self::disable_variant( $variant );
						if ( 'removed' === $result ) {
							++$out['removed'];
							$out['bytes'] += $bytes;
						} else {
							$done = false;
							++$out['failed'];
						}
					}
				}
				if ( $done ) {
					unset( $remaining['files'][ $rel ][ $format ], $remaining['files'][ $rel ]['po'][ $format ] );
				}
			}
			if ( isset( $remaining['files'][ $rel ] ) ) {
				$e = $remaining['files'][ $rel ];
				if ( isset( $e['po'] ) && empty( $e['po'] ) ) {
					unset( $remaining['files'][ $rel ]['po'] );
				}
				if ( empty( $e['webp'] ) && empty( $e['avif'] ) && empty( $remaining['files'][ $rel ]['po'] ) ) {
					unset( $remaining['files'][ $rel ] );
				}
			}
		}
		if ( empty( $remaining['files'] ) ) {
			delete_post_meta( $id, self::META_KEY );
		} else {
			update_post_meta( $id, self::META_KEY, $remaining );
		}
		self::unlock( $id );
		delete_transient( self::STATS_KEY );
		self::variants_changed();
		return $out;
	}

	/**
	 * 画像の添付ファイル ID を、ID 順に一定数ずつ調べて返す（一括処理の対象探し用）。
	 *
	 * @param string $mode     pending（未処理だけ）|all（全部）|converted（変換記録があるもの）。
	 * @param int    $after_id この ID より後から調べる。
	 * @param int    $limit    1 回に調べる件数。
	 * @param array  $args     retry_failed。
	 * @return array ids, last_id, scanned, done。
	 */
	public static function scan_chunk( $mode, $after_id, $limit, array $args = array() ) {
		global $wpdb;
		$mimes        = Kashiwazaki_Next_Gen_Images_Engine::SOURCE_MIMES;
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$params       = array_merge( $mimes, array( (int) $after_id, (int) $limit ) );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d", $params ) );
		if ( '' !== $wpdb->last_error ) {
			// SELECT が失敗した（wpdb::get_col は失敗でも空の配列を返す）。「対象が尽きた」とは区別する: 終わりにせず、呼び出し側が再試行する。
			return array(
				'ids'     => array(),
				'last_id' => (int) $after_id,
				'scanned' => 0,
				'done'    => false,
				'error'   => true,
			);
		}
		$ids = array_map( 'intval', (array) $rows );

		$out = array(
			'ids'     => array(),
			'last_id' => (int) $after_id,
			'scanned' => count( $ids ),
			'done'    => count( $ids ) < (int) $limit,
		);
		if ( empty( $ids ) ) {
			return $out;
		}
		$out['last_id'] = (int) end( $ids );
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			if ( 'all' === $mode ) {
				$out['ids'][] = $id;
			} elseif ( 'converted' === $mode ) {
				if ( ! empty( self::get_meta( $id ) ) ) {
					$out['ids'][] = $id;
				}
			} elseif ( self::needs_work( $id, $args ) ) {
				$out['ids'][] = $id;
			}
		}
		return $out;
	}

	/**
	 * 変換済みの状況を集計する（設定画面の診断用）。
	 *
	 * @param bool $refresh true なら集計し直す。
	 * @return array
	 */
	public static function stats( $refresh = false ) {
		global $wpdb;
		if ( ! $refresh ) {
			$cached = get_transient( self::STATS_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$stats = array(
			'images'      => 0,
			'attachments' => 0,
			'files'       => array(
				'webp' => 0,
				'avif' => 0,
			),
			'src_bytes'   => array(
				'webp' => 0,
				'avif' => 0,
			),
			'out_bytes'   => array(
				'webp' => 0,
				'avif' => 0,
			),
			'failed'      => 0,
			'time'        => time(),
		);

		$mimes        = Kashiwazaki_Next_Gen_Images_Engine::SOURCE_MIMES;
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$stats['images'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($placeholders)", $mimes ) );

		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT 1000", self::META_KEY, $last ) );
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->meta_id;
				$meta = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
					continue;
				}
				$any = false;
				foreach ( $meta['files'] as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					foreach ( array( 'webp', 'avif' ) as $format ) {
						if ( isset( $entry[ $format ]['size'] ) ) {
							$any = true;
							++$stats['files'][ $format ];
							$stats['src_bytes'][ $format ] += (int) $entry['src_size'];
							$stats['out_bytes'][ $format ] += (int) $entry[ $format ]['size'];
						}
					}
				}
				if ( $any ) {
					++$stats['attachments'];
				}
				if ( isset( $meta['state'] ) && 'failed' === $meta['state'] ) {
					++$stats['failed'];
				}
			}
		} while ( count( (array) $rows ) === 1000 );

		set_transient( self::STATS_KEY, $stats, 10 * MINUTE_IN_SECONDS );
		return $stats;
	}
}
