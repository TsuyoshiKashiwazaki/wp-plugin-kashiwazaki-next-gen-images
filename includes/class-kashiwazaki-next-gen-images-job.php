<?php
/**
 * 一括変換・一括削除の「作業の状態」をサーバー側に持つ仕組み。
 *
 * 進み具合（どこまで終わったか）をサーバーに保存するので、
 * - 画面を離れても、戻ったときに同じ進捗バーが出る
 * - 停止しても、続きから再開できる
 * - 管理画面のどのページを開いていても、見ている間は処理が進む
 * - WP-Cron が動くサーバーなら、画面を閉じていても進む
 * 作業の実体は 1 枚ずつの変換（Converter::convert_attachment）なので、途中で止まっても壊れない。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ジョブ（一括処理）の管理。
 */
final class Kashiwazaki_Next_Gen_Images_Job {

	const OPTION    = 'kashiwazaki_next_gen_images_job';
	const LOCK_NAME = 'kashiwazaki_next_gen_images_job_lock';
	const LOCK_TTL  = 90;
	const CRON_HOOK = 'kashiwazaki_next_gen_images_job_cron';
	const CHUNK     = 10;
	const STOP_FLAG = 'kashiwazaki_next_gen_images_job_stop';
	const LOG_MAX   = 40;
	const MAX_TRIES = 8;

	/**
	 * 自分が取ったロックの値。
	 *
	 * @var string
	 */
	private static $lock_value = '';

