<?php
/**
 * Plugin Name: Kashiwazaki Next-Gen Images
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: WordPressの画像をWebP・AVIFなどの次世代画像フォーマットに変換し、サイトの画像を軽量化するプラグインです。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Text Domain: wp-plugin-kashiwazaki-next-gen-images
 * Domain Path: /languages
 *
 * @package Kashiwazaki_Next_Gen_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_VERSION', '1.0.0' );
define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_FILE', __FILE__ );
define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_DIR', plugin_dir_path( __FILE__ ) );
define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_URL', plugin_dir_url( __FILE__ ) );
define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_BASENAME', plugin_basename( __FILE__ ) );
define( 'KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG', 'wp-plugin-kashiwazaki-next-gen-images' );

require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-settings.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-engine.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-converter.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-queue.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-job.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-delivery.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-admin.php';
require_once KASHIWAZAKI_NEXT_GEN_IMAGES_DIR . 'includes/class-kashiwazaki-next-gen-images-cli.php';

/**
 * 有効化時の処理。設定の初期値を（まだ無ければ）保存する。
 *
 * @return void
 */
function kashiwazaki_next_gen_images_activate() {
	if ( false === get_option( Kashiwazaki_Next_Gen_Images_Settings::OPTION, false ) ) {
		add_option( Kashiwazaki_Next_Gen_Images_Settings::OPTION, Kashiwazaki_Next_Gen_Images_Settings::defaults(), '', 'yes' );
	}
	// 停止している間に増えた「変換ファイルと同じ形の名前の画像」を台帳に載せるため、DB の真実から作り直す。
	Kashiwazaki_Next_Gen_Images_Converter::refresh_conflicts();
}
register_activation_hook( __FILE__, 'kashiwazaki_next_gen_images_activate' );

/**
 * 停止時の処理。予約済みの変換ジョブを取り除く（変換済みファイルと設定は残す）。
 *
 * @return void
 */
function kashiwazaki_next_gen_images_deactivate() {
	wp_unschedule_hook( Kashiwazaki_Next_Gen_Images_Queue::CRON_HOOK );
	wp_unschedule_hook( Kashiwazaki_Next_Gen_Images_Job::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'kashiwazaki_next_gen_images_deactivate' );

/**
 * プラグイン一覧に「設定」リンクを足す。
 *
 * @param array $links 既存のリンク。
 * @return array
 */
function kashiwazaki_next_gen_images_add_action_link( $links ) {
	$url  = admin_url( 'admin.php?page=' . KASHIWAZAKI_NEXT_GEN_IMAGES_SLUG );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( '設定', 'wp-plugin-kashiwazaki-next-gen-images' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}
add_filter( 'plugin_action_links_' . KASHIWAZAKI_NEXT_GEN_IMAGES_BASENAME, 'kashiwazaki_next_gen_images_add_action_link' );

/**
 * 各機能を起動する。
 *
 * @return void
 */
function kashiwazaki_next_gen_images_boot() {
	load_plugin_textdomain( 'wp-plugin-kashiwazaki-next-gen-images', false, dirname( KASHIWAZAKI_NEXT_GEN_IMAGES_BASENAME ) . '/languages' );

	Kashiwazaki_Next_Gen_Images_Settings::init();
	Kashiwazaki_Next_Gen_Images_Queue::init();
	Kashiwazaki_Next_Gen_Images_Job::init();
	Kashiwazaki_Next_Gen_Images_Delivery::init();
	Kashiwazaki_Next_Gen_Images_Admin::init();
	Kashiwazaki_Next_Gen_Images_CLI::init();
}
add_action( 'plugins_loaded', 'kashiwazaki_next_gen_images_boot' );
