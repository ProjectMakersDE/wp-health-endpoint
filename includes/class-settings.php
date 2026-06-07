<?php
/**
 * Settings store + admin page (status, endpoints, monitoring config).
 *
 * @package HealthEndpoint
 */

namespace ProjectMakers\HealthEndpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION     = 'health_endpoint_settings';
	const GROUP      = 'health_endpoint_group';
	const PAGE       = 'health-endpoint';
	const CAPABILITY = 'manage_options';

	/** @var Settings|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_health_endpoint_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'admin_post_health_endpoint_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_health_endpoint_generate_token', array( $this, 'handle_generate_token' ) );
		add_filter( 'plugin_action_links_' . HEALTH_ENDPOINT_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'alert_email'        => '',
			'monitoring_enabled' => 0,
			'check_interval'     => 60,
			'disk_threshold'     => 90,
			'cpu_threshold'      => 80,
			'cpu_minutes'        => 5,
			'ram_threshold'      => 90,
			'ram_minutes'        => 5,
			'alert_cooldown'     => 60,
			'recovery_email'     => 1,
			'token'              => '',
		);
	}

	public static function check_interval_options() {
		return array( 60, 120, 300, 600, 900, 1800, 3600 );
	}

	public static function normalize_check_interval( $seconds ) {
		$seconds = (int) $seconds;
		return in_array( $seconds, self::check_interval_options(), true ) ? $seconds : 60;
	}

	public static function format_interval( $seconds ) {
		$seconds = self::normalize_check_interval( $seconds );
		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %d: number of seconds */
				_n( '%d second', '%d seconds', $seconds, 'health-endpoint' ),
				$seconds
			);
		}

		$minutes = (int) round( $seconds / 60 );
		return sprintf(
			/* translators: %d: number of minutes */
			_n( '%d minute', '%d minutes', $minutes, 'health-endpoint' ),
			$minutes
		);
	}

	/**
	 * Merged settings (stored values over defaults).
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = self::defaults();
		return array_intersect_key( wp_parse_args( $stored, $defaults ), $defaults );
	}

	/**
	 * The diagnostics token: wp-config constant wins over the stored option.
	 *
	 * @return string
	 */
	public static function token() {
		if ( defined( 'HEALTH_ENDPOINT_TOKEN' ) && '' !== trim( (string) HEALTH_ENDPOINT_TOKEN ) ) {
			return (string) HEALTH_ENDPOINT_TOKEN;
		}
		$s = self::get();
		return (string) $s['token'];
	}

	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize the whole settings array.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$emails = array();
		foreach ( preg_split( '/[,\s]+/', (string) ( $in['alert_email'] ?? '' ) ) as $e ) {
			$e = sanitize_email( trim( $e ) );
			if ( '' !== $e && is_email( $e ) ) {
				$emails[] = $e;
			}
		}
		$out['alert_email'] = implode( ', ', array_unique( $emails ) );

		$out['monitoring_enabled'] = empty( $in['monitoring_enabled'] ) ? 0 : 1;
		$out['recovery_email']     = empty( $in['recovery_email'] ) ? 0 : 1;
		$out['check_interval']     = self::normalize_check_interval( $in['check_interval'] ?? $d['check_interval'] );

		$out['disk_threshold'] = $this->clamp_int( $in['disk_threshold'] ?? $d['disk_threshold'], 1, 100, $d['disk_threshold'] );
		$out['cpu_threshold']  = $this->clamp_int( $in['cpu_threshold'] ?? $d['cpu_threshold'], 1, 1000, $d['cpu_threshold'] );
		$out['ram_threshold']  = $this->clamp_int( $in['ram_threshold'] ?? $d['ram_threshold'], 1, 100, $d['ram_threshold'] );
		$out['cpu_minutes']    = $this->clamp_int( $in['cpu_minutes'] ?? $d['cpu_minutes'], 1, 60, $d['cpu_minutes'] );
		$out['ram_minutes']    = $this->clamp_int( $in['ram_minutes'] ?? $d['ram_minutes'], 1, 60, $d['ram_minutes'] );
		$out['alert_cooldown'] = $this->clamp_int( $in['alert_cooldown'] ?? $d['alert_cooldown'], 0, 1440, $d['alert_cooldown'] );

		$out['token'] = sanitize_text_field( (string) ( $in['token'] ?? '' ) );

		// (Re)schedule or clear the monitoring cron to match the new state.
		if ( $out['monitoring_enabled'] ) {
			Monitor::instance()->sync_schedule( $out, true );
		} else {
			Monitor::unschedule();
		}

		add_settings_error( self::OPTION, 'saved', __( 'Settings saved.', 'health-endpoint' ), 'updated' );

		return $out;
	}

	private function clamp_int( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $fallback;
		}
		return (int) max( $min, min( $max, (int) $value ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'ProjectMakers Health Endpoint', 'health-endpoint' ),
			__( 'Health', 'health-endpoint' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-heart',
			80
		);
	}

	public function action_links( $links ) {
		$url       = admin_url( 'admin.php?page=' . self::PAGE );
		$settings  = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'health-endpoint' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'health-endpoint-admin',
			HEALTH_ENDPOINT_URL . 'assets/admin.css',
			array(),
			HEALTH_ENDPOINT_VERSION
		);
	}

	public function handle_test_email() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'health-endpoint' ) );
		}
		check_admin_referer( 'health_endpoint_test_email' );

		$sent   = Monitor::instance()->send_test_email();
		$status = $sent ? 'testmail_ok' : 'testmail_fail';

		wp_safe_redirect( add_query_arg( 'he_notice', $status, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public function handle_run_now() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'health-endpoint' ) );
		}
		check_admin_referer( 'health_endpoint_run_now' );

		Monitor::instance()->run_checks( true );

		wp_safe_redirect( add_query_arg( 'he_notice', 'ran', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public function handle_generate_token() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'health-endpoint' ) );
		}
		check_admin_referer( 'health_endpoint_generate_token' );

		if ( defined( 'HEALTH_ENDPOINT_TOKEN' ) && '' !== trim( (string) HEALTH_ENDPOINT_TOKEN ) ) {
			wp_safe_redirect( add_query_arg( 'he_notice', 'token_locked', admin_url( 'admin.php?page=' . self::PAGE ) ) );
			exit;
		}

		$s          = self::get();
		$s['token'] = wp_generate_password( 48, false, false );
		update_option( self::OPTION, $s, false );

		wp_safe_redirect( add_query_arg( 'he_notice', 'token_generated', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$s          = self::get();
		$snapshot   = Monitor::instance()->snapshot();
		$last_run   = (int) Monitor::instance()->last_run();
		$last_duration_ms = Monitor::instance()->last_duration_ms();
		$token_set  = '' !== self::token();
		$token_lock = defined( 'HEALTH_ENDPOINT_TOKEN' ) && '' !== trim( (string) HEALTH_ENDPOINT_TOKEN );

		$endpoints = array(
			'Pretty'        => home_url( '/health' ),
			'Query-Fallback' => home_url( '/?health_check=1' ),
			'Plain (OK/ERROR)' => home_url( '/?health_check=1&format=plain' ),
			'REST'          => rest_url( 'health/v1/check' ),
		);

		?>
		<div class="wrap he-wrap">
			<div class="he-header">
				<span class="dashicons dashicons-heart"></span>
				<div>
					<h1><?php esc_html_e( 'ProjectMakers Health Endpoint', 'health-endpoint' ); ?></h1>
					<p class="he-sub">
						<?php esc_html_e( 'Uptime endpoint & server monitoring', 'health-endpoint' ); ?>
					</p>
				</div>
				<span class="he-version">v<?php echo esc_html( HEALTH_ENDPOINT_VERSION ); ?></span>
			</div>

			<?php $this->render_notice(); ?>
			<?php settings_errors( self::OPTION ); ?>

			<?php if ( $s['monitoring_enabled'] && '' === trim( (string) $s['alert_email'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: admin email address */
							esc_html__( 'No alert email is set; alerts fall back to the site admin address (%s). Set a dedicated address below.', 'health-endpoint' ),
							esc_html( get_option( 'admin_email' ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="he-grid">

				<div class="he-card">
					<h2><?php esc_html_e( 'What this plugin does', 'health-endpoint' ); ?></h2>
					<p>
						<?php esc_html_e( 'Exposes a tiny public health endpoint for uptime monitors (Uptime Kuma & co.) that only confirms WordPress and the database respond; no sensitive data is ever revealed. Optionally it monitors the server internally (database, disk, CPU, RAM) and emails you when something breaches your thresholds.', 'health-endpoint' ); ?>
					</p>
				</div>

				<div class="he-card">
					<h2><?php esc_html_e( 'Live status', 'health-endpoint' ); ?></h2>
					<ul class="he-status">
						<?php
						$this->status_row( 'Database', $snapshot['db']['ok'], $snapshot['db']['ok'] ? __( 'connected', 'health-endpoint' ) . ' (' . (int) $snapshot['db']['latency_ms'] . ' ms)' : __( 'down', 'health-endpoint' ) );
						$this->metric_row( 'Disk used', $snapshot['disk'], 'used_pct', (int) $s['disk_threshold'] );
						$this->metric_row( 'CPU (load/core)', $snapshot['cpu'], 'pct', (int) $s['cpu_threshold'] );
						$this->metric_row( 'RAM used', $snapshot['ram'], 'used_pct', (int) $s['ram_threshold'] );
						?>
					</ul>
					<p class="he-muted">
						<?php
						if ( $last_run ) {
							/* translators: %s: human time diff */
							printf( esc_html__( 'Last internal check: %s ago', 'health-endpoint' ), esc_html( human_time_diff( $last_run ) ) );
							if ( null !== $last_duration_ms ) {
								echo '<br />';
								printf(
									/* translators: %s: formatted duration */
									esc_html__( 'Last check duration: %s', 'health-endpoint' ),
									esc_html( $this->format_duration( $last_duration_ms ) )
								);
							}
						} else {
							esc_html_e( 'Internal monitoring has not run yet.', 'health-endpoint' );
						}
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="he-inline">
						<?php wp_nonce_field( 'health_endpoint_run_now' ); ?>
						<input type="hidden" name="action" value="health_endpoint_run_now" />
						<button type="submit" class="button"><?php esc_html_e( 'Run check now', 'health-endpoint' ); ?></button>
					</form>
				</div>

				<div class="he-card he-card-wide">
					<h2><?php esc_html_e( 'Endpoints', 'health-endpoint' ); ?></h2>
					<table class="he-endpoints">
						<?php foreach ( $endpoints as $label => $url ) : ?>
							<tr>
								<th><?php echo esc_html( $label ); ?></th>
								<td><code><?php echo esc_html( $url ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</table>
					<p class="he-muted">
						<?php esc_html_e( 'Healthy: HTTP 200 {"status":"ok"}. DB down: HTTP 503. Append a valid token for diagnostics:', 'health-endpoint' ); ?>
						<code>?token=...</code> <?php esc_html_e( 'or header', 'health-endpoint' ); ?> <code>X-Health-Token: ...</code>.
						<?php echo $token_set ? esc_html__( 'Diagnostics token is configured.', 'health-endpoint' ) : esc_html__( 'No token configured; diagnostics mode is off.', 'health-endpoint' ); ?>
					</p>
				</div>

				<div class="he-card he-card-wide">
					<h2><?php esc_html_e( 'Monitoring & alerts', 'health-endpoint' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( self::GROUP ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Internal monitoring', 'health-endpoint' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[monitoring_enabled]" value="1" <?php checked( $s['monitoring_enabled'], 1 ); ?> />
										<?php esc_html_e( 'Check the server at the configured interval and alert on breaches', 'health-endpoint' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Runs via WP-Cron. For reliable checks on low-traffic sites, set up a real server cron (see README).', 'health-endpoint' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="he-interval"><?php esc_html_e( 'Check interval', 'health-endpoint' ); ?></label></th>
								<td>
									<select id="he-interval" name="<?php echo esc_attr( self::OPTION ); ?>[check_interval]">
										<?php foreach ( self::check_interval_options() as $seconds ) : ?>
											<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( (int) $s['check_interval'], (int) $seconds ); ?>>
												<?php echo esc_html( self::format_interval( $seconds ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'How often the internal database, disk, CPU, and RAM checks should run.', 'health-endpoint' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="he-email"><?php esc_html_e( 'Alert email(s)', 'health-endpoint' ); ?></label></th>
								<td>
									<input type="text" id="he-email" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="you@example.com, ops@example.com" />
									<p class="description"><?php esc_html_e( 'Comma-separated. Alerts are sent here when a check fails.', 'health-endpoint' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Disk threshold', 'health-endpoint' ); ?></th>
								<td>
									<?php $this->select_pct( 'disk_threshold', (int) $s['disk_threshold'], array( 60, 70, 80, 85, 90, 95 ) ); ?>
									<?php esc_html_e( 'used -> alert', 'health-endpoint' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'CPU threshold', 'health-endpoint' ); ?></th>
								<td>
									<?php $this->select_pct( 'cpu_threshold', (int) $s['cpu_threshold'], array( 70, 80, 90, 100, 150, 200 ) ); ?>
									<?php esc_html_e( 'load-per-core, sustained for', 'health-endpoint' ); ?>
									<?php $this->number( 'cpu_minutes', (int) $s['cpu_minutes'], 1, 60 ); ?>
									<?php esc_html_e( 'minute(s)', 'health-endpoint' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'RAM threshold', 'health-endpoint' ); ?></th>
								<td>
									<?php $this->select_pct( 'ram_threshold', (int) $s['ram_threshold'], array( 70, 80, 85, 90, 95 ) ); ?>
									<?php esc_html_e( 'used, sustained for', 'health-endpoint' ); ?>
									<?php $this->number( 'ram_minutes', (int) $s['ram_minutes'], 1, 60 ); ?>
									<?php esc_html_e( 'minute(s)', 'health-endpoint' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Repeat-alert cooldown', 'health-endpoint' ); ?></th>
								<td>
									<?php $this->number( 'alert_cooldown', (int) $s['alert_cooldown'], 0, 1440 ); ?>
									<?php esc_html_e( 'minute(s) between repeated alerts for the same issue', 'health-endpoint' ); ?>
									<p class="description"><?php esc_html_e( 'Set to 0 to send a single alert per incident (no reminders until it recovers).', 'health-endpoint' ); ?></p>
									<p>
										<label>
											<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[recovery_email]" value="1" <?php checked( $s['recovery_email'], 1 ); ?> />
											<?php esc_html_e( 'Also send a recovery email when an issue clears', 'health-endpoint' ); ?>
										</label>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="he-token"><?php esc_html_e( 'Diagnostics token', 'health-endpoint' ); ?></label></th>
								<td>
									<?php if ( $token_lock ) : ?>
										<input type="text" class="regular-text" value="<?php esc_attr_e( 'Defined in wp-config.php (HEALTH_ENDPOINT_TOKEN)', 'health-endpoint' ); ?>" disabled />
										<p class="description"><?php esc_html_e( 'The wp-config.php constant takes precedence over this field.', 'health-endpoint' ); ?></p>
									<?php else : ?>
										<input type="password" id="he-token" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off" />
										<p class="description"><?php esc_html_e( 'Unlocks the diagnostics payload. Leave empty to keep diagnostics off. For best security define HEALTH_ENDPOINT_TOKEN in wp-config.php instead.', 'health-endpoint' ); ?></p>
										<p>
											<button type="submit" class="button" form="he-generate-token-form"><?php esc_html_e( 'Generate token', 'health-endpoint' ); ?></button>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
						<?php submit_button(); ?>
					</form>

					<?php if ( ! $token_lock ) : ?>
						<form id="he-generate-token-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="he-hidden-form">
							<?php wp_nonce_field( 'health_endpoint_generate_token' ); ?>
							<input type="hidden" name="action" value="health_endpoint_generate_token" />
						</form>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="he-inline">
						<?php wp_nonce_field( 'health_endpoint_test_email' ); ?>
						<input type="hidden" name="action" value="health_endpoint_test_email" />
						<button type="submit" class="button"><?php esc_html_e( 'Send test email', 'health-endpoint' ); ?></button>
					</form>
				</div>

			</div>

			<div class="he-footer">
				<em>
					<?php esc_html_e( 'Made with', 'health-endpoint' ); ?>
					<span class="he-heart" aria-label="<?php esc_attr_e( 'love', 'health-endpoint' ); ?>">&hearts;</span>
					<?php esc_html_e( 'by', 'health-endpoint' ); ?>
					<a href="https://projectmakers.de" target="_blank" rel="noopener">ProjectMakers</a>
				</em>
			</div>
		</div>
		<?php
	}

	private function render_notice() {
		$notice = isset( $_GET['he_notice'] ) ? sanitize_key( wp_unslash( $_GET['he_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $notice ) {
			return;
		}
		$map = array(
			'testmail_ok'     => array( 'updated', __( 'Test email sent.', 'health-endpoint' ) ),
			'testmail_fail'   => array( 'error', __( 'Test email could not be sent. Check the alert address and your mailer (e.g. WP Mail SMTP).', 'health-endpoint' ) ),
			'ran'             => array( 'updated', __( 'Internal check executed.', 'health-endpoint' ) ),
			'token_generated' => array( 'updated', __( 'Diagnostics token generated.', 'health-endpoint' ) ),
			'token_locked'    => array( 'error', __( 'Diagnostics token is defined in wp-config.php and cannot be generated here.', 'health-endpoint' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] )
		);
	}

	private function status_row( $label, $ok, $text ) {
		printf(
			'<li><span class="he-dot %s"></span><span class="he-label">%s</span><span class="he-val">%s</span></li>',
			$ok ? 'is-ok' : 'is-bad',
			esc_html( $label ),
			esc_html( $text )
		);
	}

	private function metric_row( $label, $metric, $key, $threshold ) {
		if ( null === $metric ) {
			$this->status_row( $label, true, __( 'n/a on this host', 'health-endpoint' ) );
			return;
		}
		$val = (float) $metric[ $key ];
		$ok  = $val < $threshold;
		printf(
			'<li><span class="he-dot %s"></span><span class="he-label">%s</span><span class="he-val">%s%% <span class="he-muted">(limit %d%%)</span></span></li>',
			$ok ? 'is-ok' : 'is-bad',
			esc_html( $label ),
			esc_html( (string) $val ),
			(int) $threshold
		);
	}

	private function select_pct( $key, $current, array $options ) {
		if ( ! in_array( (int) $current, $options, true ) ) {
			$options[] = (int) $current;
			sort( $options );
		}
		echo '<select name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '">';
		foreach ( $options as $opt ) {
			printf(
				'<option value="%1$d" %2$s>%1$d%%</option>',
				(int) $opt,
				selected( (int) $current, (int) $opt, false )
			);
		}
		echo '</select>';
	}

	private function number( $key, $current, $min, $max ) {
		printf(
			'<input type="number" class="small-text" name="%s" value="%d" min="%d" max="%d" />',
			esc_attr( self::OPTION . '[' . $key . ']' ),
			(int) $current,
			(int) $min,
			(int) $max
		);
	}

	private function format_duration( $milliseconds ) {
		$milliseconds = max( 0, (int) $milliseconds );
		if ( $milliseconds < 1000 ) {
			return sprintf(
				/* translators: %d: duration in milliseconds */
				_n( '%d ms', '%d ms', $milliseconds, 'health-endpoint' ),
				$milliseconds
			);
		}

		return sprintf(
			/* translators: %.2f: duration in seconds */
			__( '%.2f s', 'health-endpoint' ),
			$milliseconds / 1000
		);
	}
}