	/**
	 * 保存しようとしたジョブが、DB の今のジョブと別のものになっていた（置き換えられた・消えた・ロックが取れなかった）か。
	 * 処理側は、これが真なら処理を打ち切る。
	 *
	 * @var bool
	 */
	private static $lost = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}

	/**
	 * 現在のジョブ（無ければ null）。
	 *
	 * 必ず DB から直接読む。WordPress は「自動読み込み」のオプションを 1 回のリクエストの間メモリに
	 * 覚えているため、get_option() だと、長い処理の途中で別のリクエストが書いた「停止」を見落とし、
	 * 処理の終わりに停止を上書きしてしまう（更新の取りこぼし）。
	 *
	 * @return array|null
	 */
	public static function get() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) );
		if ( null === $raw ) {
			return null;
		}
		$job = maybe_unserialize( $raw );
		return ( is_array( $job ) && ! empty( $job['id'] ) && ! empty( $job['kind'] ) ) ? $job : null;
	}

	/**
	 * ジョブを保存する（DB に直接書き、メモリ上の古い値も捨てる）。
	 *
	 * @param array $job ジョブ。
	 * @return array
	 */
	private static function save( array $job, $clear_stop_flag = false ) {
		$result = Kashiwazaki_Next_Gen_Images_Converter::with_lock(
			'job',
			function () use ( $job, $clear_stop_flag ) {
				$current = self::get();
				// 保存しようとするジョブが、DB の今のジョブと別のもの（別のジョブに置き換えられた・消えた）なら、保存しない。
				if ( ! $current || (string) $current['id'] !== (string) $job['id'] ) {
					return array(
						'lost' => true,
						'job'  => $current,
					);
				}
				$job['updated'] = time();
				$job['rev']     = isset( $current['rev'] ) ? (int) $current['rev'] + 1 : 1; // 画面が古い応答で新しい表示を上書きしないための版番号。
				if ( ! Kashiwazaki_Next_Gen_Images_Converter::write_option_row( self::OPTION, $job ) ) {
					// 保存に失敗した。DB のジョブは変わっていないので、保存できなかったもの（lost）として扱う（処理側は打ち切り、停止の依頼は消さない）。
					return array(
						'lost' => true,
						'job'  => $current,
					);
				}
				// 停止の保存が成功したときだけ、停止の依頼を、保存と同じ鍵の中で消す。鍵の外で消すと、保存のあとで別の要求が再開して
				// 新しい停止の依頼を出した場合に、その新しい依頼まで消してしまう。
				if ( $clear_stop_flag ) {
					self::flag_clear();
				}
				return array(
					'lost' => false,
					'job'  => $job,
				);
			}
		);
		if ( null === $result || $result['lost'] ) {
			self::$lost = true;
			$current    = ( null === $result || empty( $result['job'] ) ) ? self::get() : $result['job'];
			return $current ? $current : $job;
		}
		return $result['job'];
	}

	/**
	 * 読んだジョブ（無ければ null）と同じ状態のときだけ、ジョブを置き換える（開始・再開用）。
	 * 名前付きロックの中で、現在のジョブの id と rev を PHP で比べる（SQL の LIKE や文字列等価は使わない）。
	 *
	 * @param array      $new 新しいジョブ。
	 * @param array|null $old 置き換え元として読んだジョブ。
	 * @return array ok(bool), job(array|null: 置き換えた後、または今のジョブ)。
	 */
	private static function replace_job( array $new, $old ) {
		$result = Kashiwazaki_Next_Gen_Images_Converter::with_lock(
			'job',
			function () use ( $new, $old ) {
				$current = self::get();
				$same    = ( ! $old && ! $current )
					|| ( $old && $current && (string) $old['id'] === (string) $current['id'] && (int) $old['rev'] === (int) $current['rev'] );
				if ( ! $same ) {
					return array(
						'ok'  => false,
						'job' => $current,
					);
				}
				$new['updated'] = time();
				$new['rev']     = $current ? (int) $current['rev'] + 1 : 1;
				// 新しく始めた・再開したので、古い停止の依頼は要らない。書く前に（鍵の中で）消す: 書いたあとに消すと、
				// 書いた直後に出た新しい停止の依頼まで消してしまう。書き込みが失敗したら、消した依頼を元に戻す（ジョブは置き換わらない）。
				$old_flag = self::flag_value();
				self::flag_clear();
				if ( ! Kashiwazaki_Next_Gen_Images_Converter::write_option_row( self::OPTION, $new ) ) {
					if ( null !== $old_flag ) {
						self::flag_put( $old_flag ); // 鍵の中で消した値を、そのまま戻す(ジョブの ID の確認は要らない)。
					}
					return array(
						'ok'  => false,
						'job' => $current,
					);
				}
				return array(
					'ok'  => true,
					'job' => $new,
				);
			}
		);
		if ( null === $result ) {
			return array(
				'ok'  => false,
				'job' => self::get(),
			);
		}
		return $result;
	}

	/**
	 * 画像（変換の対象になりうる添付ファイル）の総数。
	 *
	 * @return int
	 */
	private static function count_images() {
		global $wpdb;
		$mimes        = Kashiwazaki_Next_Gen_Images_Engine::SOURCE_MIMES;
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($placeholders)", $mimes ) );
	}

	/**
	 * ジョブを始める。一時停止中の同じ種類のジョブがあれば、続きから再開する。
	 *
	 * @param string $kind convert（変換）| remove（変換済みファイルの削除）。
	 * @param array  $opts force(bool), retry(bool), restart(bool: 最初からやり直す), after_id(int: 試験用)。
	 * @return array
	 */
	public static function start( $kind, array $opts = array() ) {
		$kind     = ( 'remove' === $kind ) ? 'remove' : 'convert';
		$existing = self::get();

		// 実行中のジョブがあれば、何も変えずにそれを返す。別の画面・別の管理者の開始で、実行中のジョブ（と停止の依頼）を置き換えない。
		if ( $existing && 'running' === $existing['state'] ) {
			return $existing;
		}

		// 台帳（同名の別画像を避けるための一覧）を DB の真実から作り直しておく。停止中に増えた分を取りこぼさないための保険。
		try {
			Kashiwazaki_Next_Gen_Images_Converter::refresh_conflicts();
		} catch ( Throwable $e ) {
			unset( $e );
		}

		if ( $existing && 'paused' === $existing['state'] && $existing['kind'] === $kind && empty( $opts['restart'] ) ) {
			$resumed          = $existing;
			$resumed['state'] = 'running';
			$resumed['tries'] = 0;
			$resumed['log']   = self::push_log( $existing, __( '再開しました。', 'wp-plugin-kashiwazaki-next-gen-images' ), false );
			$res              = self::replace_job( $resumed, $existing );
		} else {
			$job = array(
				'id'          => wp_generate_password( 10, false ),
				'kind'        => $kind,
				'state'       => 'running',
				'force'       => ( 'convert' === $kind ) && ! empty( $opts['force'] ),
				'retry'       => ( 'convert' === $kind ) && ( ! empty( $opts['retry'] ) || ! empty( $opts['force'] ) ),
				'force_token' => wp_generate_password( 10, false ),
				'after_id'    => isset( $opts['after_id'] ) ? max( 0, (int) $opts['after_id'] ) : 0,
				'queue'       => array(),
				'chunk_last'  => 0,
				'tries'       => 0,
				'total'       => self::count_images(),
				'scanned'     => 0,
				'processed'   => 0,
				'converted'   => 0,
				'saved'       => 0,
				'failed'      => 0,
				'removed'     => 0,
				'bytes'       => 0,
				'started'     => time(),
				'finished'    => 0,
				'rev'         => 0,
				'log'         => array(),
			);
			$res = self::replace_job( $job, $existing );
		}
		if ( ! empty( $res['ok'] ) ) {
			// 古い停止の依頼は、replace_job が鍵の中で消した。
			self::schedule();
		}
		return $res['job'];
	}

	/**
	 * 一時停止する。
	 *
	 * 処理中の別のリクエストがあるときは、「停止の依頼」を別の行に書く。処理側は 1 枚終えるごとにそれを見て止まる。
	 * （状態そのものを書き換えると、処理側の保存とぶつかって停止が消えることがあるため。）
	 *
	 * @return array|null
	 */
	public static function stop() {
		$job = self::get();
		if ( ! $job || 'running' !== $job['state'] ) {
			return $job;
		}
		$target_id = (string) $job['id'];
		if ( ! self::flag_set( $target_id ) ) {
			return self::get(); // 読んでから書くまでの間に、ジョブが入れ替わった(終わった・別のジョブが始まった)。古いジョブの停止の依頼で、新しいジョブの旗を上書きしない。
		}
		if ( self::lock( 0 ) ) {
			// 処理中のリクエストが無い。今すぐ止める。
			$job = self::get();
			// 停止の依頼を書いた後に遅れて来て、別のジョブ(終わった後に始まった新しいジョブ)を止めない: 止めるのは、依頼したジョブだけ。
			if ( $job && 'running' === $job['state'] && (string) $job['id'] === $target_id ) {
				$job['state'] = 'paused';
				$job['log']   = self::push_log( $job, __( '停止しました。', 'wp-plugin-kashiwazaki-next-gen-images' ), false );
				self::$lost   = false;
				$job          = self::save( $job, true ); // 停止の依頼は、保存と同じ鍵の中で、保存が成功したときだけ消える。
			}
			// 止める対象の実行中のジョブが無かったとき（読み直す間に止まった・終わった）は、停止の依頼を消さない。停止の依頼はジョブの ID と一致するときだけ
			// 効くので、残っていても害は無く、再開(replace_job)が鍵の中で消す。鍵の外で消すと、再開と新しい停止の依頼の間に割り込んで、新しい依頼を消し得る。
			self::unlock();
		}
		return self::get();
	}

	/**
	 * 終わったジョブの表示を消す。
	 *
	 * @return void
	 */
	public static function clear() {
		Kashiwazaki_Next_Gen_Images_Converter::with_lock(
			'job',
			function () {
				// ロックの中で現在のジョブを読み直し、実行中でなければ消す（開始と交錯して、新しいジョブを消さない）。
				$job = self::get();
				if ( $job && 'running' !== $job['state'] ) {
					delete_option( self::OPTION );
					wp_cache_delete( 'alloptions', 'options' );
				}
				return true;
			}
		);
	}

	/**
	 * 一定時間だけ作業を進める。
	 *
	 * 少なくとも 1 件は進める。時間が足りなければ途中で止め、続きは次の呼び出しで処理する。
	 *
	 * @param float $budget 使ってよい秒数。
	 * @return array array( job => 要約, busy => 他の処理が進行中か )。
	 */
	public static function tick( $budget = 10.0 ) {
		self::$lost = false;
		$job = self::get();
		if ( ! $job || 'running' !== $job['state'] ) {
			return array(
				'job'  => self::summary( $job ),
				'busy' => false,
			);
		}
		if ( ! self::lock( 0 ) ) {
			return array(
				'job'  => self::summary( $job ),
				'busy' => true,
			);
		}

		try {
			$job = self::get(); // ロックを取った後の最新の状態。
			if ( $job && 'running' === $job['state'] ) {
				if ( self::stop_requested( $job ) ) {
					$job['state'] = 'paused';
					$job['log']   = self::push_log( $job, __( '停止しました。', 'wp-plugin-kashiwazaki-next-gen-images' ), false );
					$job          = self::save( $job, true ); // 停止の依頼は、保存と同じ鍵の中で、保存が成功したときだけ消える。
				} else {
					$job = self::work( $job, (float) $budget );
				}
			}
		} catch ( Throwable $e ) {
			$job = self::get();
			if ( $job ) {
				$job['log'] = self::push_log( $job, $e->getMessage(), true );
				$job        = self::save( $job );
			}
		}
		self::unlock();

		return array(
			'job'  => self::summary( $job ),
			'busy' => false,
		);
	}

	/**
	 * 実際の作業（ロックを取った状態で呼ぶ）。
	 *
	 * @param array $job    ジョブ。
	 * @param float $budget 秒数。
	 * @return array 保存後のジョブ。
	 */
	private static function work( array $job, $budget ) {
		$started  = microtime( true );
		$deadline = min( $started + $budget, Kashiwazaki_Next_Gen_Images_Engine::deadline() );
		$mode     = ( 'remove' === $job['kind'] ) ? 'converted' : ( $job['force'] ? 'all' : 'pending' );
		$units    = 0;

		while ( true ) {
			if ( empty( $job['queue'] ) ) {
				$chunk = Kashiwazaki_Next_Gen_Images_Converter::scan_chunk( $mode, (int) $job['after_id'], self::CHUNK, array( 'retry_failed' => ! empty( $job['retry'] ) ) );
				if ( ! empty( $chunk['error'] ) ) {
					// 対象の探索の SELECT が失敗した。「対象が尽きた」ではないので、完了にしない。数回続けて失敗したら、止めて知らせる（次の機会に再試行する）。
					$job['tries'] = $job['tries'] + 1;
					if ( $job['tries'] >= self::MAX_TRIES ) {
						$job['state'] = 'paused';
						$job['tries'] = 0;
						$job['log']   = self::push_log( $job, __( '対象の画像を探せませんでした（データベースの問い合わせが失敗しました）。一時停止しました。', 'wp-plugin-kashiwazaki-next-gen-images' ), true );
					}
					break;
				}
				$job['scanned'] += (int) $chunk['scanned'];
				if ( empty( $chunk['ids'] ) ) {
					$job['after_id'] = (int) $chunk['last_id'];
					if ( ! empty( $chunk['done'] ) ) {
						$job['state']    = 'done';
						$job['finished'] = time();
						$job['log']      = self::push_log( $job, self::done_message( $job ), false );
						break;
					}
					if ( microtime( true ) >= $deadline ) {
						break;
					}
					continue;
				}
				$job['queue']      = array_map( 'intval', $chunk['ids'] );
				$job['chunk_last'] = (int) $chunk['last_id'];
			}

			while ( ! empty( $job['queue'] ) ) {
				// 少なくとも 1 件は進める。2 件目以降は、締め切りを越えそうなら始めない。
				if ( $units > 0 && ( microtime( true ) + 3.0 ) >= $deadline ) {
					break 2;
				}
				$id = (int) $job['queue'][0];
				if ( 'remove' === $job['kind'] ) {
					$res = Kashiwazaki_Next_Gen_Images_Converter::remove_converted( $id );
					$job['removed'] += (int) $res['removed'];
					$job['bytes']   += (int) $res['bytes'];
					if ( ! empty( $res['failed'] ) ) {
						$job['failed'] += (int) $res['failed'];
						$job['log']     = self::push_log( $job, sprintf( /* translators: 1: attachment ID, 2: number of files */ __( 'ID %1$d: 消せなかった変換ファイルが %2$d 件あります（権限を確かめて、もう一度実行してください）。', 'wp-plugin-kashiwazaki-next-gen-images' ), $id, (int) $res['failed'] ), true );
					}
					array_shift( $job['queue'] );
					++$job['processed'];
				} else {
					$res = Kashiwazaki_Next_Gen_Images_Converter::convert_attachment(
						$id,
						array(
							'force'        => ! empty( $job['force'] ),
							'retry_failed' => ! empty( $job['retry'] ),
							'force_token'  => (string) $job['force_token'],
							'deadline'     => $deadline,
						)
					);
					// 途中で終わった分も含めて、まず集計する。
					$job['converted'] += (int) $res['converted'];
					$job['saved']     += (int) $res['saved'];
					foreach ( array_slice( (array) $res['errors'], 0, 2 ) as $error ) {
						$job['log'] = self::push_log( $job, 'ID ' . $id . ': ' . $error, true );
					}
					if ( in_array( $res['state'], array( 'partial', 'locked' ), true ) ) {
						// 時間内に終わらなかった（または別の処理が進行中）。同じ画像を次の機会にもう一度。
						// 実際に変換を進められたなら、失敗の試行には数えない（枚数の多い画像は、何回かに分けて終わる）。
						$job['tries'] = ( ! empty( $res['progress'] ) ) ? 0 : $job['tries'] + 1;
						if ( $job['tries'] >= self::MAX_TRIES ) {
							$job['log'] = self::push_log( $job, sprintf( /* translators: %d: attachment ID */ __( 'ID %d: 時間内に終わらなかったため、飛ばしました。', 'wp-plugin-kashiwazaki-next-gen-images' ), $id ), true );
							++$job['failed'];
							array_shift( $job['queue'] );
							++$job['processed'];
							$job['tries'] = 0;
						} else {
							break 2;
						}
					} else {
						$job['tries'] = 0;
						if ( 'failed' === $res['state'] ) {
							++$job['failed'];
						}
						array_shift( $job['queue'] );
						++$job['processed'];
					}
				}
				++$units;
				// チャンクの最後の 1 枚を終えたら、保存の前に、探索の位置(after_id)を進める。進める前に停止して保存すると、再開時に queue が空のまま
				// 古い after_id から同じチャンクを探し直し、同じ枚数を二重に数える・二重に変換する。
				if ( empty( $job['queue'] ) && $job['chunk_last'] > (int) $job['after_id'] ) {
					$job['after_id']   = (int) $job['chunk_last'];
					$job['chunk_last'] = 0;
				}
				// 1 枚終えるごとに状態を保存する（画面の数字が、1 枚ずつ動くように）。停止の依頼があればここで止まる。
				if ( ! self::checkpoint( $job ) ) {
					return $job;
				}
			}

			if ( empty( $job['queue'] ) && $job['chunk_last'] > (int) $job['after_id'] ) {
				$job['after_id']   = (int) $job['chunk_last'];
				$job['chunk_last'] = 0;
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		// 終わり際に停止の依頼が来ていたら、それも尊重する。
		$stopped = false;
		if ( 'running' === $job['state'] && self::stop_requested( $job ) ) {
			$job['state'] = 'paused';
			$job['log']   = self::push_log( $job, __( '停止しました。', 'wp-plugin-kashiwazaki-next-gen-images' ), false );
			$stopped      = true;
		}
		$job = self::save( $job, $stopped ); // 止めたときは、停止の依頼を、保存と同じ鍵の中で、保存が成功したときだけ消す。
		if ( ! self::$lost && 'running' === $job['state'] ) {
			self::schedule();
		}
		return $job;
	}

	/**
	 * 1 枚終えるごとの区切り。状態を保存し、停止の依頼があれば止める。
	 *
	 * @param array $job ジョブ（参照渡し）。
	 * @return bool 続けてよいなら true。
	 */
	private static function checkpoint( array &$job ) {
		if ( self::stop_requested( $job ) ) {
			$job['state'] = 'paused';
			$job['log']   = self::push_log( $job, __( '停止しました。', 'wp-plugin-kashiwazaki-next-gen-images' ), false );
			$job          = self::save( $job, true ); // 停止の依頼は、保存と同じ鍵の中で、保存が成功したときだけ消える。
			return false;
		}
		$job = self::save( $job );
		return ! self::$lost;
	}

	/**
	 * このジョブに停止の依頼が出ているか。
	 *
	 * @param array $job ジョブ。
	 * @return bool
	 */
	private static function stop_requested( array $job ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$flag = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::STOP_FLAG ) );
		return null !== $flag && (string) $flag === (string) $job['id'];
	}

	/**
	 * 停止の依頼を書く（ジョブ本体とは別の行なので、処理側の保存とぶつからない）。
	 *
	 * 書くのは、いま保存されているジョブの ID が $job_id と同じときだけ（1 つの SQL の中で確かめる）。
	 * 停止の依頼 A がジョブ J を読んだ後に遅れ、その間に J が終わって新しいジョブ K が始まり、K への停止の依頼 B が旗を書いたとき、
	 * A が後から J の ID を書いて、K の旗を上書きしないようにする。
	 *
	 * @param string $job_id ジョブの ID。
	 * @return bool 書いた(または同じ値がすでにあった)ら true。ジョブが入れ替わっていて書かなかったとき・SQL が失敗したときは false。
	 */
	private static function flag_set( $job_id ) {
		global $wpdb;
		$needle = 's:2:"id";s:' . strlen( $job_id ) . ':"' . $job_id . '";';
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$res = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM DUAL WHERE EXISTS (SELECT 1 FROM {$wpdb->options} AS j WHERE j.option_name = %s AND j.option_value LIKE %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::STOP_FLAG, $job_id, self::OPTION, '%' . $wpdb->esc_like( $needle ) . '%' ) );
		wp_cache_delete( self::STOP_FLAG, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( false === $res || '' !== $wpdb->last_error ) {
			return false;
		}
		return true === self::flag_is( $job_id );
	}

	/**
	 * 停止の依頼の値を、確認なしでそのまま書く(鍵の中で消した値を戻すときに使う)。
	 *
	 * @param string $job_id 書く値(ジョブの ID)。
	 * @return void
	 */
	private static function flag_put( $job_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::STOP_FLAG, $job_id ) );
		wp_cache_delete( self::STOP_FLAG, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * いま書かれている停止の依頼が、このジョブ ID か。
	 *
	 * @param string $job_id ジョブの ID。
	 * @return bool
	 */
	private static function flag_is( $job_id ) {
		return self::flag_value() === (string) $job_id;
	}

	/**
	 * 停止の依頼を消す。
	 *
	 * @return void
	 */
	private static function flag_clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::STOP_FLAG ) );
	}

	/**
	 * いま書かれている停止の依頼の値（ジョブの ID）。無ければ null。
	 *
	 * @return string|null
	 */
	private static function flag_value() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$flag = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::STOP_FLAG ) );
		return null === $flag ? null : (string) $flag;
	}

	/**
	 * ログに 1 行足した配列を返す（古いものから捨てる）。
	 *
	 * @param array  $job      ジョブ。
	 * @param string $message  文章。
	 * @param bool   $is_error エラーか。
	 * @return array
	 */
	private static function push_log( array $job, $message, $is_error ) {
		$log   = isset( $job['log'] ) && is_array( $job['log'] ) ? $job['log'] : array();
		$log[] = array(
			't' => time(),
			'e' => $is_error ? 1 : 0,
			'm' => (string) $message,
		);
		return array_slice( $log, -self::LOG_MAX );
	}

	/**
	 * 完了時の文章。
	 *
	 * @param array $job ジョブ。
	 * @return string
	 */
	private static function done_message( array $job ) {
		if ( 'remove' === $job['kind'] ) {
			return sprintf(
				/* translators: 1: removed files, 2: size */
				__( '完了しました。変換済みファイル %1$s 件（%2$s）を削除しました。', 'wp-plugin-kashiwazaki-next-gen-images' ),
				number_format_i18n( (int) $job['removed'] ),
				size_format( (int) $job['bytes'] ) ?: '0 B'
			);
		}
		return sprintf(
			/* translators: 1: converted files, 2: failed, 3: size */
			__( '完了しました。変換 %1$s ファイル / 失敗 %2$s 枚 / 削減 %3$s', 'wp-plugin-kashiwazaki-next-gen-images' ),
			number_format_i18n( (int) $job['converted'] ),
			number_format_i18n( (int) $job['failed'] ),
			size_format( (int) $job['saved'] ) ?: '0 B'
		);
	}

	/**
	 * 画面に出すための要約（進捗の割合・文章・ログ）。
	 *
	 * @param array|null $job ジョブ。
	 * @return array|null
	 */
	public static function summary( $job ) {
		if ( ! is_array( $job ) ) {
			return null;
		}
		$total    = max( 1, (int) $job['total'] );
		$queue    = ( isset( $job['queue'] ) && is_array( $job['queue'] ) ) ? count( $job['queue'] ) : 0;
		$done     = max( 0, (int) $job['scanned'] - $queue ); // 実際に片付いた枚数（確認しただけで、まだ処理していない分は含めない）。
		$percent  = ( 'done' === $job['state'] ) ? 100 : (int) min( 99, floor( ( $done / $total ) * 100 ) );
		$label    = ( 'remove' === $job['kind'] ) ? __( '削除', 'wp-plugin-kashiwazaki-next-gen-images' ) : __( '変換', 'wp-plugin-kashiwazaki-next-gen-images' );
		$running  = ( 'running' === $job['state'] );
		$stopping = $running && self::stop_requested( $job );

		if ( 'done' === $job['state'] ) {
			$text = self::done_message( $job );
		} else {
			if ( $stopping ) {
				$state_label = __( '停止しています', 'wp-plugin-kashiwazaki-next-gen-images' );
			} elseif ( 'paused' === $job['state'] ) {
				$state_label = sprintf( /* translators: %s: 変換 or 削除 */ __( '%sを一時停止中', 'wp-plugin-kashiwazaki-next-gen-images' ), $label );
			} else {
				$state_label = sprintf( /* translators: %s: 変換 or 削除 */ __( '%s中', 'wp-plugin-kashiwazaki-next-gen-images' ), $label );
			}
			if ( 'remove' === $job['kind'] ) {
				$detail = sprintf(
					/* translators: 1: done, 2: total, 3: removed files */
					__( '%1$s / %2$s 枚を処理（削除 %3$s ファイル）', 'wp-plugin-kashiwazaki-next-gen-images' ),
					number_format_i18n( $done ),
					number_format_i18n( (int) $job['total'] ),
					number_format_i18n( (int) $job['removed'] )
				);
			} else {
				$detail = sprintf(
					/* translators: 1: done, 2: total, 3: converted files, 4: failed, 5: saved */
					__( '%1$s / %2$s 枚を処理（変換 %3$s ファイル・失敗 %4$s・削減 %5$s）', 'wp-plugin-kashiwazaki-next-gen-images' ),
					number_format_i18n( $done ),
					number_format_i18n( (int) $job['total'] ),
					number_format_i18n( (int) $job['converted'] ),
					number_format_i18n( (int) $job['failed'] ),
					size_format( (int) $job['saved'] ) ?: '0 B'
				);
			}
			$text = $state_label . '… ' . $detail;
		}

		// いま処理している画像の名前（動いていることが一目で分かるように）。
		$current = '';
		if ( $running && $queue > 0 ) {
			$title   = wp_strip_all_tags( get_the_title( (int) $job['queue'][0] ) );
			$current = ( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $title, 0, 48, '…' ) : $title );
		}

		$log = array();
		foreach ( array_reverse( (array) $job['log'] ) as $row ) {
			$log[] = array(
				'e' => ! empty( $row['e'] ),
				'm' => (string) $row['m'],
			);
		}
		return array(
			'id'       => (string) $job['id'],
			'kind'     => (string) $job['kind'],
			'state'    => (string) $job['state'],
			'stopping' => $stopping,
			'rev'      => isset( $job['rev'] ) ? (int) $job['rev'] : 0,
			'percent'  => $percent,
			'done'     => $done,
			'total'    => (int) $job['total'],
			'started'  => (int) $job['started'],
			'finished' => (int) $job['finished'],
			'now'      => time(),
			'current'  => $current,
			'text'     => $text,
			'log'      => array_slice( $log, 0, 30 ),
		);
	}

	/**
	 * WP-Cron の次の実行を予約する（動かないサーバーでは、画面を開いている間の処理だけが頼り）。
	 *
	 * @return void
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK );
		}
	}

	/**
	 * WP-Cron からの実行。
	 *
	 * @return void
	 */
	public static function run_cron() {
		try {
			self::tick( 20.0 );
		} catch ( Throwable $e ) {
			unset( $e ); // 次の機会にやり直す。
		}
	}

	/**
	 * 同時に 2 つの処理が進まないようにする鍵を取る。
	 *
	 * @param int $wait 待つ秒数（0 なら待たない）。
	 * @return bool
	 */
	private static function lock( $wait ) {
		global $wpdb;
		$until = microtime( true ) + max( 0, (int) $wait );
		do {
			$value = time() . '-' . wp_generate_password( 8, false );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_NAME, $value ) );
			if ( 1 === (int) $got ) {
				self::$lock_value = $value;
				return true;
			}
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_NAME ) );
			if ( null !== $old && ( time() - (int) $old ) > self::LOCK_TTL ) {
				$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_NAME, $old ) );
				if ( 1 === (int) $swapped ) {
					self::$lock_value = $value;
					return true;
				}
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			if ( microtime( true ) < $until ) {
				usleep( 250000 );
			}
		} while ( microtime( true ) < $until );
		return false;
	}

	/**
	 * 鍵を返す（自分が取った鍵のときだけ）。
	 *
	 * @return void
	 */
	private static function unlock() {
		global $wpdb;
		if ( '' === self::$lock_value ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_NAME,
				'option_value' => self::$lock_value,
			)
		);
		self::$lock_value = '';
	}
}
