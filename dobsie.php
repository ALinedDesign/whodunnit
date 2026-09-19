<?php
/**
 * Plugin Name: Dobsie
 * Plugin URI:  https://docs.tracksies.com/docs/dobsie/
 * Description: Lightweight performance profiler for WordPress. Shows which plugins are hogging your database with a real-time toast overlay and a detailed admin profiler page.
 * Version:     1.0.0
 * Author:      Tracksies
 * Author URI:  https://tracksies.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dobsie
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Dobsie
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOBSIE_VERSION', '1.0.0' );
define( 'DOBSIE_FILE', __FILE__ );
define( 'DOBSIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOBSIE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Read a sanitised query-string value early in the bootstrap.
 *
 * Bootstrap runs before WordPress's nonce + capability machinery is available,
 * so we cannot verify a nonce here. Reads are limited to display toggles for
 * the diagnostic UI; the actual rendering is gated by current_user_can() in
 * the toast and profiler classes.
 */
function dobsie_read_query_param( $key ) {
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
function dobsie_read_cookie( $key ) {
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
function dobsie_request_has_login_cookie() {
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( strpos( (string) $name, 'wordpress_logged_in_' ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Decide, as early as possible, whether this request might need SAVEQUERIES.
 *
 * This is a PRE-FILTER, not the gate. All it establishes is that somebody is
 * logged in, which costs one array scan and keeps the hook below off the vast
 * majority of front-end traffic. The real decision needs a capability, and a
 * capability needs the auth stack, which does not exist this early.
 *
 * ⚠️ Until 1.0.0 the cookie check WAS the gate, and the wordpress.org review
 * pended the submission for it: every logged-in user, subscribers and
 * customers included, got query tracing on every page while the readme
 * promised administrators only. A login cookie says a user exists, never
 * which user.
 */
function dobsie_maybe_enable_savequeries() {
	if ( defined( 'SAVEQUERIES' ) ) {
		return;
	}

	if ( ! dobsie_request_has_login_cookie() ) {
		return;
	}

	add_action( 'init', 'dobsie_enable_savequeries_for_admin', 0 );
}

/**
 * Enable SAVEQUERIES, for administrators only.
 *
 * `init` at priority 0 is the earliest point where current_user_can() is
 * reliable, so it is the earliest honest place to make this decision.
 *
 * What that costs, precisely, because it is worth knowing which number moves:
 *
 * - The toast's QUERY COUNT is unaffected. It reads $wpdb->num_queries, which
 *   core increments on every query whether or not SAVEQUERIES is set.
 * - The DB TIME, the per-source breakdown and the slow-query list come from
 *   $wpdb->queries, which only fills once the constant is defined. Those now
 *   start at `init` and so exclude core's own bootstrap queries — loading
 *   options, resolving the user.
 *
 * That trade is the right way round for what this plugin is for. Plugin work
 * happens on `init` and later, so attribution of PLUGINS, which is the whole
 * product, is untouched. What drops out is core's own startup, which was
 * never attributable to a plugin anyway.
 */
function dobsie_enable_savequeries_for_admin() {
	if ( defined( 'SAVEQUERIES' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page = dobsie_read_query_param( 'page' );

	// Profiler page: enable when explicitly requested via deep-scan or debug tab.
	if ( $page === 'dobsie' ) {
		$tab = dobsie_read_query_param( 'tab' );
		if ( dobsie_read_query_param( 'savequeries' ) === '1' || $tab === 'debug' || $tab === 'deep' ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- SAVEQUERIES is a WordPress core constant; this enables core's existing query-trace behaviour, it is not a plugin-defined constant.
			define( 'SAVEQUERIES', true );
			return;
		}
	}

	// Toast: enable if option is on and user hasn't opted out.
	$toast_enabled = get_option( 'dobsie_toast_enabled', '1' );
	if ( $toast_enabled !== '1' ) {
		return;
	}
	if ( dobsie_read_cookie( 'dobsie_toast_hidden' ) === '1' ) {
		return;
	}
	if ( dobsie_read_query_param( 'perf' ) === '0' ) {
		return;
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- SAVEQUERIES is a WordPress core constant; this enables core's existing query-trace behaviour, it is not a plugin-defined constant.
	define( 'SAVEQUERIES', true );
}

dobsie_maybe_enable_savequeries();

if ( ! defined( 'DOBSIE_START' ) ) {
	define( 'DOBSIE_START', microtime( true ) );
}

/**
 * Bootstrap the plugin.
 */
function dobsie_init() {
	require_once DOBSIE_DIR . 'includes/class-dobsie-profiler.php';
	require_once DOBSIE_DIR . 'includes/class-dobsie-toast.php';

	Dobsie_Profiler::init();
	Dobsie_Toast::init();
}
add_action( 'plugins_loaded', 'dobsie_init' );

/**
 * Register and conditionally enqueue the JS handler.
 *
 * Replaces inline onclick handlers (which trigger malware-pattern scanners)
 * with a properly enqueued script. Loaded only when the toast or profiler
 * page would render — i.e., only for users with manage_options.
 */
function dobsie_register_assets() {
	wp_register_script(
		'dobsie',
		DOBSIE_URL . 'assets/js/dobsie.js',
		array(),
		DOBSIE_VERSION,
		true
	);
}
add_action( 'init', 'dobsie_register_assets' );

function dobsie_enqueue_for_admins() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_enqueue_script( 'dobsie' );
}
add_action( 'wp_enqueue_scripts', 'dobsie_enqueue_for_admins' );
add_action( 'admin_enqueue_scripts', 'dobsie_enqueue_for_admins' );

/**
 * Add settings link on the Plugins page.
 */
function dobsie_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=dobsie' ) ) . '">' . esc_html__( 'Profiler', 'dobsie' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'dobsie_plugin_action_links' );

/**
 * Register settings.
 */
function dobsie_register_settings() {
	register_setting( 'dobsie_settings', 'dobsie_toast_enabled', array(
		'type'              => 'string',
		'default'           => '1',
		'sanitize_callback' => function ( $val ) {
			return $val === '1' ? '1' : '0';
		},
	) );
}
add_action( 'admin_init', 'dobsie_register_settings' );

/**
 * Clean up on uninstall.
 */
register_uninstall_hook( __FILE__, 'dobsie_uninstall' );
function dobsie_uninstall() {
	delete_option( 'dobsie_toast_enabled' );
}
