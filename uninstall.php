<?php
/**
 * プラグイン削除時の処理。
 *
 * 設定と変換の記録を消す。変換済みファイル（WebP / AVIF）は、設定で「削除する」を選んだ場合だけ消す。
 * 元の画像には一切触れない。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'kashiwazaki_next_gen_images_uninstall_canon_rel' ) ) {
	/**
	 * 登録のパスを、アップロードフォルダからの相対パスの正規の形にそろえる。
	 * アンインストールはプラグインのクラスを読み込まずに動くので、本体の Converter::canon_rel と同じ規則をここにも持つ
	 * (絶対パスはアップロードフォルダ・realpath・最後の「/<フォルダ名>/」で相対にし、連続した / と「.」を除き、「..」は相殺する)。
	 *
	 * @param string $value 登録のパス。
	 * @param string $base  アップロードフォルダ(wp_normalize_path 済み、末尾の / なし)。
	 * @return string
	 */
	function kashiwazaki_next_gen_images_uninstall_canon_rel( $value, $base = '' ) {
		$v = wp_normalize_path( (string) $value );
		if ( '' !== $v && ( '/' === $v[0] || preg_match( '#^[A-Za-z]:/#', $v ) ) ) {
			$rel = null;
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
}

if ( ! function_exists( 'kashiwazaki_next_gen_images_uninstall_reg_keys' ) ) {
	/**
	 * 登録のパスが指し得る「アップロードフォルダからの相対パス」の鍵(小文字)を全部返す(本体の Converter::reg_keys と同じ規則)。
	 * 正規の形・実体のパス(realpath)・末尾の相対パス(アップロードフォルダの表記が合わない絶対パスと、外を指す「../」)。
	 *
	 * @param string $value 登録のパス。
	 * @param string $base  アップロードフォルダ(wp_normalize_path 済み、末尾の / なし)。
	 * @return array
	 */
	function kashiwazaki_next_gen_images_uninstall_reg_keys( $value, $base ) {
		$canon  = kashiwazaki_next_gen_images_uninstall_canon_rel( $value, $base );
		$keys   = array( strtolower( $canon ) );
		$v      = wp_normalize_path( (string) $value );
		$is_abs = ( '' !== $v && ( '/' === $v[0] || preg_match( '#^[A-Za-z]:/#', $v ) ) );
		$rb     = ( '' !== $base ) ? @realpath( $base ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$rb     = ( false !== $rb ) ? wp_normalize_path( $rb ) : false;
		$under  = false;
		if ( $is_abs && '' !== $base ) {
			$under = ( 0 === stripos( $v, $base . '/' ) ) || ( false !== $rb && 0 === stripos( $v, $rb . '/' ) );
		}
		if ( '' !== $base && '' !== $v && false !== $rb ) {
			$rp = @realpath( $is_abs ? $v : $base . '/' . $v ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $rp ) {
				$rp = wp_normalize_path( $rp );
				if ( 0 === stripos( $rp, $rb . '/' ) ) {
					$keys[] = strtolower( substr( $rp, strlen( $rb ) + 1 ) );
				}
			}
		}
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
}

if ( ! function_exists( 'kashiwazaki_next_gen_images_uninstall_path_keys' ) ) {
	/**
	 * いま調べているファイルの「アップロードフォルダからの相対パス」の鍵(小文字)を返す(本体の Converter::path_keys と同じ規則)。
	 * 文字列の正規の形と、実体のパス(ファイルがあれば realpath、無ければフォルダの realpath ＋ ファイル名)。
	 *
	 * @param string $abs  絶対パス。
	 * @param string $base アップロードフォルダ(wp_normalize_path 済み、末尾の / なし)。
	 * @return array
	 */
	function kashiwazaki_next_gen_images_uninstall_path_keys( $abs, $base ) {
		$v    = wp_normalize_path( (string) $abs );
		$keys = array();
		if ( '' === $base || '' === $v ) {
			return $keys;
		}
		if ( 0 === stripos( $v, $base . '/' ) ) {
			$keys[] = strtolower( kashiwazaki_next_gen_images_uninstall_canon_rel( substr( $v, strlen( $base ) + 1 ), $base ) );
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
					$keys[] = strtolower( substr( $rf, strlen( $rb ) + 1 ) );
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}
}

if ( ! function_exists( 'kashiwazaki_next_gen_images_uninstall_files' ) ) {
	/**
	 * 自分で作った変換ファイル（WebP / AVIF）を、他の添付ファイルとして登録されていないものに限って消す。
	 * 1 サイト分。設定で「削除する」を選んだ場合だけ呼ばれる。
	 *
	 * @param string $meta_key 変換の記録のメタキー。
	 * @return void
	 */
	function kashiwazaki_next_gen_images_uninstall_files( $meta_key ) {
		global $wpdb;

		$uploads = wp_get_upload_dir();
		$base    = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ? untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) : '';
		$last    = 0;
		$reg_set = null; // 登録された .webp / .avif の鍵の集合(最初に必要になったとき 1 回だけ作る)。
		while ( '' !== $base ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT 200", $meta_key, $last ) );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$last = (int) $row->meta_id;
				$meta = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $meta ) || empty( $meta['files'] ) ) {
					continue;
				}
				foreach ( $meta['files'] as $rel => $entry ) {
					// 自分で作った .webp / .avif だけを、アップロードフォルダの中に限って消す。
					if ( ! is_string( $rel ) || '' === $rel || '/' === $rel[0] || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) || ! is_array( $entry ) ) {
						continue;
					}
					foreach ( array( 'webp', 'avif' ) as $ext ) {
						// 所有の記録、または作り直しの途中で止まった記録（po）があるものが、自分で作ったファイル。
						if ( empty( $entry[ $ext ]['owned'] ) && empty( $entry['po'][ $ext ] ) ) {
							continue;
						}
						$path = $base . '/' . $rel . '.' . $ext;
						if ( is_file( $path ) ) {
							// 登録の確認は 2 つの SQL。どちらかが失敗したら（結果が null でも「未登録」とは言えない）、そのファイルは消さない。
							$wpdb->last_error = '';
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$registered = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $rel . '.' . $ext ) );
							$check_ok   = ( '' === $wpdb->last_error );
							if ( $check_ok && null === $registered ) {
								// 縮小前の元画像・各サイズ・バックアップとしてメタデータに載っているファイル名とも照合する。
								$name       = wp_basename( $rel . '.' . $ext );
								$needle     = 's:' . strlen( $name ) . ':"' . $name . '";';
								$wpdb->last_error = '';
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery
								$registered = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $needle ) . '%' ) );
								$check_ok   = ( '' === $wpdb->last_error );
							}
							if ( $check_ok && null === $registered ) {
								// 登録のパスが別表記(「2026//10/x.jpg.webp」「../uploads/…」・絶対パス・シンボリックリンク)でも、ファイル自体がリンクで名前が違っても見つけるため、
								// 登録された .webp / .avif の全部について、登録のパスの鍵(正規の形・実体のパス・末尾の相対パス)の集合を、サイトごとに 1 回だけ作り、
								// 調べるファイルの鍵(正規の形・実体のパス)が集合に入っていれば登録済みとする(プラグインの本体の Converter::reg_keys / path_keys と同じ規則)。
								if ( null === $reg_set ) {
									$reg_set          = array();
									// 登録された本体の全部(拡張子を問わない。.jpg などの名前で登録された添付が、変換ファイルへのシンボリックリンクのこともある)を 1000 件ずつ読む。
									$main_after = 0;
									do {
										$wpdb->last_error = '';
										// phpcs:ignore WordPress.DB.DirectDatabaseQuery
										$main_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_id > %d ORDER BY meta_id ASC LIMIT 1000", $main_after ) );
										if ( '' !== $wpdb->last_error ) {
											$reg_set = false; // 読めなかった。消さない。
											break;
										}
										foreach ( (array) $main_rows as $main_row ) {
											$main_after = max( $main_after, (int) $main_row->meta_id );
											foreach ( kashiwazaki_next_gen_images_uninstall_reg_keys( $main_row->meta_value, $base ) as $reg_key ) {
												$reg_set[ $reg_key ] = 1;
											}
										}
									} while ( 1000 === count( (array) $main_rows ) );
									if ( false !== $reg_set ) {
								// 登録された各サイズ・original_image・バックアップの .webp / .avif も同じ鍵の集合に入れる(リンクで登録された各サイズの実体を消さないため)。
								// 一度に大量の行をメモリに読まないよう、meta_id の順に 300 件ずつ読む。
								$md_after = 0;
								do {
									$wpdb->last_error = '';
									// phpcs:ignore WordPress.DB.DirectDatabaseQuery
									$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT md.meta_id AS mid, mf.meta_value AS main_file, md.meta_value AS metadata FROM {$wpdb->postmeta} md INNER JOIN {$wpdb->postmeta} mf ON mf.post_id = md.post_id AND mf.meta_key = '_wp_attached_file' WHERE md.meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND md.meta_value REGEXP %s AND md.meta_id > %d ORDER BY md.meta_id ASC LIMIT 300", '"[^"]*[.](webp|avif)";', $md_after ) );
									if ( '' !== $wpdb->last_error ) {
										$reg_set = false;
										break;
									}
									foreach ( (array) $meta_rows as $meta_row ) {
										$md_after = max( $md_after, (int) $meta_row->mid );
										$mm       = maybe_unserialize( $meta_row->metadata );
										if ( ! is_array( $mm ) || '' === (string) $meta_row->main_file ) {
											continue;
										}
										$md_dir = dirname( (string) $meta_row->main_file );
										$md_nm  = array();
										if ( isset( $mm['original_image'] ) ) {
											$md_nm[] = $mm['original_image'];
										}
										if ( isset( $mm['sizes'] ) && is_array( $mm['sizes'] ) ) {
											foreach ( $mm['sizes'] as $sz ) {
												if ( is_array( $sz ) && isset( $sz['file'] ) ) {
													$md_nm[] = $sz['file'];
												}
											}
										}
										foreach ( $mm as $mk => $mv ) {
											if ( 'sizes' !== $mk && 'original_image' !== $mk && is_array( $mv ) && isset( $mv['file'] ) ) {
												$md_nm[] = $mv['file'];
											}
										}
										foreach ( $md_nm as $md_name ) {
											if ( is_string( $md_name ) && preg_match( '/\.(webp|avif)$/i', $md_name ) && false === strpos( $md_name, '/' ) ) {
												foreach ( kashiwazaki_next_gen_images_uninstall_reg_keys( ( '.' === $md_dir ? '' : $md_dir . '/' ) . $md_name, $base ) as $reg_key ) {
													$reg_set[ $reg_key ] = 1;
												}
											}
										}
									}
								} while ( 300 === count( (array) $meta_rows ) );
									}
								}
								if ( false === $reg_set ) {
									$check_ok = false;
								} else {
									foreach ( kashiwazaki_next_gen_images_uninstall_path_keys( $path, $base ) as $want_key ) {
										if ( isset( $reg_set[ $want_key ] ) ) {
											$check_ok = false; // 登録されている(別表記・リンクを含む)。消さない。
											break;
										}
									}
								}
							}
							if ( $check_ok && null === $registered ) {
								@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
							}
						}
					}
				}
			}
		}
	
	}
}

