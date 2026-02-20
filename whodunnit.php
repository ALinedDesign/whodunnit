<?php
/**
 * Plugin Name: Whodunnit
 * Plugin URI:  https://alineddesign.com/whodunnit
 * Description: Lightweight performance profiler for WordPress. Shows which plugins are hogging your database with a real-time toast overlay and a detailed admin profiler page.
 * Version:     1.0.0
 * Author:      ALined Design
 * Author URI:  https://alineddesign.com
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

define( 'WHODUNNIT_VERSION', '1.1.0' );
define( 'WHODUNNIT_FILE', __FILE__ );
define( 'WHODUNNIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WHODUNNIT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Enable SAVEQUERIES when toast is active.
 *
 * This must happen early (before plugins_loaded) so WordPress records query
 * traces from the start. Only enabled for administrators who haven't hidden
 * the toast.
 */
function whodunnit_maybe_enable_savequeries() {
	if ( defined( 'SAVEQUERIES' ) ) {
		return;
	}

	// Always enable on our profiler page with deep scan.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Early bootstrap before nonce system available.
	if ( isset( $_GET['page'] ) && $_GET['page'] === 'whodunnit' && isset( $_GET['savequeries'] ) ) {
		define( 'SAVEQUERIES', true );
		return;
	}

	// Enable for toast if the user hasn't hidden it.
	$toast_enabled = get_option( 'whodunnit_toast_enabled', '1' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Early bootstrap, cookie/GET read for toggling diagnostics.
	if ( $toast_enabled === '1' && ! ( isset( $_COOKIE['whodunnit_toast_hidden'] ) && $_COOKIE['whodunnit_toast_hidden'] === '1' ) ) {
		// Only if user has manage_options — but we can't check caps this early,
		// so we check the cookie opt-out and the option. Actual cap check happens at render.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_GET['perf'] ) || $_GET['perf'] !== '0' ) {
			define( 'SAVEQUERIES', true );
		}
	}
}

// Must run before WordPress starts executing queries
whodunnit_maybe_enable_savequeries();

// Record page start time for execution breakdown.
if ( ! defined( 'WHODUNNIT_START' ) ) {
	define( 'WHODUNNIT_START', microtime( true ) );
}

/**
 * Track outgoing HTTP requests for diagnostics.
 *
 * Hooks early so we capture all requests made during the page lifecycle.
 */
$GLOBALS['whodunnit_http_requests'] = [];

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['whodunnit_http_requests'][] = [
		'url'    => $url,
		'start'  => microtime( true ),
		'method' => $args['method'] ?? 'GET',
	];
	return $pre;
}, 1, 3 );

add_filter( 'http_response', function ( $response, $args, $url ) {
	foreach ( $GLOBALS['whodunnit_http_requests'] as &$req ) {
		if ( $req['url'] === $url && ! isset( $req['duration'] ) ) {
			$req['duration'] = round( ( microtime( true ) - $req['start'] ) * 1000 );
			$req['status']   = wp_remote_retrieve_response_code( $response );
			break;
		}
	}
	return $response;
}, 999, 3 );

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
	register_setting( 'whodunnit_settings', 'whodunnit_toast_enabled', [
		'type'              => 'string',
		'default'           => '1',
		'sanitize_callback' => function ( $val ) {
			return $val === '1' ? '1' : '0';
		},
	] );
}
add_action( 'admin_init', 'whodunnit_register_settings' );
