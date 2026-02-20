<?php
/**
 * Whodunnit Profiler — Admin page with deep diagnostics.
 *
 * Access: Tools > Whodunnit
 * Query params:
 *   ?savequeries=1  — Detailed query breakdown by plugin source
 *   ?serverhealth=1 — Filesystem, cron, PHP config diagnostics
 *
 * @package Whodunnit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Whodunnit_Profiler {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
	}

	/**
	 * Register the admin menu page under Tools.
	 */
	public static function add_menu_page() {
		add_management_page(
			'Whodunnit',
			'Whodunnit',
			'manage_options',
			'whodunnit',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the profiler page.
	 */
	public static function render_page() {
		global $wpdb;

		$memory           = memory_get_peak_usage( true ) / 1024 / 1024;
		$plugins          = count( get_option( 'active_plugins', [] ) );
		$queries          = $wpdb->num_queries;
		$savequeries_on   = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page, nonce verified by WordPress menu system.
		$serverhealth_on  = isset( $_GET['serverhealth'] );
		$toast_enabled    = get_option( 'whodunnit_toast_enabled', '1' );

		// Autoload analysis.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic tool, caching would defeat the purpose.
		$autoload_total = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
		);
		$autoload_mb = $autoload_total / 1024 / 1024;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic tool, caching would defeat the purpose.
		$large_options = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size
			FROM {$wpdb->options}
			WHERE autoload = 'yes'
			ORDER BY LENGTH(option_value) DESC
			LIMIT 15"
		);

		// Database stats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic tool, caching would defeat the purpose.
		$revisions   = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic tool, caching would defeat the purpose.
		$transients  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance diagnostic tool, caching would defeat the purpose.
		$expired     = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );

		// Query analysis by plugin.
		$queries_by_plugin = [];
		$slow_queries      = [];

		if ( $savequeries_on && ! empty( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				$sql   = $q[0];
				$time  = $q[1] * 1000;
				$trace = $q[2];

				$source = self::detect_source( $sql, $trace );

				if ( ! isset( $queries_by_plugin[ $source ] ) ) {
					$queries_by_plugin[ $source ] = [ 'count' => 0, 'time' => 0 ];
				}
				$queries_by_plugin[ $source ]['count']++;
				$queries_by_plugin[ $source ]['time'] += $time;

				if ( $time > 10 ) {
					$slow_queries[] = [
						'sql'    => $sql,
						'time'   => $time,
						'source' => $source,
					];
				}
			}

			uasort( $queries_by_plugin, function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			} );

			usort( $slow_queries, function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			} );
		}

		self::render_styles();
		?>
		<div class="wrap whodunnit">
			<h1>Whodunnit</h1>
			<p class="description">Who's hogging your database? Let's find out.</p>

			<div class="nav-tabs">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=whodunnit' ) ); ?>"
				   class="<?php echo esc_attr( ! $savequeries_on && ! $serverhealth_on ? 'active' : '' ); ?>">Overview</a>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=whodunnit&savequeries=1' ) ); ?>"
				   class="<?php echo esc_attr( $savequeries_on ? 'active' : '' ); ?>">Deep Scan</a>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=whodunnit&serverhealth=1' ) ); ?>"
				   class="<?php echo esc_attr( $serverhealth_on ? 'active' : '' ); ?>">Server Health</a>
			</div>

			<?php self::render_toast_toggle( $toast_enabled ); ?>

			<?php self::render_overview( $memory, $plugins, $queries, $autoload_mb, $savequeries_on, $wpdb ); ?>

			<?php if ( $savequeries_on && ! empty( $queries_by_plugin ) ) : ?>
				<?php self::render_query_breakdown( $queries_by_plugin, $slow_queries, $wpdb ); ?>
			<?php elseif ( ! $savequeries_on ) : ?>
				<div class="box">
					<h2>Query Analysis</h2>
					<p>Click <strong>Deep Scan</strong> above to see which plugins generate the most database queries.</p>
					<p><em>Note: Deep Scan adds overhead. Use for diagnosis, not production.</em></p>
				</div>
			<?php endif; ?>

			<?php self::render_autoload_table( $large_options ); ?>
			<?php self::render_rewrite_rules(); ?>
			<?php self::render_db_cleanup( $revisions, $transients, $expired ); ?>
			<?php self::render_page_test(); ?>
			<?php self::render_recommendations( $plugins, $queries, $memory, $revisions, $expired ); ?>

			<?php if ( $savequeries_on && ! empty( $queries_by_plugin ) ) : ?>
				<?php self::render_worst_offenders( $queries_by_plugin, $slow_queries ); ?>
			<?php endif; ?>

			<?php if ( $serverhealth_on ) : ?>
				<?php self::render_server_health(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Detect the source plugin/theme from a query's SQL and stack trace.
	 *
	 * @param string $sql   The SQL query.
	 * @param string $trace The stack trace.
	 * @return string Source identifier.
	 */
	public static function detect_source( $sql, $trace ) {
		$source = 'WordPress Core';

		// Check stack trace for plugin/theme path.
		if ( preg_match( '/wp-content\/plugins\/([^\/]+)/', $trace, $m ) ) {
			$source = $m[1];
		} elseif ( preg_match( '/wp-content\/themes\/([^\/]+)/', $trace, $m ) ) {
			$source = 'Theme: ' . $m[1];
		} elseif ( preg_match( '/wp-content\/mu-plugins\/([^\/,]+)/', $trace, $m ) ) {
			$source = 'MU: ' . $m[1];
		}

		// Override with SQL content patterns for more accuracy.
		$sql_patterns = [
			'gravityforms'   => [ 'GFCache', 'gf_form', 'gravityforms' ],
			'woocommerce'    => [ 'woocommerce', 'wc_' ],
			'dokan'          => [ 'dokan' ],
			'buddyboss'      => [ 'bp_', 'buddypress', 'buddyboss' ],
			'eventin'        => [ 'eventin', 'etn_' ],
			'learnpress'     => [ 'learnpress', 'lp_' ],
			'gravityview'    => [ 'gravityview', 'gv_' ],
			'action-scheduler' => [ 'actionscheduler' ],
			'litespeed-cache'  => [ 'litespeed' ],
			'yoast-seo'       => [ 'yoast_seo', 'wpseo_' ],
			'elementor'        => [ 'elementor' ],
			'wpforms'          => [ 'wpforms' ],
			'jetpack'          => [ 'jetpack' ],
		];

		$sql_lower = strtolower( $sql );
		foreach ( $sql_patterns as $name => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( strpos( $sql_lower, strtolower( $pattern ) ) !== false ) {
					return $name;
				}
			}
		}

		return $source;
	}

	// -------------------------------------------------------------------------
	// Render methods
	// -------------------------------------------------------------------------

	private static function render_styles() {
		?>
		<style>
			.whodunnit { max-width: 1000px; }
			.whodunnit .box { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; }
			.whodunnit .stat { display: inline-block; background: #f9f9f9; padding: 15px 25px; margin: 0 10px 10px 0; text-align: center; }
			.whodunnit .stat b { font-size: 28px; display: block; }
			.whodunnit .bad { color: #d63638; }
			.whodunnit .warn { color: #dba617; }
			.whodunnit .good { color: #00a32a; }
			.whodunnit table { width: 100%; border-collapse: collapse; }
			.whodunnit th, .whodunnit td { padding: 8px; border-bottom: 1px solid #eee; text-align: left; }
			.whodunnit th { background: #f9f9f9; }
			.whodunnit code { background: #f0f0f0; padding: 2px 5px; font-size: 12px; }
			.whodunnit .sql { max-width: 500px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.whodunnit .bar { background: #2271b1; height: 12px; display: inline-block; }
			.whodunnit .bar-bg { background: #f0f0f0; width: 150px; display: inline-block; margin-right: 10px; }
			.whodunnit .nav-tabs { margin-bottom: 20px; }
			.whodunnit .nav-tabs a { display: inline-block; padding: 8px 16px; background: #f0f0f0; margin-right: 5px; text-decoration: none; color: #333; border-radius: 3px 3px 0 0; }
			.whodunnit .nav-tabs a.active { background: #2271b1; color: #fff; }
			.whodunnit .toast-toggle { margin-bottom: 20px; padding: 10px 15px; background: #f9f9f9; border: 1px solid #ccd0d4; display: flex; align-items: center; gap: 10px; }
		</style>
		<?php
	}

	private static function render_toast_toggle( $toast_enabled ) {
		?>
		<form method="post" action="options.php" class="toast-toggle">
			<?php settings_fields( 'whodunnit_settings' ); ?>
			<label>
				<input type="hidden" name="whodunnit_toast_enabled" value="0">
				<input type="checkbox" name="whodunnit_toast_enabled" value="1"
					<?php checked( $toast_enabled, '1' ); ?>>
				Enable performance toast overlay
			</label>
			<span style="color: #888; font-size: 12px;">
				(floating panel on every page — visible to admins only, enables SAVEQUERIES)
			</span>
			<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_overview( $memory, $plugins, $queries, $autoload_mb, $savequeries_on, $wpdb ) {
		?>
		<div class="box">
			<h2>Overview</h2>
			<div class="stat">
				<b class="<?php echo esc_attr( $memory > 256 ? 'bad' : ( $memory > 128 ? 'warn' : 'good' ) ); ?>">
					<?php echo esc_html( number_format( $memory, 1 ) ); ?>MB
				</b> Memory
			</div>
			<div class="stat">
				<b class="<?php echo esc_attr( $plugins > 50 ? 'bad' : ( $plugins > 30 ? 'warn' : 'good' ) ); ?>">
					<?php echo (int) $plugins; ?>
				</b> Plugins
			</div>
			<div class="stat">
				<b class="<?php echo esc_attr( $queries > 500 ? 'bad' : ( $queries > 200 ? 'warn' : 'good' ) ); ?>">
					<?php echo (int) $queries; ?>
				</b> Queries
			</div>
			<div class="stat">
				<b class="<?php echo esc_attr( $autoload_mb > 1 ? 'bad' : ( $autoload_mb > 0.5 ? 'warn' : 'good' ) ); ?>">
					<?php echo esc_html( number_format( $autoload_mb, 2 ) ); ?>MB
				</b> Autoload
			</div>
			<?php
			if ( $savequeries_on ) :
				$total_query_time = array_sum( array_column( $wpdb->queries, 1 ) ) * 1000;
				?>
				<div class="stat">
					<b class="<?php echo esc_attr( $total_query_time > 1000 ? 'bad' : ( $total_query_time > 500 ? 'warn' : 'good' ) ); ?>">
						<?php echo esc_html( number_format( $total_query_time, 0 ) ); ?>ms
					</b> Query Time
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_query_breakdown( $queries_by_plugin, $slow_queries, $wpdb ) {
		$max_time = max( array_column( $queries_by_plugin, 'time' ) );
		?>
		<div class="box">
			<h2>Queries by Plugin/Source</h2>
			<p>Shows which plugins are generating the most database queries and time.</p>
			<table>
				<thead>
					<tr><th>Plugin/Source</th><th>Queries</th><th>Total Time</th><th></th></tr>
				</thead>
				<tbody>
					<?php
					$i = 0;
					foreach ( $queries_by_plugin as $plugin => $data ) :
						if ( $i++ >= 20 ) break;
						$pct        = $max_time > 0 ? ( $data['time'] / $max_time ) * 100 : 0;
						$time_class = $data['time'] > 200 ? 'bad' : ( $data['time'] > 100 ? 'warn' : 'good' );
						?>
						<tr>
							<td><strong><?php echo esc_html( $plugin ); ?></strong></td>
							<td><?php echo (int) $data['count']; ?></td>
							<td class="<?php echo esc_attr( $time_class ); ?>"><?php echo esc_html( number_format( $data['time'], 1 ) ); ?>ms</td>
							<td>
								<span class="bar-bg"><span class="bar" style="width: <?php echo esc_attr( $pct ); ?>%"></span></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( ! empty( $slow_queries ) ) : ?>
		<div class="box">
			<h2>Slow Queries (>10ms)</h2>
			<p>Individual queries taking significant time. May indicate missing indexes or inefficient queries.</p>
			<table>
				<thead><tr><th>Query</th><th>Time</th><th>Source</th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $slow_queries, 0, 15 ) as $sq ) : ?>
					<tr>
						<td class="sql">
							<code><?php echo esc_html( substr( $sq['sql'], 0, 100 ) ); ?><?php echo esc_html( strlen( $sq['sql'] ) > 100 ? '...' : '' ); ?></code>
						</td>
						<td class="bad"><?php echo esc_html( number_format( $sq['time'], 1 ) ); ?>ms</td>
						<td><?php echo esc_html( $sq['source'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif;
	}

	private static function render_autoload_table( $large_options ) {
		?>
		<div class="box">
			<h2>Autoloaded Options (Top 15)</h2>
			<p>Target: under 1MB total. Large options slow every page load.</p>
			<table>
				<tr><th>Option</th><th>Size</th></tr>
				<?php
				foreach ( $large_options as $opt ) :
					$kb    = $opt->size / 1024;
					$class = $kb > 100 ? 'bad' : ( $kb > 50 ? 'warn' : '' );
					?>
					<tr>
						<td><code><?php echo esc_html( $opt->option_name ); ?></code></td>
						<td class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( size_format( $opt->size ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	private static function render_rewrite_rules() {
		$rewrite_rules = get_option( 'rewrite_rules', [] );
		$rules_size    = strlen( wp_json_encode( $rewrite_rules ) );
		$rules_count   = is_array( $rewrite_rules ) ? count( $rewrite_rules ) : 0;

		// Group rules by pattern to identify sources.
		$rule_groups = [];
		if ( is_array( $rewrite_rules ) ) {
			$source_patterns = [
				'BuddyBoss/BuddyPress' => [ 'members', 'groups', 'activity', 'bp-' ],
				'Dokan'                 => [ 'store', 'vendor', 'dashboard' ],
				'LearnPress'            => [ 'course', 'lesson', 'quiz' ],
				'Eventin'               => [ 'event', 'schedule', 'speaker' ],
				'WooCommerce'           => [ 'product', 'shop', 'cart' ],
				'GravityView'           => [ 'entry', 'gravityview' ],
				'weDocs'                => [ 'docs' ],
				'SEO/Feeds'             => [ 'feed', 'sitemap' ],
			];

			foreach ( $rewrite_rules as $pattern => $rewrite ) {
				$source = 'WordPress Core';
				$combined = $pattern . ' ' . $rewrite;

				foreach ( $source_patterns as $name => $keywords ) {
					foreach ( $keywords as $keyword ) {
						if ( stripos( $combined, $keyword ) !== false ) {
							$source = $name;
							break 2;
						}
					}
				}

				if ( ! isset( $rule_groups[ $source ] ) ) {
					$rule_groups[ $source ] = [ 'count' => 0, 'patterns' => [] ];
				}
				$rule_groups[ $source ]['count']++;
				if ( count( $rule_groups[ $source ]['patterns'] ) < 3 ) {
					$rule_groups[ $source ]['patterns'][] = $pattern;
				}
			}
			arsort( $rule_groups );
		}
		?>
		<div class="box">
			<h2>Rewrite Rules Analysis</h2>
			<p>
				<strong>Total Rules:</strong> <?php echo esc_html( number_format( $rules_count ) ); ?> |
				<strong>Size:</strong>
				<span class="<?php echo esc_attr( $rules_size > 50000 ? 'bad' : ( $rules_size > 20000 ? 'warn' : 'good' ) ); ?>">
					<?php echo esc_html( size_format( $rules_size ) ); ?>
				</span>
				<?php if ( $rules_size > 50000 ) : ?> — Very large, consider optimizing<?php endif; ?>
			</p>

			<?php if ( ! empty( $rule_groups ) ) : ?>
			<table>
				<thead><tr><th>Source (estimated)</th><th>Rules</th><th>Sample Patterns</th></tr></thead>
				<tbody>
					<?php
					foreach ( $rule_groups as $source => $data ) :
						$pct = $rules_count > 0 ? round( ( $data['count'] / $rules_count ) * 100 ) : 0;
						?>
						<tr>
							<td><strong><?php echo esc_html( $source ); ?></strong></td>
							<td><?php echo (int) $data['count']; ?> (<?php echo (int) $pct; ?>%)</td>
							<td><code style="font-size:11px"><?php echo esc_html( implode( ', ', array_slice( $data['patterns'], 0, 2 ) ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<details style="margin-top: 15px;">
				<summary style="cursor: pointer; color: #2271b1;">Show all rewrite rules (<?php echo (int) $rules_count; ?>)</summary>
				<div style="max-height: 300px; overflow-y: auto; margin-top: 10px; background: #f9f9f9; padding: 10px; font-size: 11px;">
					<table style="font-size: 11px;">
						<tr><th>Pattern</th><th>Rewrite</th></tr>
						<?php foreach ( $rewrite_rules as $pattern => $rewrite ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( $pattern, 0, 60 ) ); ?><?php echo esc_html( strlen( $pattern ) > 60 ? '...' : '' ); ?></code></td>
							<td><code><?php echo esc_html( substr( $rewrite, 0, 60 ) ); ?><?php echo esc_html( strlen( $rewrite ) > 60 ? '...' : '' ); ?></code></td>
						</tr>
						<?php endforeach; ?>
					</table>
				</div>
			</details>
		</div>
		<?php
	}

	private static function render_db_cleanup( $revisions, $transients, $expired ) {
		?>
		<div class="box">
			<h2>Database Cleanup</h2>
			<table>
				<tr>
					<td>Post Revisions</td>
					<td class="<?php echo esc_attr( $revisions > 1000 ? 'bad' : '' ); ?>">
						<?php echo esc_html( number_format( $revisions ) ); ?>
						<?php if ( $revisions > 1000 ) : ?> — Consider limiting<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>Transients</td>
					<td><?php echo esc_html( number_format( $transients ) ); ?> (<?php echo (int) $expired; ?> expired)</td>
				</tr>
			</table>
			<?php if ( $revisions > 1000 ) : ?>
			<p style="margin-top:15px">Add to wp-config.php: <code>define('WP_POST_REVISIONS', 5);</code></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_page_test() {
		?>
		<div class="box">
			<h2>Quick Page Test</h2>
			<p>
				<input type="text" id="whodunnit-test-url" value="<?php echo esc_url( home_url( '/' ) ); ?>" style="width:70%">
				<button class="button" onclick="whodunnitTestPage()">Test</button>
			</p>
			<div id="whodunnit-result"></div>
			<script>
			function whodunnitTestPage() {
				var url = document.getElementById('whodunnit-test-url').value;
				document.getElementById('whodunnit-result').innerHTML = 'Testing...';
				var start = Date.now();
				fetch(url, {mode:'no-cors'}).then(function() {
					var time = Date.now() - start;
					var cls = time > 3000 ? 'bad' : (time > 1500 ? 'warn' : 'good');
					document.getElementById('whodunnit-result').innerHTML = '<b class="'+cls+'">' + time + 'ms</b> (client-side, includes network)';
				}).catch(function(e) {
					document.getElementById('whodunnit-result').innerHTML = 'Error: ' + e.message;
				});
			}
			</script>
		</div>
		<?php
	}

	private static function render_recommendations( $plugins, $queries, $memory, $revisions, $expired ) {
		?>
		<div class="box">
			<h2>Recommendations</h2>
			<ul>
				<?php if ( $plugins > 50 ) : ?>
				<li class="bad"><strong><?php echo (int) $plugins; ?> plugins</strong> is very high. Consider object caching (Redis/Memcached) to reduce database load.</li>
				<?php endif; ?>
				<?php if ( $queries > 500 ) : ?>
				<li class="warn"><strong><?php echo (int) $queries; ?> queries</strong> per page load. Object caching would help significantly.</li>
				<?php endif; ?>
				<?php if ( $memory > 200 ) : ?>
				<li class="warn"><strong><?php echo esc_html( number_format( $memory ) ); ?>MB memory</strong>. Ensure PHP memory_limit is at least 512M.</li>
				<?php endif; ?>
				<?php if ( $revisions > 1000 ) : ?>
				<li>Limit post revisions: <code>define('WP_POST_REVISIONS', 5);</code></li>
				<?php endif; ?>
				<?php if ( $expired > 100 ) : ?>
				<li>Clean up <?php echo (int) $expired; ?> expired transients.</li>
				<?php endif; ?>
				<li>Ensure <strong>OPcache</strong> is enabled (caches compiled PHP).</li>
				<li>Use <strong>Redis or Memcached</strong> for object caching (critical with <?php echo (int) $plugins; ?> plugins).</li>
			</ul>
		</div>
		<?php
	}

	private static function render_worst_offenders( $queries_by_plugin, $slow_queries ) {
		$worst_plugins        = array_slice( $queries_by_plugin, 0, 5, true );
		$total_query_time_all = array_sum( array_column( $queries_by_plugin, 'time' ) );
		?>
		<div class="box" style="background: #fff8e5; border-color: #ffb900;">
			<h2 style="color: #996800;">Worst Offenders</h2>
			<p>These plugins are consuming the most database resources. Focus optimization efforts here.</p>

			<table style="background: #fff;">
				<thead>
					<tr><th>Plugin</th><th>Queries</th><th>Time</th><th>% of Total</th><th>Suggestion</th></tr>
				</thead>
				<tbody>
					<?php
					foreach ( $worst_plugins as $plugin => $data ) :
						$pct      = $total_query_time_all > 0 ? ( $data['time'] / $total_query_time_all ) * 100 : 0;
						$severity = $pct > 30 ? 'bad' : ( $pct > 15 ? 'warn' : '' );
						$action   = self::get_suggestion( $plugin );
						?>
						<tr>
							<td><strong><?php echo esc_html( $plugin ); ?></strong></td>
							<td><?php echo (int) $data['count']; ?></td>
							<td class="<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( number_format( $data['time'], 0 ) ); ?>ms</td>
							<td class="<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( number_format( $pct, 1 ) ); ?>%</td>
							<td><em><?php echo esc_html( $action ); ?></em></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="margin-top: 15px; padding: 10px; background: #fff; border-left: 4px solid #d63638;">
				<strong>Quick Win:</strong> These <?php echo (int) count( $worst_plugins ); ?> plugins account for
				<strong><?php echo esc_html( number_format( array_sum( array_column( $worst_plugins, 'time' ) ), 0 ) ); ?>ms</strong>
				(<?php echo esc_html( number_format( ( array_sum( array_column( $worst_plugins, 'time' ) ) / $total_query_time_all ) * 100, 0 ) ); ?>%)
				of total query time.
			</div>

			<?php
			// Identify patterns in slow queries.
			$patterns = [
				'wp_options'  => 0,
				'wp_postmeta' => 0,
				'wp_usermeta' => 0,
				'wp_posts'    => 0,
				'transient'   => 0,
			];

			foreach ( $slow_queries as $sq ) {
				foreach ( $patterns as $pattern => &$count ) {
					if ( stripos( $sq['sql'], $pattern ) !== false ) {
						$count++;
					}
				}
			}
			arsort( $patterns );
			$patterns = array_filter( $patterns );
			?>

			<?php if ( ! empty( $patterns ) ) : ?>
			<div style="margin-top: 15px;">
				<strong>Slow Query Patterns:</strong>
				<?php foreach ( $patterns as $pattern => $count ) : ?>
				<span style="display: inline-block; background: #f0f0f0; padding: 3px 8px; margin: 2px; border-radius: 3px;">
					<?php echo esc_html( $pattern ); ?>: <?php echo (int) $count; ?>
				</span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get an optimization suggestion for a known plugin.
	 *
	 * @param string $plugin Plugin identifier.
	 * @return string Suggestion text.
	 */
	private static function get_suggestion( $plugin ) {
		$plugin_lower = strtolower( $plugin );

		$suggestions = [
			'woocommerce'  => 'Enable HPOS, check transients',
			'gravityforms' => 'Archive old entries, check GPPA queries',
			'buddyboss'    => 'Check activity stream settings',
			'buddypress'   => 'Check activity stream settings',
			'dokan'        => 'Review vendor listing queries',
			'eventin'      => 'Check event listing queries',
			'learnpress'   => 'Review course/lesson queries',
			'elementor'    => 'Check CSS regeneration, DOM output',
			'yoast-seo'    => 'Check indexable table size',
			'jetpack'      => 'Disable unused modules',
			'wordpress core' => 'Check wp_options, autoload, transients',
		];

		foreach ( $suggestions as $key => $suggestion ) {
			if ( strpos( $plugin_lower, $key ) !== false ) {
				return $suggestion;
			}
		}

		return 'Review usage';
	}

	/**
	 * Render the Server Health tab.
	 */
	private static function render_server_health() {
		// ---- OPcache Diagnostics ----
		self::render_opcache_diagnostics();

		// ---- HTTP Request Tracking ----
		self::render_http_requests();

		// ---- Execution Time Breakdown ----
		self::render_execution_breakdown();

		// Filesystem helpers.
		$count_files = function ( $dir, $max_depth = 2, $current_depth = 0 ) use ( &$count_files ) {
			if ( ! is_dir( $dir ) || $current_depth > $max_depth ) {
				return [ 'count' => 0, 'size' => 0 ];
			}
			$count = 0;
			$size  = 0;
			$items = @scandir( $dir );
			if ( $items === false ) {
				return [ 'count' => 0, 'size' => 0 ];
			}
			foreach ( $items as $item ) {
				if ( $item === '.' || $item === '..' ) continue;
				$path = $dir . '/' . $item;
				if ( is_file( $path ) ) {
					$count++;
					$size += @filesize( $path );
				} elseif ( is_dir( $path ) && $current_depth < $max_depth ) {
					$sub    = $count_files( $path, $max_depth, $current_depth + 1 );
					$count += $sub['count'];
					$size  += $sub['size'];
				}
			}
			return [ 'count' => $count, 'size' => $size ];
		};

		$wp_content = WP_CONTENT_DIR;
		$upload_dir = wp_upload_dir();

		$dirs_to_check = [
			'Cache Directory' => $wp_content . '/cache',
			'LiteSpeed Cache' => $wp_content . '/litespeed',
			'Uploads (top level)' => $upload_dir['basedir'],
			'WP Logs'         => $wp_content . '/debug.log',
		];

		$fs_stats = [];
		foreach ( $dirs_to_check as $label => $path ) {
			if ( is_file( $path ) ) {
				$fs_stats[ $label ] = [
					'path'    => $path,
					'count'   => 1,
					'size'    => @filesize( $path ) ?: 0,
					'is_file' => true,
					'error'   => null,
				];
			} else {
				$stats = $count_files( $path, 1 );
				$fs_stats[ $label ] = [
					'path'    => $path,
					'count'   => $stats['count'],
					'size'    => $stats['size'],
					'is_file' => false,
					'error'   => is_dir( $path ) ? null : 'Not found',
				];
			}
		}

		// Cron analysis.
		$crons         = _get_cron_array();
		$cron_count    = 0;
		$overdue_crons = [];
		$cron_hooks    = [];
		$now           = time();

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					$event_count = count( $events );
					$cron_count += $event_count;

					if ( ! isset( $cron_hooks[ $hook ] ) ) {
						$cron_hooks[ $hook ] = [ 'count' => 0, 'next' => $timestamp ];
					}
					$cron_hooks[ $hook ]['count'] += $event_count;

					if ( $timestamp < $now ) {
						$overdue_crons[] = [
							'hook'       => $hook,
							'scheduled'  => $timestamp,
							'overdue_by' => $now - $timestamp,
						];
					}
				}
			}
		}
		uasort( $cron_hooks, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

		// Object cache detection.
		$object_cache_type = 'None (using database)';
		$object_cache      = false;
		if ( function_exists( 'wp_cache_get' ) ) {
			if ( defined( 'WP_REDIS_DISABLED' ) && ! WP_REDIS_DISABLED ) {
				$object_cache      = true;
				$object_cache_type = 'Redis';
			} elseif ( class_exists( 'Memcached' ) && defined( 'WP_CACHE' ) && WP_CACHE ) {
				$object_cache      = true;
				$object_cache_type = 'Memcached';
			} elseif ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
				$object_cache      = true;
				$object_cache_type = 'Custom (object-cache.php exists)';
			}
		}

		// Check object cache drop-in details.
		$object_cache_detail = '';
		$dropin = WP_CONTENT_DIR . '/object-cache.php';
		if ( file_exists( $dropin ) ) {
			$header = file_get_contents( $dropin, false, null, 0, 500 );
			if ( preg_match( '/Plugin Name:\s*(.+)/i', $header, $m ) ) {
				$object_cache_detail = trim( $m[1] );
			}
		}

		// Object cache test.
		$object_cache_working = false;
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( 'whodunnit_test', 'ok', '', 60 );
			$object_cache_working = ( wp_cache_get( 'whodunnit_test' ) === 'ok' );
			wp_cache_delete( 'whodunnit_test' );
		}

		// Problem files.
		$problem_files = [];
		$debug_log     = $wp_content . '/debug.log';
		if ( file_exists( $debug_log ) && filesize( $debug_log ) > 10 * 1024 * 1024 ) {
			$problem_files[] = [ 'file' => 'debug.log', 'size' => filesize( $debug_log ), 'issue' => 'Very large log file' ];
		}
		$error_log = ABSPATH . 'error_log';
		if ( file_exists( $error_log ) ) {
			$problem_files[] = [ 'file' => 'error_log (root)', 'size' => filesize( $error_log ), 'issue' => 'Error log in web root' ];
		}

		// Active hooks.
		global $wp_filter;
		$heavy_hooks    = [];
		$hooks_to_check = [ 'init', 'wp_loaded', 'admin_init', 'wp_head', 'wp_footer', 'the_content', 'save_post', 'user_has_cap' ];
		foreach ( $hooks_to_check as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$count = 0;
				foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
					$count += count( $callbacks );
				}
				$heavy_hooks[ $hook ] = $count;
			}
		}
		arsort( $heavy_hooks );

		// ---- Render ----
		?>
		<div class="box">
			<h2>Filesystem Health</h2>
			<p>High file counts can exhaust inodes and cause 503 errors.</p>
			<table>
				<thead><tr><th>Location</th><th>Files</th><th>Size</th><th>Status</th></tr></thead>
				<tbody>
					<?php
					foreach ( $fs_stats as $label => $stats ) :
						$count_class = '';
						$status      = 'OK';
						if ( $stats['error'] ) {
							$status = $stats['error'];
						} elseif ( $stats['count'] > 10000 ) {
							$count_class = 'bad';
							$status      = 'Too many files!';
						} elseif ( $stats['count'] > 5000 ) {
							$count_class = 'warn';
							$status      = 'Getting high';
						}
						if ( $stats['is_file'] && $stats['size'] > 50 * 1024 * 1024 ) {
							$count_class = 'bad';
							$status      = 'File too large!';
						} elseif ( $stats['is_file'] && $stats['size'] > 10 * 1024 * 1024 ) {
							$count_class = 'warn';
							$status      = 'Large file';
						}
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $label ); ?></strong><br>
								<code style="font-size:11px;color:#666"><?php echo esc_html( $stats['path'] ); ?></code>
							</td>
							<td class="<?php echo esc_attr( $count_class ); ?>">
								<?php echo esc_html( $stats['is_file'] ? '1 file' : number_format( $stats['count'] ) ); ?>
							</td>
							<td><?php echo esc_html( size_format( $stats['size'] ) ); ?></td>
							<td class="<?php echo esc_attr( $count_class ); ?>"><?php echo esc_html( $status ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $problem_files ) ) : ?>
			<h4 style="margin-top:20px;color:#d63638">Problem Files Detected</h4>
			<ul>
				<?php foreach ( $problem_files as $pf ) : ?>
				<li><strong><?php echo esc_html( $pf['file'] ); ?></strong>: <?php echo esc_html( size_format( $pf['size'] ) ); ?> — <?php echo esc_html( $pf['issue'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>

		<div class="box">
			<h2>Cron Health</h2>
			<p>Stuck or overdue cron jobs can cause performance issues and failed background tasks.</p>

			<div class="stat">
				<b class="<?php echo esc_attr( $cron_count > 100 ? 'warn' : 'good' ); ?>"><?php echo (int) $cron_count; ?></b>
				Scheduled Events
			</div>
			<div class="stat">
				<b class="<?php echo esc_attr( count( $overdue_crons ) > 10 ? 'bad' : ( count( $overdue_crons ) > 0 ? 'warn' : 'good' ) ); ?>">
					<?php echo (int) count( $overdue_crons ); ?>
				</b> Overdue
			</div>

			<?php if ( ! empty( $overdue_crons ) ) : ?>
			<h4 style="margin-top:20px;color:#dba617">Overdue Cron Jobs</h4>
			<table>
				<thead><tr><th>Hook</th><th>Scheduled</th><th>Overdue By</th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $overdue_crons, 0, 10 ) as $oc ) : ?>
					<tr>
						<td><code><?php echo esc_html( $oc['hook'] ); ?></code></td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $oc['scheduled'] ) ); ?></td>
						<td class="warn"><?php echo esc_html( human_time_diff( $oc['scheduled'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><em>Overdue crons may indicate WP-Cron isn't running. Check if DISABLE_WP_CRON is set and a system cron is configured.</em></p>
			<?php endif; ?>

			<details style="margin-top:15px">
				<summary style="cursor:pointer;color:#2271b1">Show all cron hooks (<?php echo (int) count( $cron_hooks ); ?>)</summary>
				<table style="margin-top:10px">
					<thead><tr><th>Hook</th><th>Events</th><th>Next Run</th></tr></thead>
					<tbody>
						<?php foreach ( array_slice( $cron_hooks, 0, 30, true ) as $hook => $data ) : ?>
						<tr>
							<td><code style="font-size:11px"><?php echo esc_html( $hook ); ?></code></td>
							<td><?php echo (int) $data['count']; ?></td>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i', $data['next'] ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</div>

		<div class="box">
			<h2>Object Cache</h2>
			<table>
				<tr>
					<td><strong>Type</strong></td>
					<td class="<?php echo esc_attr( $object_cache ? 'good' : 'warn' ); ?>"><?php echo esc_html( $object_cache_type ); ?></td>
				</tr>
				<?php if ( $object_cache_detail ) : ?>
				<tr>
					<td><strong>Drop-in</strong></td>
					<td><?php echo esc_html( $object_cache_detail ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<td><strong>Working</strong></td>
					<td class="<?php echo esc_attr( $object_cache_working ? 'good' : 'bad' ); ?>">
						<?php echo $object_cache_working ? 'Yes (set/get test passed)' : 'No (set/get test failed!)'; ?>
					</td>
				</tr>
				<?php
				global $wp_object_cache;
				if ( isset( $wp_object_cache ) && is_object( $wp_object_cache ) ) :
					?>
					<tr>
						<td><strong>Class</strong></td>
						<td><code><?php echo esc_html( get_class( $wp_object_cache ) ); ?></code></td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<div class="box">
			<h2>Active Hooks Analysis</h2>
			<p>Hooks with many callbacks can slow down execution.</p>
			<table>
				<thead><tr><th>Hook</th><th>Callbacks</th><th>Status</th></tr></thead>
				<tbody>
					<?php
					foreach ( $heavy_hooks as $hook => $count ) :
						$class  = $count > 50 ? 'bad' : ( $count > 25 ? 'warn' : 'good' );
						$status = $count > 50 ? 'Very heavy' : ( $count > 25 ? 'Heavy' : 'Normal' );
						?>
						<tr>
							<td><code><?php echo esc_html( $hook ); ?></code></td>
							<td class="<?php echo esc_attr( $class ); ?>"><?php echo (int) $count; ?></td>
							<td class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $status ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:10px"><em>Note: <code>user_has_cap</code> fires on every permission check. Many callbacks here = slow admin.</em></p>
		</div>

		<div class="box">
			<h2>Server Health Recommendations</h2>
			<ul>
				<?php if ( ! empty( $overdue_crons ) ) : ?>
				<li class="warn"><strong><?php echo (int) count( $overdue_crons ); ?> overdue cron jobs</strong>. Check if WP-Cron is working or set up a system cron.</li>
				<?php endif; ?>

				<?php foreach ( $fs_stats as $label => $stats ) : ?>
					<?php if ( ! $stats['error'] && $stats['count'] > 5000 ) : ?>
					<li class="<?php echo esc_attr( $stats['count'] > 10000 ? 'bad' : 'warn' ); ?>">
						<strong><?php echo esc_html( $label ); ?></strong> has <?php echo esc_html( number_format( $stats['count'] ) ); ?> files. Consider cleanup.
					</li>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if ( ! $object_cache ) : ?>
				<li class="warn"><strong>No object cache</strong>. Install Redis or Memcached for better performance.</li>
				<?php endif; ?>

				<?php if ( ! ( function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ) ) : ?>
				<li class="bad"><strong>OPcache not enabled</strong>. This is likely your biggest performance issue. Enable it in PHP settings to cache compiled PHP — can save 2-3 seconds per page load with 60+ plugins.</li>
				<?php endif; ?>

				<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) : ?>
				<li class="warn"><strong>Debug logging is ON</strong>. This creates log files and slows the site. Disable on production.</li>
				<?php endif; ?>

				<?php if ( isset( $heavy_hooks['user_has_cap'] ) && $heavy_hooks['user_has_cap'] > 30 ) : ?>
				<li class="warn"><strong>user_has_cap has <?php echo (int) $heavy_hooks['user_has_cap']; ?> callbacks</strong>. Permission checks are expensive. Consider caching role checks.</li>
				<?php endif; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render OPcache diagnostics.
	 */
	private static function render_opcache_diagnostics() {
		$opcache_available = function_exists( 'opcache_get_status' );
		$opcache_status    = $opcache_available ? @opcache_get_status( false ) : false;
		$opcache_config    = $opcache_available && function_exists( 'opcache_get_configuration' ) ? @opcache_get_configuration() : false;
		?>
		<div class="box" style="<?php echo ! $opcache_status ? 'background:#fff0f0;border-color:#d63638;' : ''; ?>">
			<h2>OPcache</h2>

			<?php if ( ! $opcache_available ) : ?>
				<p class="bad" style="font-size:16px;font-weight:bold;">OPcache extension is NOT loaded.</p>
				<p>Without OPcache, PHP reparses every file on every request. With 60+ plugins, this adds seconds to every page load.</p>
				<p><strong>Fix:</strong> Enable <code>opcache</code> in your PHP settings (cPanel > MultiPHP INI Editor, or contact your host).</p>

			<?php elseif ( ! $opcache_status ) : ?>
				<p class="bad" style="font-size:16px;font-weight:bold;">OPcache is installed but DISABLED.</p>
				<p>The extension is loaded but not active. This is likely your biggest performance bottleneck.</p>
				<p><strong>Fix:</strong> Set <code>opcache.enable = 1</code> in php.ini or via cPanel > MultiPHP INI Editor.</p>

			<?php else : ?>
				<p class="good" style="font-size:14px;font-weight:bold;">OPcache is enabled and active.</p>

				<div style="display:flex;flex-wrap:wrap;gap:10px;margin:15px 0;">
					<?php
					$hit_rate = round( $opcache_status['opcache_statistics']['opcache_hit_rate'], 1 );
					$hit_class = $hit_rate > 95 ? 'good' : ( $hit_rate > 80 ? 'warn' : 'bad' );
					?>
					<div class="stat">
						<b class="<?php echo esc_attr( $hit_class ); ?>"><?php echo esc_html( $hit_rate ); ?>%</b>
						Hit Rate
					</div>
					<div class="stat">
						<b><?php echo (int) $opcache_status['opcache_statistics']['num_cached_scripts']; ?></b>
						Cached Scripts
					</div>
					<div class="stat">
						<b><?php echo esc_html( round( $opcache_status['memory_usage']['used_memory'] / 1024 / 1024, 1 ) ); ?>MB</b>
						Memory Used
					</div>
					<div class="stat">
						<b><?php echo esc_html( round( $opcache_status['memory_usage']['free_memory'] / 1024 / 1024, 1 ) ); ?>MB</b>
						Memory Free
					</div>
					<div class="stat">
						<b class="<?php echo esc_attr( $opcache_status['cache_full'] ? 'bad' : 'good' ); ?>">
							<?php echo $opcache_status['cache_full'] ? 'FULL!' : 'No'; ?>
						</b>
						Cache Full
					</div>
				</div>

				<table>
					<tr>
						<td><strong>Hits</strong></td>
						<td><?php echo esc_html( number_format( $opcache_status['opcache_statistics']['hits'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Misses</strong></td>
						<td class="<?php echo esc_attr( $opcache_status['opcache_statistics']['misses'] > 1000 ? 'warn' : '' ); ?>">
							<?php echo esc_html( number_format( $opcache_status['opcache_statistics']['misses'] ) ); ?>
						</td>
					</tr>
					<tr>
						<td><strong>Wasted Memory</strong></td>
						<td class="<?php echo esc_attr( $opcache_status['memory_usage']['wasted_percentage'] > 10 ? 'warn' : '' ); ?>">
							<?php echo esc_html( round( $opcache_status['memory_usage']['wasted_memory'] / 1024 / 1024, 1 ) ); ?>MB
							(<?php echo esc_html( round( $opcache_status['memory_usage']['wasted_percentage'], 1 ) ); ?>%)
						</td>
					</tr>
					<tr>
						<td><strong>OOM Restarts</strong></td>
						<td class="<?php echo esc_attr( $opcache_status['opcache_statistics']['oom_restarts'] > 0 ? 'bad' : 'good' ); ?>">
							<?php echo (int) $opcache_status['opcache_statistics']['oom_restarts']; ?>
							<?php if ( $opcache_status['opcache_statistics']['oom_restarts'] > 0 ) : ?>
								— Increase <code>opcache.memory_consumption</code>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $opcache_config ) : ?>
					<tr>
						<td><strong>Max Memory</strong></td>
						<td><?php echo esc_html( $opcache_config['directives']['opcache.memory_consumption'] ); ?>MB</td>
					</tr>
					<tr>
						<td><strong>Max Files</strong></td>
						<td>
							<?php echo esc_html( number_format( $opcache_config['directives']['opcache.max_accelerated_files'] ) ); ?>
							<?php
							$cached = $opcache_status['opcache_statistics']['num_cached_scripts'];
							$max    = $opcache_config['directives']['opcache.max_accelerated_files'];
							if ( $cached > $max * 0.9 ) :
								?>
								<span class="warn"> — approaching limit, increase <code>opcache.max_accelerated_files</code></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong>Revalidate Freq</strong></td>
						<td>
							<?php echo (int) $opcache_config['directives']['opcache.revalidate_freq']; ?>s
							<?php if ( $opcache_config['directives']['opcache.revalidate_freq'] < 60 ) : ?>
								<span class="warn"> — set to 60+ for production</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong>Validate Timestamps</strong></td>
						<td><?php echo $opcache_config['directives']['opcache.validate_timestamps'] ? 'Yes' : 'No (manual reset needed)'; ?></td>
					</tr>
					<?php if ( isset( $opcache_config['directives']['opcache.jit'] ) ) : ?>
					<tr>
						<td><strong>JIT</strong></td>
						<td><?php echo esc_html( $opcache_config['directives']['opcache.jit'] ); ?></td>
					</tr>
					<?php endif; ?>
					<?php endif; ?>
				</table>

				<?php if ( $opcache_status['cache_full'] ) : ?>
				<p class="bad" style="margin-top:15px;font-weight:bold;">
					Cache is FULL! PHP can't cache new scripts. Increase <code>opcache.memory_consumption</code> to at least 256.
				</p>
				<?php endif; ?>

				<?php if ( $hit_rate < 80 ) : ?>
				<p class="warn" style="margin-top:15px;">
					Hit rate is low. This could mean the cache is too small, revalidation is too frequent, or the site was recently restarted.
				</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render outgoing HTTP requests made during this page load.
	 */
	private static function render_http_requests() {
		$requests = $GLOBALS['whodunnit_http_requests'] ?? [];

		?>
		<div class="box">
			<h2>Outgoing HTTP Requests</h2>
			<p>External HTTP calls block page rendering. Each request can add 0.5-5 seconds.</p>

			<?php if ( empty( $requests ) ) : ?>
				<p class="good">No outgoing HTTP requests detected during this page load.</p>
			<?php else : ?>
				<?php
				$total_ms = 0;
				foreach ( $requests as $req ) {
					if ( isset( $req['duration'] ) ) {
						$total_ms += $req['duration'];
					}
				}
				?>

				<div style="display:flex;gap:10px;margin-bottom:15px;">
					<div class="stat">
						<b class="<?php echo esc_attr( count( $requests ) > 3 ? 'bad' : ( count( $requests ) > 0 ? 'warn' : 'good' ) ); ?>">
							<?php echo (int) count( $requests ); ?>
						</b> Requests
					</div>
					<div class="stat">
						<b class="<?php echo esc_attr( $total_ms > 2000 ? 'bad' : ( $total_ms > 500 ? 'warn' : 'good' ) ); ?>">
							<?php echo esc_html( number_format( $total_ms ) ); ?>ms
						</b> Total Time
					</div>
				</div>

				<table>
					<thead><tr><th>URL</th><th>Method</th><th>Time</th><th>Status</th></tr></thead>
					<tbody>
						<?php foreach ( $requests as $req ) : ?>
						<tr>
							<td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
								<code style="font-size:11px;" title="<?php echo esc_attr( $req['url'] ); ?>">
									<?php echo esc_html( substr( $req['url'], 0, 80 ) ); ?><?php echo strlen( $req['url'] ) > 80 ? '...' : ''; ?>
								</code>
							</td>
							<td><?php echo esc_html( $req['method'] ?? 'GET' ); ?></td>
							<td class="<?php echo esc_attr( ( $req['duration'] ?? 0 ) > 1000 ? 'bad' : ( ( $req['duration'] ?? 0 ) > 500 ? 'warn' : '' ) ); ?>">
								<?php echo isset( $req['duration'] ) ? esc_html( number_format( $req['duration'] ) ) . 'ms' : 'pending'; ?>
							</td>
							<td><?php echo isset( $req['status'] ) ? (int) $req['status'] : '—'; ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_ms > 1000 ) : ?>
				<p class="warn" style="margin-top:15px;">
					<strong><?php echo esc_html( number_format( $total_ms / 1000, 1 ) ); ?>s</strong> spent on external HTTP requests.
					Consider blocking unnecessary update checks or license pings on admin pages.
				</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render execution time breakdown.
	 */
	private static function render_execution_breakdown() {
		$total_time = defined( 'WHODUNNIT_START' ) ? ( microtime( true ) - WHODUNNIT_START ) * 1000 : 0;

		// Get DB time from SAVEQUERIES if available.
		global $wpdb;
		$db_time = 0;
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) {
			$db_time = array_sum( array_column( $wpdb->queries, 1 ) ) * 1000;
		}

		// HTTP time.
		$http_time = 0;
		$requests  = $GLOBALS['whodunnit_http_requests'] ?? [];
		foreach ( $requests as $req ) {
			if ( isset( $req['duration'] ) ) {
				$http_time += $req['duration'];
			}
		}

		$php_time = max( 0, $total_time - $db_time - $http_time );

		if ( $total_time <= 0 ) {
			return;
		}

		$db_pct   = round( ( $db_time / $total_time ) * 100 );
		$http_pct = round( ( $http_time / $total_time ) * 100 );
		$php_pct  = 100 - $db_pct - $http_pct;
		?>
		<div class="box">
			<h2>Execution Time Breakdown</h2>
			<p>Where is the time going? This shows the split between PHP processing, database queries, and external HTTP requests.</p>

			<div style="display:flex;gap:10px;margin-bottom:15px;">
				<div class="stat">
					<b><?php echo esc_html( number_format( $total_time / 1000, 2 ) ); ?>s</b>
					Total
				</div>
				<div class="stat">
					<b style="color:#2271b1;"><?php echo esc_html( number_format( $php_time / 1000, 2 ) ); ?>s</b>
					PHP (<?php echo (int) $php_pct; ?>%)
				</div>
				<div class="stat">
					<b style="color:#00a32a;"><?php echo esc_html( number_format( $db_time / 1000, 2 ) ); ?>s</b>
					Database (<?php echo (int) $db_pct; ?>%)
				</div>
				<div class="stat">
					<b style="color:#dba617;"><?php echo esc_html( number_format( $http_time / 1000, 2 ) ); ?>s</b>
					HTTP (<?php echo (int) $http_pct; ?>%)
				</div>
			</div>

			<!-- Visual bar -->
			<div style="width:100%;height:30px;display:flex;border-radius:3px;overflow:hidden;margin-bottom:10px;">
				<?php if ( $php_pct > 0 ) : ?>
				<div style="width:<?php echo (int) $php_pct; ?>%;background:#2271b1;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:bold;">
					<?php echo $php_pct > 10 ? 'PHP ' . (int) $php_pct . '%' : ''; ?>
				</div>
				<?php endif; ?>
				<?php if ( $db_pct > 0 ) : ?>
				<div style="width:<?php echo (int) $db_pct; ?>%;background:#00a32a;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:bold;">
					<?php echo $db_pct > 10 ? 'DB ' . (int) $db_pct . '%' : ''; ?>
				</div>
				<?php endif; ?>
				<?php if ( $http_pct > 0 ) : ?>
				<div style="width:<?php echo (int) $http_pct; ?>%;background:#dba617;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:bold;">
					<?php echo $http_pct > 10 ? 'HTTP ' . (int) $http_pct . '%' : ''; ?>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( $php_pct > 70 && $total_time > 3000 ) : ?>
			<div style="padding:10px;background:#fff8e5;border-left:4px solid #dba617;margin-top:10px;">
				<strong>PHP is the bottleneck (<?php echo (int) $php_pct; ?>% of time).</strong>
				The database is fast — most time is spent in PHP execution. Key factors:
				<ul style="margin:10px 0 0 20px;">
					<li><strong>OPcache</strong> — If disabled, PHP reparses thousands of files per request</li>
					<li><strong>Plugin count</strong> — <?php echo (int) count( get_option( 'active_plugins', [] ) ); ?> plugins all initialize on every request</li>
					<li><strong>Memory: <?php echo esc_html( number_format( memory_get_peak_usage( true ) / 1024 / 1024, 0 ) ); ?>MB</strong> — High memory = more GC pauses</li>
					<li><strong>Shared hosting CPU</strong> — Other sites on the same server affect your speed</li>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( $http_pct > 30 && $http_time > 1000 ) : ?>
			<div style="padding:10px;background:#fff8e5;border-left:4px solid #dba617;margin-top:10px;">
				<strong>External HTTP requests are adding <?php echo esc_html( number_format( $http_time / 1000, 1 ) ); ?>s.</strong>
				Plugins are making blocking API calls (license checks, update pings). Consider blocking non-essential HTTP requests on admin pages.
			</div>
			<?php endif; ?>

			<p style="margin-top:10px;color:#888;font-size:12px;">
				<em>PHP time = Total - Database - HTTP. Includes plugin initialization, hook execution, template rendering, and memory allocation.
				<?php if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) : ?>
					Enable Deep Scan for accurate DB time measurement.
				<?php endif; ?>
				</em>
			</p>
		</div>
		<?php
	}
}
