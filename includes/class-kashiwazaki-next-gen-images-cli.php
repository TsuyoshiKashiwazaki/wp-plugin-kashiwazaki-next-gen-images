<?php
/**
 * WP-CLI コマンド。大量の画像を SSH から一括変換するときに使う。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI 用のクラス。
 */
final class Kashiwazaki_Next_Gen_Images_CLI {

	/**
	 * コマンドを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'kashiwazaki-next-gen-images', array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * Kashiwazaki Next-Gen Images のコマンド。
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : convert（変換）| status（状況表示）。
	 *
	 * [<id>...]
	 * : convert のとき、対象の添付ファイル ID。省略すると未変換の画像すべて。
	 *
	 * [--force]
	 * : 変換済みの画像も作り直す。
	 *
	 * [--retry-failed]
	 * : 以前に失敗した画像も再試行する。
	 *
	 * ## EXAMPLES
	 *
	 *     wp kashiwazaki-next-gen-images convert
	 *     wp kashiwazaki-next-gen-images convert 12 34 --force
	 *     wp kashiwazaki-next-gen-images status
	 *
	 * @param array $args       位置引数。
	 * @param array $assoc_args オプション。
	 * @return void
	 */
	public static function dispatch( $args, $assoc_args ) {
		$action = array_shift( $args );
		if ( 'status' === $action ) {
			self::status();
			return;
		}
		if ( 'convert' === $action ) {
			self::convert( array_map( 'absint', $args ), $assoc_args );
			return;
		}
		WP_CLI::error( 'Unknown action. Use: convert | status' );
	}

	/**
	 * 状況を表示する。
	 *
	 * @return void
	 */
	private static function status() {
		$stats = Kashiwazaki_Next_Gen_Images_Converter::stats( true );
		$fmts  = Kashiwazaki_Next_Gen_Images_Engine::available_formats();
		WP_CLI::line( 'Available formats on this server: ' . ( $fmts ? implode( ', ', $fmts ) : '(none)' ) );
		WP_CLI::line( sprintf( 'Images: %d / converted: %d / failed: %d', $stats['images'], $stats['attachments'], $stats['failed'] ) );
		foreach ( array( 'webp', 'avif' ) as $f ) {
			WP_CLI::line( sprintf( '%s: %d files, %s -> %s', strtoupper( $f ), $stats['files'][ $f ], size_format( $stats['src_bytes'][ $f ] ), size_format( $stats['out_bytes'][ $f ] ) ) );
		}
	}

	/**
	 * 変換を実行する。
	 *
	 * @param int[] $ids        対象の ID（空なら未処理すべて）。
	 * @param array $assoc_args オプション。
	 * @return void
	 */
	private static function convert( array $ids, array $assoc_args ) {
		$force = ! empty( $assoc_args['force'] );
		$retry = ! empty( $assoc_args['retry-failed'] ) || $force;

		if ( empty( $ids ) ) {
			$after = 0;
			do {
				$chunk = Kashiwazaki_Next_Gen_Images_Converter::scan_chunk( $force ? 'all' : 'pending', $after, 200, array( 'retry_failed' => $retry ) );
				if ( ! empty( $chunk['error'] ) ) {
					WP_CLI::error( 'Could not list the images to convert (database query failed). Nothing was converted.' );
				}
				$ids   = array_merge( $ids, $chunk['ids'] );
				$after = $chunk['last_id'];
			} while ( ! $chunk['done'] );
		}
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to convert.' );
			return;
		}

		$progress     = \WP_CLI\Utils\make_progress_bar( 'Converting', count( $ids ) );
		$converted    = 0;
		$failed       = 0;
		$locked       = 0;
		$saved        = 0;
		foreach ( $ids as $id ) {
			$force_token = '';
			$tries        = 0;
			do {
				// CLI には実行時間の制限が無いので、時間で区切らず 1 回で最後まで処理する。
				$res          = Kashiwazaki_Next_Gen_Images_Converter::convert_attachment(
					$id,
					array(
						'force'        => $force,
						'retry_failed' => $retry,
						'force_token'  => $force_token,
					)
				);
				$force_token = $res['force_token'];
				$converted   += $res['converted'];
				$saved       += $res['saved'];
				++$tries;
			} while ( in_array( $res['state'], array( 'partial' ), true ) && $tries < 5 );

			if ( 'failed' === $res['state'] ) {
				++$failed;
				foreach ( $res['errors'] as $error ) {
					WP_CLI::warning( "ID {$id}: {$error}" );
				}
			}
			if ( 'locked' === $res['state'] ) { // 同じ添付を別の処理(画面・cron・一括処理)が変換中。数えず黙って次へ進むと、未変換のまま「全部終わった」ように見える。
				++$locked;
				WP_CLI::warning( "ID {$id}: skipped because another process is converting it." );
			}
			$progress->tick();
		}
		$progress->finish();
		if ( $locked > 0 ) {
			WP_CLI::warning( sprintf( '%d attachments were skipped because another process was converting them. Run the command again.', $locked ) );
		}
		WP_CLI::success( sprintf( 'Converted %d files, %d attachments failed, saved %s.', $converted, $failed, size_format( $saved ) ) );
	}
}
