<?php
/**
 * 変換した画像をサイトに表示する部分。
 *
 * 方式は <picture> タグ。元の <img> はそのまま残し、前に <source type="image/avif"> と
 * <source type="image/webp"> を足す。ブラウザが形式に対応していれば変換後の画像を、
 * 対応していなければ元の <img> をそのまま表示する。
 *
 * - サーバー側の書き換えルール（.htaccess 等）は不要。レンタルサーバーでもそのまま動く。
 * - ブラウザごとに内容が変わる仕組み（Accept ヘッダー）を使わないので、ページキャッシュとも衝突しない。
 * - 変換ファイルが存在しない・元画像より古い画像には何もしない（元の <img> のまま）。
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 表示側の処理。
 */
final class Kashiwazaki_Next_Gen_Images_Delivery {

	const CSS_CLASS   = 'kashiwazaki-next-gen-images-picture';
	const MAX_BYTES   = 8388608; // これより大きい HTML は触らない。
	const LAZY_ATTRS  = array( 'data-src', 'data-lazy-src', 'data-srcset', 'data-lazy-srcset', 'data-original', 'data-lazyload', 'data-no-nextgen' );
	const IMAGE_EXTS  = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' );

	/**
	 * リクエスト内のキャッシュ。
	 *
	 * @var array
	 */
	private static $ctx = null;