if ( ! function_exists( 'kashiwazaki_next_gen_images_uninstall_site' ) ) {
	/**
	 * 1 サイト分のデータを消す。
	 *
	 * @return void
	 */
	function kashiwazaki_next_gen_images_uninstall_site() {
		global $wpdb;

		$settings     = get_option( 'kashiwazaki_next_gen_images_settings', array() );
		$delete_files = is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );
		$meta_key     = '_kashiwazaki_next_gen_images';

		if ( $delete_files ) {
			kashiwazaki_next_gen_images_uninstall_files( $meta_key );
		}

		delete_post_meta_by_key( $meta_key );
		delete_post_meta_by_key( '_kashiwazaki_next_gen_images_attempts' );
		delete_option( 'kashiwazaki_next_gen_images_settings' );
		delete_option( 'kashiwazaki_next_gen_images_mime_status' );
		delete_option( 'kashiwazaki_next_gen_images_name_conflicts' );
		delete_option( 'kashiwazaki_next_gen_images_name_conflicts_dirty' );
		delete_option( 'kashiwazaki_next_gen_images_job' );
		delete_option( 'kashiwazaki_next_gen_images_job_stop' );
		delete_option( 'kashiwazaki_next_gen_images_stale_variants' );
		delete_transient( 'kashiwazaki_next_gen_images_stats' );
		wp_unschedule_hook( 'kashiwazaki_next_gen_images_cron_convert' );
		wp_unschedule_hook( 'kashiwazaki_next_gen_images_job_cron' );

		// 処理中の鍵と、エンジン動作確認の一時データ（実行環境ごとに複数ある）を消す。
		$like_lock   = $wpdb->esc_like( 'kashiwazaki_next_gen_images_' ) . '%lock%';
		$like_engine = $wpdb->esc_like( '_transient_kashiwazaki_next_gen_images_engine_test_' ) . '%';
		$like_timeout = $wpdb->esc_like( '_transient_timeout_kashiwazaki_next_gen_images_engine_test_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like_lock, $like_engine, $like_timeout ) );
	}
}

if ( is_multisite() ) {
	$kashiwazaki_next_gen_images_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $kashiwazaki_next_gen_images_sites as $kashiwazaki_next_gen_images_site_id ) {
		switch_to_blog( $kashiwazaki_next_gen_images_site_id );
		kashiwazaki_next_gen_images_uninstall_site();
		restore_current_blog();
	}
} else {
	kashiwazaki_next_gen_images_uninstall_site();
}
