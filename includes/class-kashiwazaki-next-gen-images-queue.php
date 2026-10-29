<?php
/**
 * アップロード時の自動変換、予約（WP-Cron）、元ファイル削除時の後始末。
 *
 * どの処理も例外を握りつぶして元の処理（アップロード・削除）を妨げない。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 変換のきっかけを担当するクラス。
 */
final class Kashiwazaki_Next_Gen_Images_Queue {

	const CRON_HOOK    = 'kashiwazaki_next_gen_images_cron_convert';
	const ATTEMPT_META = '_kashiwazaki_next_gen_images_attempts';
	const MAX_ATTEMPTS = 6;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// 画像のサイズ違いが作られた直後。アップロード・サムネイル再生成のどちらでも通る。
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 99, 3 );
		// 画像エディタでの編集や、他のプラグインによるメタデータ更新。
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_update_metadata' ), 99, 2 );
		add_action( 'add_attachment', array( 'Kashiwazaki_Next_Gen_Images_Converter', 'note_attachment' ), 10, 1 );
		// 台帳（同名の別画像を避けるための一覧）を DB の真実から保つ。メタが保存されたあと・添付が削除されたあとに作り直す。
		add_action( 'added_post_meta', array( 'Kashiwazaki_Next_Gen_Images_Converter', 'on_meta_saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( 'Kashiwazaki_Next_Gen_Images_Converter', 'on_meta_saved' ), 10, 4 );
		add_action( 'deleted_post_meta', array( 'Kashiwazaki_Next_Gen_Images_Converter', 'on_meta_deleted' ), 10, 4 );
		add_action( 'delete_attachment', array( 'Kashiwazaki_Next_Gen_Images_Converter', 'before_delete_attachment' ), 10, 1 );
		add_action( 'deleted_post', array( 'Kashiwazaki_Next_Gen_Images_Converter', 'after_delete_post' ), 10, 1 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ), 10, 1 );
		// 元ファイルが消されるとき、一緒に変換ファイルも消す。
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ), 10, 1 );
	}

	/**
	 * 自動変換の対象にしてよい状況か。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return bool
	 */
	private static function should_auto_convert( $id ) {
		return (bool) Kashiwazaki_Next_Gen_Images_Settings::get( 'auto_convert' )
			&& Kashiwazaki_Next_Gen_Images_Converter::is_supported_attachment( $id );
	}

	/**
	 * 新しい画像のサイズ違いが作られた直後の処理。
	 *
	 * @param array  $metadata 添付ファイルのメタデータ。
	 * @param int    $id       添付ファイル ID。
	 * @param string $context  create|update。
	 * @return array 受け取ったメタデータをそのまま返す（変更しない）。
	 */
	public static function on_generate_metadata( $metadata, $id, $context = 'create' ) {
		try {
			$id = (int) $id;
			if ( ! self::should_auto_convert( $id ) ) {
				return $metadata;
			}
			if ( 'immediate' !== Kashiwazaki_Next_Gen_Images_Settings::get( 'convert_timing' ) ) {
				self::schedule( $id );
				return $metadata;
			}
			// アップロードを待たせすぎないよう、時間に上限を設ける。終わらなかった分は予約に回す。
			$result = Kashiwazaki_Next_Gen_Images_Converter::convert_attachment(
				$id,
				array(
					'metadata'        => is_array( $metadata ) ? $metadata : null,
					'deadline'        => Kashiwazaki_Next_Gen_Images_Engine::deadline(),
					'strict_deadline' => true, // WordPress がサイズ違いの作成で時間を使い切っていたら、1 組目からでも変換せず予約に回す。
				)
			);
			if ( in_array( $result['state'], array( 'partial', 'locked' ), true ) ) {
				self::schedule( $id );
			}
		} catch ( Throwable $e ) {
			// アップロード自体は成功させる。変換はあとから一括変換でやり直せる。
			self::log( 'on_generate_metadata: ' . $e->getMessage() );
		}
		return $metadata;
	}

	/**
	 * メタデータが更新されたとき、未処理があれば予約する。
	 *
	 * @param array $data メタデータ。
	 * @param int   $id   添付ファイル ID。
	 * @return array
	 */
	public static function on_update_metadata( $data, $id ) {
		try {
			$id = (int) $id;
			if ( self::should_auto_convert( $id ) && Kashiwazaki_Next_Gen_Images_Converter::needs_work( $id, array( 'metadata' => $data ) ) ) {
				// このフィルターは保存の前に呼ばれる。保存済みの古いメタデータではなく、渡された新しいメタデータ($data)で、未変換のサイズがあるかを判定する。
				self::schedule( $id );
			}
		} catch ( Throwable $e ) {
			self::log( 'on_update_metadata: ' . $e->getMessage() );
		}
		return $data;
	}

	/**
	 * WP-Cron に変換を予約する（同じ画像の予約は重複させない）。
	 *
	 * @param int $id    添付ファイル ID。
	 * @param int $delay 何秒後に実行するか。
	 * @return void
	 */
	public static function schedule( $id, $delay = 5 ) {
		$args = array( (int) $id );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK, $args );
		}
	}

	/**
	 * WP-Cron から呼ばれる変換。
	 *
	 * @param int $id 添付ファイル ID。
	 * @return void
	 */
	public static function run_scheduled( $id ) {
		$id = (int) $id;
		try {
			if ( ! self::should_auto_convert( $id ) ) {
				return;
			}
			$result = Kashiwazaki_Next_Gen_Images_Converter::convert_attachment(
				$id,
				array( 'deadline' => Kashiwazaki_Next_Gen_Images_Engine::deadline() )
			);
			if ( in_array( $result['state'], array( 'partial', 'locked' ), true ) ) {
				// 無限に再予約しないよう回数を数える。上限に達したら、一括変換に任せる。
				$attempts = (int) get_post_meta( $id, self::ATTEMPT_META, true );
				if ( $attempts < self::MAX_ATTEMPTS ) {
					update_post_meta( $id, self::ATTEMPT_META, $attempts + 1 );
					self::schedule( $id, 30 );
				}
			} else {
				delete_post_meta( $id, self::ATTEMPT_META );
			}
		} catch ( Throwable $e ) {
			self::log( 'run_scheduled: ' . $e->getMessage() );
		}
	}

	/**
	 * WordPress が元ファイルを消すとき、付属の変換ファイルも消す。
	 *
	 * @param string $file 消される元ファイルのパス。
	 * @return string そのまま返す。
	 */
	public static function on_delete_file( $file ) {
		try {
			Kashiwazaki_Next_Gen_Images_Converter::delete_variants_of( $file );
		} catch ( Throwable $e ) {
			self::log( 'on_delete_file: ' . $e->getMessage() );
		}
		return $file;
	}

	/**
	 * デバッグログ（WP_DEBUG_LOG が有効なときだけ書かれる）。
	 *
	 * @param string $message メッセージ。
	 * @return void
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Kashiwazaki Next-Gen Images] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
