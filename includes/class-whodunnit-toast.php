<?php
/**
 * Whodunnit Toast — Real-time performance overlay.
 *
 * Floating dark-themed panel on every page showing:
 * - Total load time, query count, DB time, memory
 * - Top sources by query time (bar chart)
 * - Slow queries (>20ms)
 *
 * Only visible to administrators.
 *
 * Controls:
 *   ?perf=0      Hide toast for this request
 *   ?perf=1      Show toast (overrides cookie)
 *   ?perf=debug  Full query list debug page with filtering
 *   Minimize (_) Collapse the body, keep the header
 *   Close (x)    Hide toast and set cookie to keep it hidden
 *
 * @package Whodunnit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Whodunnit_Toast {

	private static $start_time;

	public static function init() {
		// Only if toast is enabled in settings.
		if ( get_option( 'whodunnit_toast_enabled', '1' ) !== '1' ) {
			return;
		}

		self::$start_time = microtime( true );

		add_action( 'wp_footer', [ __CLASS__, 'render' ], 9999 );
		add_action( 'admin_footer', [ __CLASS__, 'render' ], 9999 );
	}

	/**
	 * Render the toast overlay.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// ?perf=0 hides for this request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin-only display toggle.
		if ( isset( $_GET['perf'] ) && $_GET['perf'] === '0' ) {
			return;
		}

		// ?perf=debug renders the full debug page instead.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin-only display toggle.
		if ( isset( $_GET['perf'] ) && $_GET['perf'] === 'debug' ) {
			self::render_debug_page();
			return;
		}

		// Check cookie for hidden state (unless ?perf=1 forces it).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin-only display toggle; cookie is a simple flag.
		if ( ! isset( $_GET['perf'] ) && isset( $_COOKIE['whodunnit_toast_hidden'] ) && $_COOKIE['whodunnit_toast_hidden'] === '1' ) {
			self::render_show_button();
			return;
		}

		global $wpdb;

		$total_time  = microtime( true ) - self::$start_time;
		$memory      = memory_get_peak_usage( true ) / 1024 / 1024;
		$query_count = $wpdb->num_queries;
		$query_time  = 0;

		// Analyze queries.
		$by_source    = [];
		$slow_queries = [];

		if ( ! empty( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				$sql   = $q[0];
				$time  = $q[1] * 1000;
				$trace = $q[2];
				$query_time += $time;

				$source = Whodunnit_Profiler::detect_source( $sql, $trace );

				if ( ! isset( $by_source[ $source ] ) ) {
					$by_source[ $source ] = [ 'count' => 0, 'time' => 0 ];
				}
				$by_source[ $source ]['count']++;
				$by_source[ $source ]['time'] += $time;

				if ( $time > 20 ) {
					$slow_queries[] = [
						'sql'    => substr( $sql, 0, 80 ),
						'time'   => $time,
						'source' => $source,
					];
				}
			}
		}

		uasort( $by_source, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );

		usort( $slow_queries, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );

		// Colour coding.
		$time_color  = $total_time > 4 ? '#dc3232' : ( $total_time > 2 ? '#ffb900' : '#46b450' );
		$query_color = $query_count > 500 ? '#dc3232' : ( $query_count > 200 ? '#ffb900' : '#46b450' );

		?>
		<div id="whodunnit-toast" style="
			position: fixed;
			bottom: 10px;
			right: 10px;
			width: 380px;
			max-height: 70vh;
			background: #1e1e1e;
			color: #e0e0e0;
			font-family: 'SF Mono', Monaco, 'Consolas', monospace;
			font-size: 11px;
			z-index: 999999;
			border-radius: 8px;
			box-shadow: 0 4px 20px rgba(0,0,0,0.4);
			overflow: hidden;
		">
			<!-- Header -->
			<div style="
				background: linear-gradient(135deg, #2d2d2d 0%, #1e1e1e 100%);
				padding: 10px 12px;
				display: flex;
				justify-content: space-between;
				align-items: center;
				border-bottom: 1px solid #333;
			">
				<span style="font-weight: bold; color: #fff;">Whodunnit</span>
				<div>
					<span onclick="document.getElementById('whodunnit-toast-body').style.display = document.getElementById('whodunnit-toast-body').style.display === 'none' ? 'block' : 'none'"
						  style="cursor: pointer; padding: 2px 6px; margin-right: 5px;">_</span>
					<span onclick="document.cookie='whodunnit_toast_hidden=1;path=/';document.getElementById('whodunnit-toast').remove();"
						  style="cursor: pointer; color: #888; padding: 2px 6px;">x</span>
				</div>
			</div>

			<div id="whodunnit-toast-body">
				<!-- Stats Row -->
				<div style="
					display: flex;
					padding: 10px;
					background: #252525;
					border-bottom: 1px solid #333;
					gap: 8px;
				">
					<div style="flex: 1; text-align: center; padding: 8px; background: #1e1e1e; border-radius: 4px;">
						<div style="color: <?php echo esc_attr( $time_color ); ?>; font-size: 18px; font-weight: bold;">
							<?php echo esc_html( number_format( $total_time, 2 ) ); ?>s
						</div>
						<div style="color: #888; font-size: 10px;">TOTAL</div>
					</div>
					<div style="flex: 1; text-align: center; padding: 8px; background: #1e1e1e; border-radius: 4px;">
						<div style="color: <?php echo esc_attr( $query_color ); ?>; font-size: 18px; font-weight: bold;">
							<?php echo (int) $query_count; ?>
						</div>
						<div style="color: #888; font-size: 10px;">QUERIES</div>
					</div>
					<div style="flex: 1; text-align: center; padding: 8px; background: #1e1e1e; border-radius: 4px;">
						<div style="color: #87ceeb; font-size: 18px; font-weight: bold;">
							<?php echo esc_html( number_format( $query_time, 0 ) ); ?>
						</div>
						<div style="color: #888; font-size: 10px;">DB MS</div>
					</div>
					<div style="flex: 1; text-align: center; padding: 8px; background: #1e1e1e; border-radius: 4px;">
						<div style="color: #dda0dd; font-size: 18px; font-weight: bold;">
							<?php echo esc_html( number_format( $memory, 0 ) ); ?>
						</div>
						<div style="color: #888; font-size: 10px;">MB</div>
					</div>
				</div>

				<!-- By Source -->
				<div style="padding: 10px; max-height: 150px; overflow-y: auto;">
					<div style="color: #ffb900; font-weight: bold; margin-bottom: 8px; font-size: 10px; text-transform: uppercase;">
						By Source
					</div>
					<?php
					$i = 0;
					foreach ( $by_source as $source => $data ) :
						if ( $i++ >= 8 ) break;
						$pct       = $query_time > 0 ? ( $data['time'] / $query_time ) * 100 : 0;
						$bar_color = $pct > 30 ? '#dc3232' : ( $pct > 15 ? '#ffb900' : '#46b450' );
						?>
						<div style="display: flex; align-items: center; margin-bottom: 4px; gap: 8px;">
							<div style="width: 100px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #87ceeb;">
								<?php echo esc_html( $source ); ?>
							</div>
							<div style="flex: 1; background: #333; height: 8px; border-radius: 4px; overflow: hidden;">
								<div style="width: <?php echo esc_attr( min( $pct, 100 ) ); ?>%; height: 100%; background: <?php echo esc_attr( $bar_color ); ?>;"></div>
							</div>
							<div style="width: 50px; text-align: right; color: <?php echo esc_attr( $bar_color ); ?>;">
								<?php echo esc_html( number_format( $data['time'], 0 ) ); ?>ms
							</div>
							<div style="width: 30px; text-align: right; color: #666;">
								<?php echo (int) $data['count']; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Slow Queries -->
				<?php if ( ! empty( $slow_queries ) ) : ?>
				<div style="padding: 10px; border-top: 1px solid #333; max-height: 150px; overflow-y: auto;">
					<div style="color: #dc3232; font-weight: bold; margin-bottom: 8px; font-size: 10px; text-transform: uppercase;">
						Slow Queries (>20ms)
					</div>
					<?php foreach ( array_slice( $slow_queries, 0, 5 ) as $sq ) : ?>
					<div style="
						background: #252525;
						padding: 6px 8px;
						margin-bottom: 4px;
						border-radius: 4px;
						border-left: 3px solid <?php echo esc_attr( $sq['time'] > 50 ? '#dc3232' : '#ffb900' ); ?>;
					">
						<div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
							<span style="color: #98fb98;"><?php echo esc_html( $sq['source'] ); ?></span>
							<span style="color: #dc3232; font-weight: bold;"><?php echo esc_html( number_format( $sq['time'], 0 ) ); ?>ms</span>
						</div>
						<div style="color: #888; font-size: 10px; word-break: break-all;">
							<?php echo esc_html( $sq['sql'] ); ?>...
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<!-- Footer -->
				<div style="padding: 8px 10px; background: #252525; border-top: 1px solid #333; color: #666; font-size: 10px;">
					<?php echo esc_html( $_SERVER['REQUEST_URI'] ); ?> |
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=whodunnit&savequeries=1' ) ); ?>" style="color: #87ceeb; text-decoration: none;">Full Report</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a small "Show Perf" button when the toast is hidden via cookie.
	 */
	private static function render_show_button() {
		?>
		<div id="whodunnit-toggle" style="
			position: fixed;
			bottom: 10px;
			right: 10px;
			background: #1e1e1e;
			color: #fff;
			padding: 8px 12px;
			border-radius: 4px;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
			font-size: 12px;
			z-index: 999999;
			cursor: pointer;
			opacity: 0.7;
		" onclick="document.cookie='whodunnit_toast_hidden=;path=/;expires=Thu, 01 Jan 1970 00:00:01 GMT';window.location.href=window.location.pathname+'?perf=1';">
			Whodunnit
		</div>
		<?php
	}

	/**
	 * Render the full debug page (?perf=debug).
	 *
	 * Dark-themed full-page query list grouped by source, with filtering.
	 */
	private static function render_debug_page() {
		global $wpdb;

		$queries_by_source = [];

		if ( ! empty( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				$sql    = $q[0];
				$time   = $q[1] * 1000;
				$trace  = $q[2];
				$source = Whodunnit_Profiler::detect_source( $sql, $trace );

				if ( ! isset( $queries_by_source[ $source ] ) ) {
					$queries_by_source[ $source ] = [];
				}

				$queries_by_source[ $source ][] = [
					'sql'     => $sql,
					'time_ms' => round( $time, 2 ),
					'trace'   => $trace,
				];
			}
		}

		// Sort each source by time.
		foreach ( $queries_by_source as $source => &$queries ) {
			usort( $queries, function ( $a, $b ) {
				return $b['time_ms'] <=> $a['time_ms'];
			} );
		}

		?>
		<!DOCTYPE html>
		<html>
		<head>
			<title>Whodunnit Debug — <?php echo esc_html( $_SERVER['REQUEST_URI'] ); ?></title>
			<style>
				body { font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 12px; background: #1e1e1e; color: #e0e0e0; padding: 20px; margin: 0; }
				h1 { color: #ffb900; font-size: 16px; }
				h2 { color: #87ceeb; font-size: 14px; margin-top: 30px; border-bottom: 1px solid #333; padding-bottom: 5px; }
				.query { background: #252525; padding: 10px; margin: 5px 0; border-radius: 4px; border-left: 3px solid #333; }
				.query.slow { border-left-color: #dc3232; }
				.query.medium { border-left-color: #ffb900; }
				.time { color: #dc3232; font-weight: bold; }
				.sql { color: #98fb98; word-break: break-all; white-space: pre-wrap; }
				.trace { color: #666; font-size: 10px; margin-top: 5px; max-height: 60px; overflow: auto; }
				.stats { background: #252525; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
				.filter { margin-bottom: 20px; }
				.filter input { background: #333; border: 1px solid #444; color: #fff; padding: 8px; width: 300px; border-radius: 4px; }
				.source-count { color: #888; }
			</style>
		</head>
		<body>
			<h1>Whodunnit Debug: <?php echo esc_html( $_SERVER['REQUEST_URI'] ); ?></h1>

			<div class="stats">
				Total Queries: <strong><?php echo (int) $wpdb->num_queries; ?></strong> |
				Total Time: <strong><?php echo esc_html( number_format( array_sum( array_column( $wpdb->queries, 1 ) ) * 1000, 0 ) ); ?>ms</strong> |
				Memory: <strong><?php echo esc_html( number_format( memory_get_peak_usage( true ) / 1024 / 1024, 0 ) ); ?>MB</strong>
			</div>

			<div class="filter">
				<input type="text" id="whodunnit-filter" placeholder="Filter queries (e.g., transient, gf_form, usermeta)..." onkeyup="whodunnitFilterQueries()">
			</div>

			<?php foreach ( $queries_by_source as $source => $queries ) : ?>
				<h2><?php echo esc_html( $source ); ?> <span class="source-count">(<?php echo (int) count( $queries ); ?> queries, <?php echo esc_html( number_format( array_sum( array_column( $queries, 'time_ms' ) ), 0 ) ); ?>ms)</span></h2>

				<?php
				foreach ( $queries as $q ) :
					$class = 'query';
					if ( $q['time_ms'] > 50 ) $class .= ' slow';
					elseif ( $q['time_ms'] > 20 ) $class .= ' medium';
					?>
					<div class="<?php echo esc_attr( $class ); ?>" data-sql="<?php echo esc_attr( strtolower( $q['sql'] ) ); ?>">
						<span class="time"><?php echo esc_html( number_format( $q['time_ms'], 2 ) ); ?>ms</span>
						<div class="sql"><?php echo esc_html( $q['sql'] ); ?></div>
						<div class="trace"><?php echo esc_html( $q['trace'] ); ?></div>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<script>
				function whodunnitFilterQueries() {
					var filter = document.getElementById('whodunnit-filter').value.toLowerCase();
					var queries = document.querySelectorAll('.query');
					queries.forEach(function(q) {
						var sql = q.getAttribute('data-sql');
						q.style.display = sql.includes(filter) ? 'block' : 'none';
					});
				}
			</script>
		</body>
		</html>
		<?php
		exit;
	}
}
