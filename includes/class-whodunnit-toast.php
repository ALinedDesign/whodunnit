<?php
/**
 * Whodunnit Toast — Real-time performance overlay.
 *
 * Floating dark-themed panel on every page showing total load time, query
 * count, DB time, peak memory, top sources, and slow queries. Only visible
 * to administrators.
 *
 * Controls (via assets/js/whodunnit.js):
 *   ?perf=0   Hide toast for this request
 *   ?perf=1   Show toast (overrides cookie)
 *   Minimize  Collapse the body
 *   Close     Hide and set 30-day cookie
 *
 * Full query inspection lives on the profiler page (Tools > Whodunnit > Debug),
 * not as a footer-injected page replacement.
 *
 * @package Whodunnit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Whodunnit_Toast {

	private static $start_time;

	public static function init() {
		if ( get_option( 'whodunnit_toast_enabled', '1' ) !== '1' ) {
			return;
		}

		self::$start_time = microtime( true );

		add_action( 'wp_footer', array( __CLASS__, 'render' ), 9999 );
		add_action( 'admin_footer', array( __CLASS__, 'render' ), 9999 );
	}

	/**
	 * Render the toast overlay.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$perf = whodunnit_read_query_param( 'perf' );

		if ( $perf === '0' ) {
			return;
		}

		// If the user has hidden the toast, show a small re-open button instead.
		if ( $perf !== '1' && whodunnit_read_cookie( 'whodunnit_toast_hidden' ) === '1' ) {
			self::render_show_button();
			return;
		}

		global $wpdb;

		$total_time  = microtime( true ) - self::$start_time;
		$memory      = memory_get_peak_usage( true ) / 1024 / 1024;
		$query_count = $wpdb->num_queries;
		$query_time  = 0;

		$by_source    = array();
		$slow_queries = array();

		if ( ! empty( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				$sql        = $q[0];
				$time       = $q[1] * 1000;
				$trace      = $q[2];
				$query_time += $time;

				$source = Whodunnit_Profiler::detect_source( $sql, $trace );

				if ( ! isset( $by_source[ $source ] ) ) {
					$by_source[ $source ] = array( 'count' => 0, 'time' => 0 );
				}
				$by_source[ $source ]['count']++;
				$by_source[ $source ]['time'] += $time;

				if ( $time > 20 ) {
					$slow_queries[] = array(
						'sql'    => substr( $sql, 0, 80 ),
						'time'   => $time,
						'source' => $source,
					);
				}
			}
		}

		uasort( $by_source, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );

		usort( $slow_queries, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );

		$time_color  = $total_time > 4 ? '#dc3232' : ( $total_time > 2 ? '#ffb900' : '#46b450' );
		$query_color = $query_count > 500 ? '#dc3232' : ( $query_count > 200 ? '#ffb900' : '#46b450' );

		$report_url = admin_url( 'tools.php?page=whodunnit&tab=deep' );
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
					<span data-whodunnit-action="minimize" role="button" tabindex="0"
						  style="cursor: pointer; padding: 2px 6px; margin-right: 5px;">_</span>
					<span data-whodunnit-action="close" role="button" tabindex="0"
						  style="cursor: pointer; color: #888; padding: 2px 6px;">x</span>
				</div>
			</div>

			<div id="whodunnit-toast-body">
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

				<div style="padding: 10px; max-height: 150px; overflow-y: auto;">
					<div style="color: #ffb900; font-weight: bold; margin-bottom: 8px; font-size: 10px; text-transform: uppercase;">
						By Source
					</div>
					<?php
					$i = 0;
					foreach ( $by_source as $source => $data ) :
						if ( $i++ >= 8 ) {
							break;
						}
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

				<?php if ( ! empty( $slow_queries ) ) : ?>
				<div style="padding: 10px; border-top: 1px solid #333; max-height: 150px; overflow-y: auto;">
					<div style="color: #dc3232; font-weight: bold; margin-bottom: 8px; font-size: 10px; text-transform: uppercase;">
						Slow Queries (&gt;20ms)
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

				<div style="padding: 8px 10px; background: #252525; border-top: 1px solid #333; color: #666; font-size: 10px;">
					<a href="<?php echo esc_url( $report_url ); ?>" style="color: #87ceeb; text-decoration: none;">Full Report</a>
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
		<div id="whodunnit-toggle" role="button" tabindex="0" style="
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
		">
			Whodunnit
		</div>
		<?php
	}
}