	/**
	 * 画像ファイルごとの変換ファイルの有無。
	 *
	 * @var array
	 */
	private static $variant_cache = array();

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! Kashiwazaki_Next_Gen_Images_Settings::get( 'delivery_enabled' ) ) {
			return;
		}

		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'print_repair_script' ), 99 );

		if ( 'page' === Kashiwazaki_Next_Gen_Images_Settings::get( 'delivery_scope' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 9999 );
			return;
		}

		// 優先度を遅くして、遅延読み込み系のプラグインなどが先に仕事を終えたあとの HTML を処理する。
		$priority = 9990;
		add_filter( 'the_content', array( __CLASS__, 'filter_html' ), $priority );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'filter_html' ), $priority );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_html' ), $priority );
		add_filter( 'widget_text', array( __CLASS__, 'filter_html' ), $priority );
		add_filter( 'widget_text_content', array( __CLASS__, 'filter_html' ), $priority );
		add_filter( 'widget_block_content', array( __CLASS__, 'filter_html' ), $priority );
	}

	/**
	 * <picture> がレイアウトの邪魔をしないための最小限の CSS。
	 *
	 * @return void
	 */
	public static function print_css() {
		if ( ! Kashiwazaki_Next_Gen_Images_Settings::get( 'delivery_css' ) || ! self::is_front_request() ) {
			return;
		}
		// <source> は本来描画されない要素。テーマの CSS が display を変えていても、flex / grid の親の中で
		// 余白を生まないよう、このプラグインが作った <picture> の中に限って非表示を固定する。
		$class = esc_html( self::CSS_CLASS );
		// border-radius: inherit は <picture> を 1 段はさむと親の値が届かなくなる（WordPress 標準の角丸ブロックが該当）。
		// <picture> 自身にも inherit を付けて、値を中継する。display:contents の要素には描画への影響はない。
		echo '<style id="kashiwazaki-next-gen-images-css">picture.' . $class . '{display:contents;border-radius:inherit}picture.' . $class . '>source{display:none!important}</style>' . "\n";
	}

	/**
	 * 他のプラグインが <img> を別の要素で包み直したとき、<picture> を <img> のすぐ外側に戻す小さなスクリプト。
	 *
	 * ライトボックスなどが、実行時に <img> を <span> などで包むことがある。すると <img> が <picture> の
	 * 直接の子でなくなり、HTML の仕様上、<source>（AVIF / WebP）が無効になって、元の画像が表示される
	 * （しかも、最初に AVIF を取得したあとで元の画像に切り替わるので、通信も無駄になる）。
	 * それを実行時に直す。<picture> の外側へ包みを出し、包みの中で <picture> が <img> を直接包む形に戻す。
	 * 包みの要素と、その中の他の要素（ズームアイコンなど）は、そのまま残る。
	 *
	 * @return void
	 */
	public static function print_repair_script() {
		if ( ! self::is_front_request() ) {
			return;
		}
		$ctx = self::context();
		if ( empty( $ctx['formats'] ) || ! apply_filters( 'kashiwazaki_next_gen_images_repair_script', true ) ) {
			return;
		}
		// 包み直し（設定 repair_wrappers）に関わらず、img と source の整合の確認は常に出す。
		$js = self::build_repair_script( (bool) Kashiwazaki_Next_Gen_Images_Settings::get( 'repair_wrappers' ) );
		echo '<script id="kashiwazaki-next-gen-images-repair">' . $js . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定の文字列と定数だけ。
	}

	/**
	 * 補正スクリプトの文字列を作る。
	 *
	 * (1) 包み直し（W が 1 のときだけ）: 他のプラグインが img を別の要素で包み直したとき、picture を img のすぐ外側に戻す。
	 * (2) 整合の確認（常に）: 他の JS が img の src・srcset を差し替えたら（picture に載せた元の値と一致しなくなったら。日本語などのパーセント符号化の違いだけは同じ値として扱う）、その picture の
	 *     source を取り除く。source が img の属性より優先されるため、残すと差し替えた画像が表示されない。
	 *     最初の値を覚えないので、このスクリプトより前に差し替わっていても、img そのものが置き換わっても見つかる。
	 *
	 * @param bool $repair_wrappers 包み直しをするか。
	 * @return string
	 */
	public static function build_repair_script( $repair_wrappers ) {
		$w = $repair_wrappers ? 1 : 0;
		return '(function(){var S="picture.' . self::CSS_CLASS . '",W=' . $w . ';'
			. 'function f(p){var i=p.querySelector("img");if(!i||i.parentNode===p)return;var t=i;while(t.parentNode!==p)t=t.parentNode;if(t===i)return;'
			. 'p.parentNode.insertBefore(t,p);i.parentNode.insertBefore(p,i);p.appendChild(i)}'
			. 'function e(s){if(s===null)return s;try{return encodeURI(decodeURI(s))}catch(x){return s}}'
			. 'function g(p){var i=p.querySelector("img");if(!i)return;var a=p.getAttribute("data-kngi-src"),b=p.getAttribute("data-kngi-srcset");'
			. 'if(a===null&&b===null)return;if(e(i.getAttribute("src"))!==e(a)||e(i.getAttribute("srcset"))!==e(b)){var c=p.firstChild,n;while(c){n=c.nextSibling;if(c.nodeName==="SOURCE")p.removeChild(c);c=n}}}'
			. 'function r(){var a=document.querySelectorAll(S),n=a.length,p;while(n--){p=a[n];if(W)f(p);g(p)}}'
			. 'function u(m){for(var j=0;j<m.length;j++){var t=m[j].target;while(t&&t.nodeName!=="PICTURE")t=t.parentNode;if(t&&String(t.className).indexOf("' . self::CSS_CLASS . '")>-1)g(t)}}'
			. 'r();if(window.MutationObserver&&document.body){new MutationObserver(r).observe(document.body,{childList:true,subtree:true});'
			. 'new MutationObserver(u).observe(document.body,{attributes:true,subtree:true,attributeFilter:["src","srcset"]})}'
			. 'document.addEventListener("DOMContentLoaded",r);window.addEventListener("load",r)})();';
	}

	/**
	 * 画面表示用のリクエストか（管理画面・REST・フィード・AJAX などでは何もしない）。
	 *
	 * @return bool
	 */
	private static function is_front_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}
		return true;
	}

	/**
	 * 配信に使う形式（優先順）。配信テストで問題が出た形式は外す。
	 *
	 * @return string[]
	 */
	public static function delivery_formats() {
		$status  = get_option( Kashiwazaki_Next_Gen_Images_Settings::MIME_OPTION, array() );
		$enabled = Kashiwazaki_Next_Gen_Images_Settings::enabled_formats();
		$formats = array();
		foreach ( Kashiwazaki_Next_Gen_Images_Settings::FORMATS as $format ) {
			if ( ! in_array( $format, $enabled, true ) ) {
				continue;
			}
			if ( is_array( $status ) && isset( $status[ $format ] ) && 'ng' === $status[ $format ] ) {
				continue;
			}
			$formats[] = $format;
		}
		return $formats;
	}

	/**
	 * リクエスト内で共通に使う情報を用意する。
	 *
	 * @return array
	 */
	private static function context() {
		if ( null !== self::$ctx ) {
			return self::$ctx;
		}
		$uploads = wp_get_upload_dir();
		$ctx     = array(
			'ok'         => false,
			'basedir'    => '',
			'base_path'  => '',
			'hosts'      => array(),
			'formats'    => self::delivery_formats(),
			'classes'    => array_map( 'strtolower', Kashiwazaki_Next_Gen_Images_Settings::lines( 'exclude_classes' ) ),
			'exclude'    => Kashiwazaki_Next_Gen_Images_Settings::lines( 'exclude_urls' ),
			'active'     => false,
			'conflicts'  => array(),
		);
		if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) && empty( $uploads['error'] ) ) {
			$base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
			$hosts     = array();
			foreach ( array( $uploads['baseurl'], home_url(), site_url() ) as $url ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( $host ) {
					$hosts[ strtolower( $host ) . self::port_suffix( wp_parse_url( $url, PHP_URL_PORT ) ) ] = true; // ポートが違えば別のオリジン。
				}
			}
			$ctx['ok']        = ( '' !== $base_path );
			$ctx['basedir']   = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
			$ctx['base_path'] = untrailingslashit( $base_path );
			$ctx['hosts']     = array_keys( $hosts );
		}
		$ctx['active'] = $ctx['ok'] && ! empty( $ctx['formats'] ) && self::is_front_request();
		if ( $ctx['active'] ) {
			// 「photo.jpg.webp」のような名前の画像が別に登録されている場合の誤配信を避けるための台帳（通常は空）。
			$ctx['conflicts'] = array_merge( Kashiwazaki_Next_Gen_Images_Converter::conflict_files(), Kashiwazaki_Next_Gen_Images_Converter::stale_files() );
			// 台帳を確定できたかは、この写しを取った時点の結果を、写しと一緒に持つ。あとで別の経路が台帳を確定しても、この写しが古いことは変わらない。
			$ctx['ledger_unknown'] = Kashiwazaki_Next_Gen_Images_Converter::ledger_unknown();
		}
		self::$ctx     = $ctx;
		return $ctx;
	}

	/**
	 * フィルター用の入口（HTML 断片）。何があっても元の HTML を返せるようにしてある。
	 *
	 * @param mixed $html HTML。
	 * @return mixed
	 */
	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 10 || stripos( $html, '<img' ) === false ) {
			return $html;
		}
		try {
			return self::transform( $html );
		} catch ( Throwable $e ) {
			return $html;
		}
	}

	/**
	 * ページ全体を対象にする場合の出力バッファ開始。
	 *
	 * @return void
	 */
	public static function start_buffer() {
		$ctx = self::context();
		if ( ! $ctx['active'] || ( function_exists( 'is_embed' ) && is_embed() ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' === $method ) {
			return;
		}
		ob_start( array( __CLASS__, 'buffer_callback' ) );
	}

	/**
	 * 出力バッファの後処理。HTML 以外や、処理に失敗したときは何も変えない。
	 *
	 * @param string $buffer 出力。
	 * @return string
	 */
	public static function buffer_callback( $buffer ) {
		try {
			if ( ! is_string( $buffer ) || '' === $buffer || stripos( $buffer, '<img' ) === false ) {
				return $buffer;
			}
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
					return $buffer;
				}
			}
			if ( stripos( $buffer, '<html' ) === false && stripos( $buffer, '<body' ) === false ) {
				return $buffer;
			}
			return self::transform( $buffer );
		} catch ( Throwable $e ) {
			return $buffer;
		}
	}

	/**
	 * 開始タグまたは閉じタグ（「<」の位置から）の終端（「>」の次の位置）を、HTML のトークナイザー（WHATWG）の読み方で探す。
	 *
	 * タグ名は、空白・「/」・「>」の手前まで。属性は、名前（先頭の「=」も名前の一部。「"」「'」「<」も入る）、「=」、値の順で、
	 * 値が引用符で囲まれるのは「=」の直後（空白を除く）に引用符があるときだけ。引用符なしの値は、空白・「>」の手前まで（「/」は含む）。
	 * 属性名や引用符なしの値の途中にある「"」「'」は、引用の始まりではない。閉じタグにも属性が書け（仕様違反だが読み飛ばされる）、同じ読み方をする。
	 *
	 * @param string $html HTML。
	 * @param int    $at   「<」の位置。
	 * @param bool   $self 戻り値。「/>」で終わり、その「/」が自己終了フラグ（引用符なしの属性値の末尾の「/」ではない）のとき true。
	 * @return int|false 終端の次の位置。閉じていなければ false。
	 */
	private static function tag_end( $html, $at, &$self = null ) {
		$self = false;
		$len  = strlen( $html );
		$ws   = "\t\n\f\r ";
		$i    = $at + 1;
		if ( $i < $len && '/' === $html[ $i ] ) {
			++$i;
		}
		$i += strcspn( $html, $ws . '/>', $i ); // タグ名。
		while ( $i < $len ) {
			$sp = strspn( $html, $ws . '/', $i );
			$i += $sp;
			if ( $i >= $len ) {
				return false;
			}
			if ( '>' === $html[ $i ] ) {
				$self = $sp > 0 && '/' === $html[ $i - 1 ]; // 空白・「/」の並びの最後が「/」のときだけ自己終了フラグ。引用符なしの値の途中の「/」は値の一部（上の strcspn が読む）。
				return $i + 1;
			}
			++$i; // 属性名の最初の 1 文字は、「=」でも名前の一部。
			$i += strcspn( $html, $ws . '/>=', $i );
			$i += strspn( $html, $ws, $i );
			if ( $i < $len && '=' === $html[ $i ] ) {
				++$i;
				$i += strspn( $html, $ws, $i );
				if ( $i < $len && ( '"' === $html[ $i ] || "'" === $html[ $i ] ) ) {
					$e = strpos( $html, $html[ $i ], $i + 1 );
					if ( false === $e ) {
						return false;
					}
					$i = $e + 1;
				} else {
					$i += strcspn( $html, $ws . '>', $i );
				}
			}
		}
		return false;
	}

	/**
	 * コメント「<!--」の終端（次の位置）。HTML の仕様どおり、「<!-->」「<!--->」はすぐ閉じる空のコメント。それ以外は「-->」または「--!>」で閉じる。
	 *
	 * @param string $html  HTML。
	 * @param int    $start 「<!--」の位置。
	 * @return int|false 終端の次の位置。閉じていなければ false。
	 */
	private static function comment_end( $html, $start ) {
		$i = $start + 4;
		if ( '>' === substr( $html, $i, 1 ) ) {
			return $i + 1;
		}
		if ( '->' === substr( $html, $i, 2 ) ) {
			return $i + 2;
		}
		if ( preg_match( '~--!?>~', $html, $m, PREG_OFFSET_CAPTURE, $i ) ) {
			return $m[0][1] + strlen( $m[0][0] );
		}
		return false;
	}

	/**
	 * 閉じタグ「</名前 …>」の終端（「>」の次の位置）。tag_end と同じ読み方。
	 *
	 * @param string $html HTML。
	 * @param int    $at   閉じタグ「</」の位置。
	 * @return int|false 終端の次の位置。閉じていなければ false。
	 */
	private static function close_tag_end( $html, $at ) {
		return self::tag_end( $html, $at );
	}

	/**
	 * 生の文字列の要素(script・style・textarea・title・iframe・noscript)の閉じタグ「</要素名」の位置を探す。
	 *
	 * script だけは、HTML の仕様の「script data escaped / double escaped」の状態を持つ。「<!--」の後ろの「<script」から「-->」までは、その中の「</script」で
	 * script は終わらない(二重にエスケープされた状態では、「</script」は 1 段戻るだけ)。それ以外の要素は、最初の閉じタグで終わる。
	 * 閉じタグは「</要素名」の直後が空白・スラッシュ・閉じかっこのものだけ。
	 *
	 * @param string $html HTML。
	 * @param string $name 要素名(小文字)。
	 * @param int    $from 開始タグの直後の位置。
	 * @return int|false 閉じタグ「</」の位置。無ければ false。
	 */
	private static function raw_close( $html, $name, $from ) {
		if ( 'plaintext' === $name ) {
			return false; // plaintext は閉じない(以降はすべて文字列)。閉じタグ風の文字列があっても閉じタグではない。
		}
		if ( 'script' !== $name ) {
			return preg_match( '~</' . preg_quote( $name, '~' ) . '(?=[\t\n\f\r />])~i', $html, $cm, PREG_OFFSET_CAPTURE, $from ) ? $cm[0][1] : false; // HTML の空白は TAB・LF・FF・CR・SPACE だけ(PCRE の \s は VT を含む)。
		}
		$state = 0; // 0: script data / 1: escaped(「<!--」の後) / 2: double escaped(escaped の中の「<script」の後)。
		$p     = $from;
		while ( preg_match( '~<!--|-->|<script(?=[\t\n\f\r />])|</script(?=[\t\n\f\r />])~i', $html, $m, PREG_OFFSET_CAPTURE, $p ) ) {
			$tok = strtolower( $m[0][0] );
			$at  = $m[0][1];
			$p   = $at + strlen( $m[0][0] );
			if ( '<!--' === $tok ) {
				// 「<!-->」「<!--->」は、「>」で script data に戻る(仕様の script data escaped dash dash / double escaped dash dash の状態)。escaped・double escaped の中でも同じ
				// (「<!--」と「-->」が重なるので、「-->」の字句としては拾えない)。
				$back = '>' === substr( $html, $p, 1 ) || '->' === substr( $html, $p, 2 );
				if ( 0 === $state ) {
					if ( ! $back ) {
						$state = 1;
					}
				} elseif ( $back ) {
					$state = 0;
				}
			} elseif ( '-->' === $tok ) {
				$state = 0;
			} elseif ( '<script' === $tok ) {
				if ( 1 === $state ) {
					$state = 2;
				}
			} else { // </script
				if ( 2 === $state ) {
					$state = 1;
				} else {
					return $at;
				}
			}
		}
		return false;
	}

	/**
	 * 保護する要素（picture・svg・head など。中身が生の文字列ではないもの）の閉じタグの位置を探す。
	 *
	 * 中身を「コメント」「開始タグ（属性値ごと読み飛ばす）」「閉じタグ」の順にたどる。中の子要素は、
	 * - script・style・textarea・title・iframe・noscript: 中身が生の文字列。開始タグが「/>」で終わっていても（HTML では script の「/>」は自己終了にならない）、閉じタグまでまとめて読み飛ばす。
	 * - template・select・canvas・video・audio・object・picture・head・svg・math: 子もそれぞれの閉じタグまで入れ子として読み飛ばす（中の閉じタグ風の文字列は、外側の閉じタグではない）。svg・math だけは「/>」で自己終了する。
	 * 入れ子が深すぎる、閉じていない、のときは false（呼び出し側は、以降の変換をしない）。
	 *
	 * @param string $html  HTML。
	 * @param string $name  探す閉じタグの要素名（小文字）。
	 * @param int    $from  開始タグの直後の位置。
	 * @param int    $level 入れ子の深さ。
	 * @return int|false 閉じタグ「</」の位置。
	 */
	private static function find_close( $html, $name, $from, $level, $foreign = false ) {
		if ( $level > 48 ) {
			return false;
		}
		// svg・math の中(外来コンテンツ)かどうか。入れ子の呼び出しにも引き継ぐ(svg の中の video の中も外来コンテンツ)。
		// 外来コンテンツの script・style・title・CDATA・自己終了形は、HTML の読み方と外来の読み方で終わりの位置が変わり得る。HTML の統合点(svg の foreignObject・math の mtext など)の中は
		// HTML の読み方で、それ以外は外来の読み方だが、この層では見分けられない。そこで、2 通りの読み方が同じ位置で終わるとき(普通の <title>・<style>・>を含まない CDATA)だけ読み進め、
		// 食い違うとき(CDATA の中の「>」や閉じタグ風の文字列、属性値の中の閉じタグ風の文字列、自己終了形)は false を返す(呼び出し側は、以降の変換をしない。画像は元のまま表示される安全側)。
		if ( 'svg' === $name || 'math' === $name ) {
			$foreign = true;
		}
		$raw    = array( 'script', 'style', 'textarea', 'title', 'iframe', 'noscript', 'xmp', 'noembed', 'noframes', 'plaintext' );
		$nested = array( 'template', 'select', 'canvas', 'video', 'audio', 'object', 'picture', 'head', 'svg', 'math' );
		$p      = $from;
		while ( preg_match( '~<!--|<[?!]|</(?![a-z>])|<(/?)([a-z][^\t\n\f\r />]*)~i', $html, $mm, PREG_OFFSET_CAPTURE, $p ) ) {
			$at = $mm[0][1];
			if ( '<!--' === $mm[0][0] ) {
				$ce = self::comment_end( $html, $at );
				if ( false === $ce ) {
					return false; // コメントが閉じていない。
				}
				$p = $ce;
				continue;
			}
			// svg・math の中(外来コンテンツ)の「<![CDATA[」は、外来の読み方では「]]>」までの CDATA セクション(中の「>」や閉じタグ風の文字列は、ただの文字列)。
			// HTML の読み方(統合点の中・HTML の中)では偽のコメントで、最初の「>」まで。2 通りの読み方が同じ位置で終わるときだけ読み進め、食い違うときは false を返す。
			if ( $foreign && '<!' === $mm[0][0] && 0 === substr_compare( $html, '<![CDATA[', $at, 9 ) ) {
				$cd = strpos( $html, ']]>', $at + 9 );
				if ( false === $cd ) {
					return false; // CDATA が閉じていない。
				}
				$be_html = strpos( $html, '>', $at + 2 );
				if ( false === $be_html || $be_html + 1 !== $cd + 3 ) {
					return false; // CDATA の中に「>」がある。HTML の読み方(偽のコメント)と終わりが違う。
				}
				$p = $cd + 3;
				continue;
			}
			if ( ! isset( $mm[2] ) || '' === $mm[2][0] ) { // 「<?」「<!」「</」(後が英字でも「>」でもない): 偽のコメント(bogus comment)。最初の「>」まで。(「<a」「<b」のような 1 文字のタグ名の開始タグは、ここに入らない。)
				$be = strpos( $html, '>', $at + 2 );
				if ( false === $be ) {
					return false;
				}
				$p = $be + 1;
				continue;
			}
			$tname = strtolower( $mm[2][0] );
			$after = substr( $html, $at + strlen( $mm[0][0] ), 1 );
			if ( '/' === $mm[1][0] ) { // 閉じタグ。
				if ( $tname === $name && '' !== $after && false !== strpos( " \t\n\r\f/>", $after ) ) {
					return $at;
				}
				// 探している要素以外の閉じタグも、属性値ごと読み飛ばす(属性値の中の「</picture>」などを、閉じタグと誤認しない)。
				$te = self::tag_end( $html, $at );
				if ( false === $te ) {
					return false; // 閉じていないタグ。文書の終わりまでがそのタグなので、閉じタグは見つからない。
				}
				$p = $te;
				continue;
			}
			// 開始タグ: 属性値ごと末尾まで読み飛ばす。
			$tend = self::tag_end( $html, $at, $self_closing );
			if ( false === $tend ) {
				return false; // 閉じていないタグ。文書の終わりまでがそのタグなので、閉じタグは見つからない。
			}
			$p = $tend;
			// 外来コンテンツの生の文字列の要素(script・style・textarea・title・iframe・noscript・xmp・noembed・noframes)が自己終了形で終わるものは、外来の読み方では中身の無い要素、
			// HTML の読み方(統合点の中)では自己終了しない。読み方が食い違うので読み進めない。
			if ( $foreign && $self_closing && in_array( $tname, $raw, true ) ) {
				return false;
			}
			if ( in_array( $tname, $raw, true ) ) {
				$rc_pos = self::raw_close( $html, $tname, $p );
				if ( false === $rc_pos ) {
					return false;
				}
				// 外来コンテンツの生の文字列の要素は、外来の読み方(CDATA を飛ばし、子タグは属性値ごと読む)の終わりと、HTML の生の文字列の終わりが同じ位置のときだけ読み進める。
				// さらに、中身に子の開始タグがあると、統合点(svg の title・desc・foreignObject)の中の子は HTML の挿入モードで読まれる 3 つ目の読み方になるので、読み進めない(CDATA・コメントの中は除く)。
				if ( $foreign ) {
					$fc_pos = self::foreign_close( $html, $tname, $p );
					if ( false === $fc_pos || $fc_pos !== $rc_pos || ! self::foreign_text_only( $html, $p, $rc_pos ) ) {
						return false;
					}
				}
				$rgt = self::close_tag_end( $html, $rc_pos );
				if ( false === $rgt ) {
					return false;
				}
				$p = $rgt;
				continue;
			}
			if ( in_array( $tname, $nested, true ) && ! ( ( 'svg' === $tname || 'math' === $tname ) && $self_closing ) ) {
				$cl = self::find_close( $html, $tname, $p, $level + 1, $foreign );
				if ( false === $cl ) {
					return false;
				}
				$rgt = self::close_tag_end( $html, $cl );
				if ( false === $rgt ) {
					return false;
				}
				$p = $rgt;
			}
		}
		return false;
	}

	/**
	 * 外来コンテンツ(svg・math)の中の要素の閉じタグを探す。中身は普通の入れ子で、CDATA セクション(「<![CDATA[ … ]]>」)の中の文字列は飛ばす。
	 *
	 * @param string $html  HTML。
	 * @param string $tname 要素名(小文字)。
	 * @param int    $from  開始タグの直後の位置。
	 * @return int|false 閉じタグ「</」の位置。CDATA が閉じていない・閉じタグが無いときは false。
	 */
	private static function foreign_close( $html, $tname, $from ) {
		$p       = $from;
		$pattern = '~(?-i:<!\[CDATA\[)|<!--|<(/?)([a-z][^\t\n\f\r />]*)~i'; // 「[CDATA[」は大文字小文字を区別する(HTML の markup declaration open state)。小文字は偽のコメント。
		while ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $p ) ) {
			$at = $m[0][1];
			if ( '<![CDATA[' === $m[0][0] ) {
				$ce = strpos( $html, ']]>', $at + 9 );
				if ( false === $ce ) {
					return false;
				}
				$p = $ce + 3;
				continue;
			}
			if ( '<!--' === $m[0][0] ) {
				$ce = self::comment_end( $html, $at );
				if ( false === $ce ) {
					return false;
				}
				$p = $ce;
				continue;
			}
			$after = substr( $html, $at + strlen( $m[0][0] ), 1 );
			if ( '/' === $m[1][0] && strtolower( $m[2][0] ) === $tname && '' !== $after && false !== strpos( " \t\n\r\f/>", $after ) ) {
				return $at;
			}
			// 探している閉じタグ以外のタグは、属性値ごと読み飛ばす(属性値の中の「</script>」などを、閉じタグと誤認しない)。
			$te = self::tag_end( $html, $at );
			if ( false === $te ) {
				return false;
			}
			$p = $te;
		}
		return false;
	}

	/**
	 * 外来コンテンツの生の文字列の要素の中身が、文字列と、CDATA・コメントだけか(タグ・閉じタグ・偽のコメントを含まないか)どうか。
	 *
	 * 子の開始タグがあると、統合点の中では HTML の挿入モードで読まれ、子が script・textarea などなら生の文字列になる(外来の読み方とも、要素自身の生の文字列の読み方とも違う 3 つ目の読み方)。
	 * 閉じタグは、祖先(svg など)の閉じタグなら外来コンテンツから出てしまう。「<!」「<?」で始まる偽のコメントは、中の CDATA の始まりや閉じタグ風の文字列を飲み込む。
	 * どれも、2 通りの読み方が同じ位置で終わっても、そこまでの読み方が違い得るので、読み進めない。
	 *
	 * @param string $html HTML。
	 * @param int    $from 中身の始まり。
	 * @param int    $to   中身の終わり(閉じタグ「</」の位置)。
	 * @return bool タグ・閉じタグ・偽のコメントが無ければ true。CDATA・コメントが閉じていないときも false。
	 */
	private static function foreign_text_only( $html, $from, $to ) {
		$p = $from;
		while ( $p < $to && preg_match( '~<!\[CDATA\[|<!--|</|<[a-z!?]~i', $html, $m, PREG_OFFSET_CAPTURE, $p ) && $m[0][1] < $to ) {
			$at = $m[0][1];
			if ( '<![CDATA[' === $m[0][0] ) {
				$ce = strpos( $html, ']]>', $at + 9 );
				if ( false === $ce ) {
					return false;
				}
				$p = $ce + 3;
			} elseif ( '<!--' === $m[0][0] ) {
				$ce = self::comment_end( $html, $at );
				if ( false === $ce ) {
					return false;
				}
				$p = $ce;
			} else {
				return false;
			}
		}
		return true;
	}

	/**
	 * HTML の中の <img> を <picture> で包む。
	 *
	 * script・style・コメント・既存の <picture> など、触ってはいけない範囲は読み飛ばす。
	 * 正規表現で全体を一度に処理せず、先頭から順に読むので、巨大な HTML でも破綻しにくい。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	public static function transform( $html ) {
		$ctx = self::context();
		if ( ! $ctx['active'] || strlen( $html ) > self::MAX_BYTES ) {
			return $html;
		}

		$skip_tags = 'script|style|noscript|textarea|template|picture|svg|iframe|object|head|title|select|math|canvas|video|audio|xmp|noembed|noframes|plaintext';
		// 開始タグ・閉じタグ 1 つ分の終端は、tag_end（HTML のトークナイザーの読み方）で探す。属性値の中の「<img」や閉じタグ風の文字列を、タグと誤認しないために使う。
		$scan      = '~<(?:(!--)|(' . $skip_tags . ')(?=[\t\n\f\r />])|(img)(?=[\t\n\f\r />])|([a-z][^\t\n\f\r />]*+)(?=[\t\n\f\r />])|(/[a-z][^\t\n\f\r />]*+)|([?!]|/(?![a-z>])))~i';

		$out    = '';
		$copied = 0;
		$pos    = 0;

		while ( preg_match( $scan, $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = $m[0][1];

			if ( isset( $m[1] ) && '' !== $m[1][0] ) { // コメント。
				$end = self::comment_end( $html, $start );
				if ( false === $end ) {
					break;
				}
				$pos = $end;
				continue;
			}

			if ( isset( $m[6] ) && '' !== $m[6][0] ) { // 「<?」「<!」「</ 」: 偽のコメント(bogus comment)。最初の「>」まで。中の img は表示されないので、触らない。
				$be = strpos( $html, '>', $start + 2 );
				if ( false === $be ) {
					break;
				}
				$pos = $be + 1;
				continue;
			}

			if ( isset( $m[2] ) && '' !== $m[2][0] ) { // 読み飛ばす要素。
				$name = strtolower( $m[2][0] );
				// 開始タグを、属性値ごと末尾まで読み飛ばしてから、閉じタグを探す（属性値の中の閉じタグ風の文字列を、閉じタグと誤認しない）。
				$tag_end = self::tag_end( $html, $start, $self_closing );
				if ( false === $tag_end ) {
					break; // 開始タグが閉じていない。以降は触らない。
				}
				// 自己終了形の svg・math（例: <svg .../>）は、対応する閉じタグが無い。そのタグ 1 つだけを読み飛ばして続ける（以降の変換を止めない）。
				// 引用符なしの属性値の末尾の「/」（<svg data-x=a/>）は自己終了ではない。
				if ( ( 'svg' === $name || 'math' === $name ) && $self_closing ) {
					$pos = $tag_end;
					continue;
				}
				// script・style・textarea・title・iframe・noscript は、中身が生の文字列（コメントも属性も文字列）。閉じタグは「</要素名」の直後が空白・スラッシュ・閉じかっこのものだけ
				// （「</scripture>」や「</stylex>」を閉じタグと誤認しない）。
				$raw_text = in_array( $name, array( 'script', 'style', 'textarea', 'title', 'iframe', 'noscript', 'xmp', 'noembed', 'noframes', 'plaintext' ), true );
				$from     = $tag_end;
				$close    = false;
				if ( $raw_text ) {
					$close = self::raw_close( $html, $name, $from );
				} else {
					// それ以外の要素（picture・svg・video など）は、中のコメントと、中の開始タグの属性値（引用符で囲まれた値）に閉じタグ風の文字列があっても、閉じタグではない。
					// 中身を「コメント」「開始タグ」「閉じタグ」の順にたどり、入れ子の要素はそれぞれの閉じタグまでまとめて読み飛ばす。
					$close = self::find_close( $html, $name, $from, 0 );
				}
				if ( false === $close ) {
					break; // 閉じていない。以降は触らない。
				}
				$gt = self::close_tag_end( $html, $close );
				if ( false === $gt ) {
					break;
				}
				$pos = $gt;
				continue;
			}

			if ( ( isset( $m[4] ) && '' !== $m[4][0] ) || ( isset( $m[5] ) && '' !== $m[5][0] ) ) { // その他の開始タグ・閉じタグ。属性値の中の「<img」を拾わないよう、タグごと読み飛ばす。
				$te = self::tag_end( $html, $start );
				if ( false === $te ) {
					break; // 閉じていないタグ。HTML では文書の終わりまでがそのタグ(EOF in tag)なので、以降は触らない(未閉じのタグが大量にあっても、二乗の時間にならない)。
				}
				$pos = $te;
				continue;
			}

			// <img>。
			$img_end = self::tag_end( $html, $start );
			if ( false === $img_end ) {
				break; // 閉じていない img。文書の終わりまでがそのタグなので、以降は触らない。
			}
			$tag = substr( $html, $start, $img_end - $start );
			$end = $img_end;
			$new = self::wrap_img( $tag, $ctx );
			if ( null !== $new ) {
				$out   .= substr( $html, $copied, $start - $copied ) . $new;
				$copied = $end;
			}
			$pos = $end;
		}

		if ( preg_last_error() !== PREG_NO_ERROR ) {
			return $html; // 正規表現の内部エラー。何も変えない。
		}
		if ( 0 === $copied ) {
			return $html;
		}
		return $out . substr( $html, $copied );
	}

	/**
	 * <img> 1 つを <picture> で包んだ文字列にする。包む必要が無ければ null。
	 *
	 * @param string $tag <img ...> の文字列。
	 * @param array  $ctx 共通情報。
	 * @return string|null
	 */
	private static function wrap_img( $tag, array $ctx ) {
		$attrs = self::parse_attributes( $tag );
		if ( empty( $attrs['src'] ) || ! is_string( $attrs['src'] ) ) {
			return null;
		}

		foreach ( self::LAZY_ATTRS as $lazy ) {
			if ( isset( $attrs[ $lazy ] ) ) {
				return null; // 遅延読み込みの仕組みが画像を差し替えるものは触らない。
			}
		}
		if ( ! empty( $attrs['class'] ) && is_string( $attrs['class'] ) && ! empty( $ctx['classes'] ) ) {
			$classes = preg_split( '/[\t\n\f\r ]+/', strtolower( trim( $attrs['class'] ) ) );
			if ( array_intersect( $classes, $ctx['classes'] ) ) {
				return null;
			}
		}
		foreach ( $ctx['exclude'] as $needle ) {
			if ( '' !== $needle && false !== stripos( $attrs['src'], $needle ) ) {
				return null;
			}
		}
		if ( apply_filters( 'kashiwazaki_next_gen_images_skip_image', false, $tag, $attrs ) ) {
			return null;
		}

		// 候補（srcset があればその全部、無ければ src）。
		$srcset = ( isset( $attrs['srcset'] ) && is_string( $attrs['srcset'] ) && '' !== trim( $attrs['srcset'] ) ) ? self::parse_srcset( $attrs['srcset'] ) : null;
		if ( null !== $srcset && empty( $srcset ) ) {
			return null; // 読み取れない srcset は触らない。
		}
		$has_srcset = ( null !== $srcset );
		$cands      = $has_srcset ? $srcset : array(
			array(
				'url'  => html_entity_decode( $attrs['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'desc' => '',
			),
		);

		// srcset が密度の記述子(2x など)だけで 1x の候補が無いとき、ブラウザは src を暗黙の 1x の候補として加える。
		// <source> の srcset はこの暗黙の候補を持たないので、src を 1x として足さないと、DPR 1 でも srcset の側(2x)の画像が選ばれてしまう。
		// 幅の記述子(w)がある srcset では、src は候補に加わらない。
		if ( $has_srcset ) {
			$has_w  = false;
			$has_1x = false;
			foreach ( $cands as $cand ) {
				if ( preg_match( '/^\d+w$/i', $cand['desc'] ) ) {
					$has_w = true;
				}
				if ( '' === $cand['desc'] || preg_match( '/^1(\.0*)?x$/i', $cand['desc'] ) ) {
					$has_1x = true;
				}
			}
			if ( ! $has_w && ! $has_1x ) {
				$cands[] = array(
					'url'  => html_entity_decode( $attrs['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'desc' => '1x',
				);
			}
		}

		$mapped = array();
		foreach ( $cands as $i => $cand ) {
			$mapped[ $i ] = self::map_url( $cand['url'], $ctx );
		}

		// 形式ごとに <source> を作る。ブラウザは「対応している最初の <source>」だけを使い、その中の候補から選ぶ。
		// そのため、ある形式の <source> に「その形式が無いサイズ」の候補があると、そのサイズでは元の画像が使われ、
		// 次の形式（WebP など）が使えるのに使われない。そこで、各候補を「その形式 → 次の形式 → 元の画像」の
		// 順で、実際にあるもののうち一番良いものにする。
		$sources = '';
		$order   = array_values( $ctx['formats'] );
		foreach ( $order as $position => $format ) {
			$chain = array_slice( $order, $position );
			$any   = false;
			$items = array();
			foreach ( $cands as $i => $cand ) {
				$url = $cand['url'];
				$map = $mapped[ $i ];
				if ( $map ) {
					foreach ( $chain as $try ) {
						if ( self::has_variant( $map['abs'], $try ) ) {
							$url = $map['clean'] . '.' . $try . $map['suffix'];
							if ( $try === $format ) {
								$any = true;
							}
							break;
						}
					}
				}
				$items[] = ( '' !== $cand['desc'] ) ? $url . ' ' . $cand['desc'] : $url;
			}
			if ( ! $any ) {
				continue; // この形式のファイルが 1 つも無ければ、この形式の <source> は作らない。
			}
			$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( implode( ', ', $items ) ) . '"';
			if ( $has_srcset && isset( $attrs['sizes'] ) && is_string( $attrs['sizes'] ) && '' !== trim( $attrs['sizes'] ) ) {
				$sources .= ' sizes="' . esc_attr( html_entity_decode( $attrs['sizes'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '"';
			}
			$sources .= '>';
		}
		if ( '' === $sources ) {
			return null;
		}

		// 元の img の src と srcset を記録する。補正スクリプトが、あとで他の JS が img を差し替えたかを、完全一致で判定するために使う。
		$data = ' data-kngi-src="' . esc_attr( $attrs['src'] ) . '"';
		if ( isset( $attrs['srcset'] ) && is_string( $attrs['srcset'] ) ) {
			$data .= ' data-kngi-srcset="' . esc_attr( $attrs['srcset'] ) . '"';
		}
		return '<picture class="' . self::CSS_CLASS . '"' . $data . '>' . $sources . $tag . '</picture>';
	}

	/**
	 * オリジンの比較に使うポートの印。省略・80・443 は同じ(印なし)。それ以外のポートは「:ポート」。
	 * (http と https で既定のポートが違っても、サイトの設定と実際の URL の食い違いで変換を止めないよう、80・443 は区別しない。)
	 *
	 * @param mixed $port ポート(整数・文字列・null)。
	 * @return string
	 */
	private static function port_suffix( $port ) {
		$port = (int) $port;
		return ( $port > 0 && 80 !== $port && 443 !== $port ) ? ':' . $port : '';
	}

	/**
	 * URL をアップロードフォルダ内のファイルに対応づける。サイト外・アップロード外の URL は null。
	 *
	 * @param string $url URL。
	 * @param array  $ctx 共通情報。
	 * @return array|null abs（絶対パス）, clean（クエリ抜きの URL）, suffix（クエリ・フラグメント）。
	 */
	private static function map_url( $url, array $ctx ) {
		$url = trim( $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return null;
		}
		$cut    = strcspn( $url, '?#' );
		$clean  = substr( $url, 0, $cut );
		$suffix = substr( $url, $cut );
		$parts  = wp_parse_url( $clean );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return null;
		}
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( isset( $parts['host'] ) && ! in_array( strtolower( $parts['host'] ) . self::port_suffix( isset( $parts['port'] ) ? $parts['port'] : null ), $ctx['hosts'], true ) ) {
			return null; // CDN など、ファイルの有無を確かめられない配信元。ポートが違う別のサービスも同じ。
		}
		$path = $parts['path'];
		if ( 0 !== strpos( $path, $ctx['base_path'] . '/' ) ) {
			return null;
		}
		$rel = rawurldecode( substr( $path, strlen( $ctx['base_path'] ) + 1 ) );
		// 「//」「/./」を含む別の表記は、台帳（正規の相対パスで持つ）と照合できないので、正規の形にそろえる（空の区切りと「.」を取り除く）。
		$rel = implode(
			'/',
			array_filter(
				explode( '/', $rel ),
				function ( $seg ) {
					return '' !== $seg && '.' !== $seg;
				}
			)
		);
		if ( ! Kashiwazaki_Next_Gen_Images_Converter::is_safe_relative_path( $rel ) ) {
			return null;
		}
		if ( ! in_array( strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ), self::IMAGE_EXTS, true ) ) {
			return null;
		}
		return array(
			'abs'    => $ctx['basedir'] . '/' . $rel,
			'clean'  => $clean,
			'suffix' => $suffix,
		);
	}

	/**
	 * 配信可否のキャッシュを捨てる。変換ファイルの作成・削除・空にする・使わない一覧の更新のたびに、変換側が呼ぶ。
	 * 同じリクエストの中で、先に「使える」と判断した結果が残らないようにする。
	 *
	 * @return void
	 */
	public static function reset_variant_cache() {
		self::$variant_cache = array();
		if ( null !== self::$ctx && ! empty( self::$ctx['active'] ) ) {
			self::$ctx['conflicts']      = array_merge( Kashiwazaki_Next_Gen_Images_Converter::conflict_files(), Kashiwazaki_Next_Gen_Images_Converter::stale_files() );
			self::$ctx['ledger_unknown'] = Kashiwazaki_Next_Gen_Images_Converter::ledger_unknown(); // 写しを取り直したので、その結果で置き換える。
		}
	}

	/**
	 * 変換ファイルが使える状態か（存在し、空でなく、元画像より新しい）。
	 *
	 * 元画像が同じ名前で差し替えられた場合、古い変換ファイルは元画像より古くなる。
	 * そのまま配信すると前の画像が表示されてしまうので、ここで弾く。
	 *
	 * @param string $abs    元画像の絶対パス。
	 * @param string $format 形式。
	 * @return bool
	 */
	private static function has_variant( $abs, $format ) {
		if ( ! isset( self::$variant_cache[ $abs ] ) ) {
			$info  = array();
			$mtime = @filemtime( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// 元画像が無ければ、変換ファイルも使わない。登録済みの添付の名前の台帳を確定できなかったとき（ロックが取れない・SQL の失敗）も、
			// 「衝突なし」とは言えないので、このリクエストでは変換ファイルを使わない（元画像のまま）。
			// 判定は、台帳の写し(ctx['conflicts'])を取った時点の結果と、いまの結果の両方で行う。どちらかが「確定できていない」なら使わない:
			// 写しを取った後で別の経路が台帳を確定できなくなったとき(いまの結果)も、写しが古いのにあとで別の経路が台帳を確定したとき(写しの結果)も、古い写しで配信しないため。
			if ( false !== $mtime && empty( self::$ctx['ledger_unknown'] ) && ! Kashiwazaki_Next_Gen_Images_Converter::ledger_unknown() ) {
				$src_ext  = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
				$src_mime = ( 'png' === $src_ext ) ? 'image/png' : ( ( 'gif' === $src_ext ) ? 'image/gif' : '' );
				foreach ( Kashiwazaki_Next_Gen_Images_Settings::FORMATS as $f ) {
					if ( 'webp' === $f && 'webp' === $src_ext ) {
						continue;
					}
					// 「PNG・GIF は劣化なしで変換」の設定のとき、PNG・GIF の AVIF(劣化している)は、すでにあっても配信しない(変換し直す前でも)。
					if ( '' !== $src_mime && Kashiwazaki_Next_Gen_Images_Converter::lossless_blocks_avif( $src_mime, $f ) ) {
						continue;
					}
					$v    = $abs . '.' . $f;
					if ( ! empty( self::$ctx['conflicts'] ) ) {
						// 台帳は、URL の文字列の相対パスと、実体（シンボリックリンクを解決した場所）の相対パスの、どちらの鍵でも引く。
						foreach ( array_merge( Kashiwazaki_Next_Gen_Images_Converter::path_keys( $v ), Kashiwazaki_Next_Gen_Images_Converter::path_keys( $v, false ) ) as $ledger_key ) {
							if ( isset( self::$ctx['conflicts'][ $ledger_key ] ) ) {
								continue 2; // 別の添付ファイルの本体と同じ名前、または使わない一覧に載った変換ファイル。変換ファイルとして使わない。
							}
						}
					}
					// 変換ファイルの名前がシンボリックリンクなら、中身は別のファイル(別の添付の本体かもしれない)なので、使わない。
					// 台帳には、実体の名前が普通の登録(別名でない本体)は載らないため、台帳の引きでは見つけられない。
					if ( @is_link( $v ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						continue;
					}
					$vt   = @filemtime( $v ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$size = ( false !== $vt ) ? @filesize( $v ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false !== $vt && $vt >= $mtime && $size > 0 ) {
						$info[ $f ] = true;
					}
				}
			}
			self::$variant_cache[ $abs ] = $info;
		}
		return ! empty( self::$variant_cache[ $abs ][ $format ] );
	}

	/**
	 * <img ...> の属性を読む（名前は小文字、値は HTML エンティティを戻したもの）。
	 *
	 * @param string $tag タグ。
	 * @return array
	 */
	private static function parse_attributes( $tag ) {
		$attrs = array();
		$inner = substr( $tag, 4, -1 ); // "<img" と ">" を除く。
		$len   = strlen( $inner );
		$i     = 0;
		$ws    = "\t\n\f\r ";
		// HTML のトークナイザー(WHATWG)の属性の読み方にそろえる: 属性名は、空白・「/」・「>」・「=」の手前まで(先頭の「=」は名前の一部。「"」「'」「<」も名前に入る)。
		// 値は、引用符で囲まれたもの、または空白・「>」の手前まで(引用符なし。「/」は値に含まれる)。同じ名前が複数あるときは最初のものを使う。
		while ( $i < $len ) {
			$i += strspn( $inner, $ws . '/', $i ); // 属性の前の空白と「/」は読み飛ばす。
			if ( $i >= $len ) {
				break;
			}
			$start = $i;
			if ( '=' === $inner[ $i ] ) {
				++$i; // 先頭の「=」は属性名の一部。
			}
			$i   += strcspn( $inner, $ws . '/=', $i );
			$name = strtolower( substr( $inner, $start, $i - $start ) );
			$i   += strspn( $inner, $ws, $i );
			$value = '';
			if ( $i < $len && '=' === $inner[ $i ] ) {
				++$i;
				$i += strspn( $inner, $ws, $i );
				if ( $i < $len && ( '"' === $inner[ $i ] || "'" === $inner[ $i ] ) ) {
					$q   = $inner[ $i ];
					$end = strpos( $inner, $q, $i + 1 );
					if ( false === $end ) {
						$end = $len;
					}
					$value = substr( $inner, $i + 1, $end - $i - 1 );
					$i     = $end + 1;
				} else {
					$n     = strcspn( $inner, $ws, $i );
					$value = substr( $inner, $i, $n );
					$i    += $n;
				}
			}
			if ( '' !== $name && ! isset( $attrs[ $name ] ) ) {
				$attrs[ $name ] = $value;
			}
		}
		return $attrs;
	}

	/**
	 * srcset 属性を (URL, 記述子) の一覧にする。
	 *
	 * @param string $value 属性値。
	 * @return array
	 */
	private static function parse_srcset( $value ) {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out   = array();
		$len   = strlen( $value );
		$i     = 0;
		while ( $i < $len ) {
			$i += strspn( $value, " \t\n\r\f,", $i );
			if ( $i >= $len ) {
				break;
			}
			$start = $i;
			$i    += strcspn( $value, " \t\n\r\f", $i );
			$url   = substr( $value, $start, $i - $start );
			$desc  = '';
			if ( ',' === substr( $url, -1 ) ) {
				$url = rtrim( $url, ',' );
			} else {
				$k    = strcspn( $value, ',', $i );
				$desc = trim( substr( $value, $i, $k ) );
				$i   += $k;
			}
			if ( '' !== $url ) {
				$out[] = array(
					'url'  => $url,
					'desc' => $desc,
				);
			}
		}
		return $out;
	}
}
