<?php
/**
 * Plugin Name: Whodunnit
 * Plugin URI:  https://docs.tracksies.com/docs/whodunnit/
 * Description: Lightweight performance profiler for WordPress. Shows which plugins are hogging your database with a real-time toast overlay and a detailed admin profiler page.
 * Version:     1.0.0
 * Author:      Tracksies
 * Author URI:  https://tracksies.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: whodunnit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Whodunnit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WHODUNNIT_VERSION', '1.0.0' );
define( 'WHODUNNIT_FILE', __FILE__ );
define( 'WHODUNNIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WHODUNNIT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Read a sanitised query-string value early in the bootstrap.
 *
 * Bootstrap runs before WordPress's nonce + capability machinery is available,
 * so we cannot verify a nonce here. Reads are limited to display toggles for
 * the diagnostic UI; the actual rendering is gated by current_user_can() in
 * the toast and profiler classes.
 */
function whodunnit_read_query_param( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display toggle for admin-only diagnostic; capability check happens at render.
	if ( ! isset( $_GET[ $key ] ) ) {
		return '';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display toggle for admin-only diagnostic; capability check happens at render.
	return sanitize_key( wp_unslash( $_GET[ $key ] ) );
}

/**
 * Read a sanitised cookie value.
 */
function whodunnit_read_cookie( $key ) {
	if ( ! isset( $_COOKIE[ $key ] ) ) {
		return '';
	}
	return sanitize_key( wp_unslash( $_COOKIE[ $key ] ) );
}

/**
 * Detect whether the current request is from a logged-in user.
 *
 * Cheap cookie-name check used at bootstrap, before WordPress's auth stack is
 * loaded. Used to gate SAVEQUERIES so anonymous visitors never pay the trace
 * overhead.
 */
function whodunnit_request_has_login_cookie() {
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( strpos( (string) $name, 'wordpress_logged_in_' ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Enable SAVEQUERIES when toast is active.
 *
 * Must run before WordPress executes queries so traces are captured. Only
 * enabled for logged-in users (cookie heuristic) and only when the toast is
 * not opted out. Final capability check happens at render time.
 */
function whodunnit_maybe_enable_savequeries() {
	if ( defined( 'SAVEQUERIES' ) ) {
		return;
	}

	if ( ! whodunnit_request_has_login_cookie() ) {
		return;
	}

	$page = whodunnit_read_query_param( 'page' );

	// Profiler page: enable when explicitly requested via deep-scan or debug tab.
	if ( $page === 'whodunnit' ) {
		$tab = whodunnit_read_query_param( 'tab' );
		if ( whodunnit_read_query_param( 'savequeries' ) === '1' || $tab === 'debug' || $tab === 'deep' ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- SAVEQUERIES is a WordPress core constant; this enables core's existing query-trace behaviour, it is not a plugin-defined constant.
			define( 'SAVEQUERIES', true );
			return;
		}
	}

	// Toast: enable if option is on and user hasn't opted out.
	$toast_enabled = get_option( 'whodunnit_toast_enabled', '1' );
	if ( $toast_enabled !== '1' ) {
		return;
	}
	if ( whodunnit_read_cookie( 'whodunnit_toast_hidden' ) === '1' ) {
		return;
	}
	if ( whodunnit_read_query_param( 'perf' ) === '0' ) {
		return;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- SAVEQUERIES is a WordPress core constant; this enables core's existing query-trace behaviour, it is not a plugin-defined constant.
	define( 'SAVEQUERIES', true );
}

whodunnit_maybe_enable_savequeries();

if ( ! defined( 'WHODUNNIT_START' ) ) {
	define( 'WHODUNNIT_START', microtime( true ) );
}

/**
 * Bootstrap the plugin.
 */
function whodunnit_init() {
	require_once WHODUNNIT_DIR . 'includes/class-whodunnit-profiler.php';
	require_once WHODUNNIT_DIR . 'includes/class-whodunnit-toast.php';

	Whodunnit_Profiler::init();
	Whodunnit_Toast::init();
}
add_action( 'plugins_loaded', 'whodunnit_init' );

/**
 * Register and conditionally enqueue the JS handler.
 *
 * Replaces inline onclick handlers (which trigger malware-pattern scanners)
 * with a properly enqueued script. Loaded only when the toast or profiler
 * page would render — i.e., only for users with manage_options.
 */
function whodunnit_register_assets() {
	wp_register_script(
		'whodunnit',
		WHODUNNIT_URL . 'assets/js/whodunnit.js',
		array(),
		WHODUNNIT_VERSION,
		true
	);
}
add_action( 'init', 'whodunnit_register_assets' );

function whodunnit_enqueue_for_admins() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_enqueue_script( 'whodunnit' );
}
add_action( 'wp_enqueue_scripts', 'whodunnit_enqueue_for_admins' );
add_action( 'admin_enqueue_scripts', 'whodunnit_enqueue_for_admins' );

/**
 * Add settings link on the Plugins page.
 */
function whodunnit_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=whodunnit' ) ) . '">' . esc_html__( 'Profiler', 'whodunnit' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'whodunnit_plugin_action_links' );

/**
 * Register settings.
 */
function whodunnit_register_settings() {
	register_setting( 'whodunnit_settings', 'whodunnit_toast_enabled', array(
		'type'              => 'string',
		'default'           => '1',
		'sanitize_callback' => function ( $val ) {
			return $val === '1' ? '1' : '0';
		},
	) );
}
add_action( 'admin_init', 'whodunnit_register_settings' );

/**
 * Clean up on uninstall.
 */
register_uninstall_hook( __FILE__, 'whodunnit_uninstall' );
function whodunnit_uninstall() {
	delete_option( 'whodunnit_toast_enabled' );
}
