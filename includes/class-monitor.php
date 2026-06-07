<?php
/**
 * Internal server monitoring: cron samples DB/disk/CPU/RAM on a configurable
 * interval, keeps a short rolling history for sustained-breach detection, and
 * sends email alerts (with cooldown + optional recovery notice).
 *
 * @package HealthEndpoint
 */

namespace ProjectMakers_Health_Endpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Monitor {

	const STATE     = 'health_endpoint_state';
	const CRON_HOOK = 'health_endpoint_cron';
	const DEFAULT_INTERVAL = 60;
	const MAX_SAMPLES = 120;
	const MAX_AGE     = 5400; // 90 min in seconds.
	const DB_CONFIRM    = 2;  // consecutive failed samples before a DB alert (debounce).
	const DISK_CONFIRM  = 2;  // consecutive over-threshold samples before a disk alert.
	const GAP_TOLERANCE = 150; // max seconds between consecutive breaching samples.

	/** @var Monitor|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_checks' ) );
		// Self-heal the schedule if monitoring is on but the event was lost.
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	public function add_schedule( $schedules ) {
		foreach ( Settings::check_interval_options() as $seconds ) {
			$schedule = self::schedule_name( $seconds );
			if ( ! isset( $schedules[ $schedule ] ) ) {
				$schedules[ $schedule ] = array(
					'interval' => (int) $seconds,
					'display'  => sprintf(
						/* translators: %s: interval label, e.g. "5 minutes" */
						__( 'Every %s (Health Endpoint)', 'projectmakers-health-endpoint' ),
						Settings::format_interval( $seconds )
					),
				);
			}
		}
		return $schedules;
	}

	private static function schedule_name( $seconds ) {
		return 'health_endpoint_' . (int) $seconds . 's';
	}

	/**
	 * Ensure the cron event matches the current monitoring settings.
	 *
	 * @param array|null $settings Optional settings array to use instead of saved options.
	 * @param bool       $force    Whether to clear and recreate the event even if one exists.
	 */
	public function sync_schedule( $settings = null, $force = false ) {
		$s = is_array( $settings ) ? $settings : Settings::get();

		if ( empty( $s['monitoring_enabled'] ) ) {
			self::unschedule();
			return;
		}

		$interval = Settings::normalize_check_interval( $s['check_interval'] ?? self::DEFAULT_INTERVAL );
		$schedule = self::schedule_name( $interval );
		$current  = wp_next_scheduled( self::CRON_HOOK );

		if ( function_exists( 'wp_get_schedule' ) && $current && $schedule !== wp_get_schedule( self::CRON_HOOK ) ) {
			$force = true;
		}

		if ( $force || ! $current ) {
			self::unschedule();
			wp_schedule_event( time() + $interval, $schedule, self::CRON_HOOK );
		}
	}

	/**
	 * Ensure the cron event exists when monitoring is enabled; remove it otherwise.
	 */
	public function maybe_schedule() {
		$this->sync_schedule();
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	private function state() {
		$state = get_option( self::STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return wp_parse_args(
			$state,
			array(
				'last_run'         => 0,
				'last_duration_ms' => null,
				'samples'          => array(),
				'alerts'           => array(),
				'snapshot'         => array(),
			)
		);
	}

	private function save_state( $state ) {
		update_option( self::STATE, $state, false );
	}

	public function last_run() {
		$s = $this->state();
		return (int) $s['last_run'];
	}

	public function last_duration_ms() {
		$s = $this->state();
		return isset( $s['last_duration_ms'] ) && is_numeric( $s['last_duration_ms'] ) ? (int) $s['last_duration_ms'] : null;
	}

	/**
	 * Current metrics for the admin status panel (live, not cached).
	 *
	 * @return array
	 */
	public function snapshot() {
		return array(
			'db'   => check_db(),
			'disk' => disk_usage(),
			'cpu'  => cpu_load(),
			'ram'  => ram_usage(),
		);
	}

	/**
	 * Sample everything, evaluate breaches, and dispatch alerts.
	 *
	 * @param bool $manual True when triggered from the admin "Run now" button.
	 */
	public function run_checks( $manual = false ) {
		$started = microtime( true );
		$s       = Settings::get();
		$now     = time();
		$state   = $this->state();

		$db   = check_db();
		$disk = disk_usage();
		$cpu  = cpu_load();
		$ram  = ram_usage();

		$state['snapshot'] = array(
			'db'   => $db,
			'disk' => $disk,
			'cpu'  => $cpu,
			'ram'  => $ram,
		);
		$state['last_run'] = $now;

		// "Run now" is a read-only diagnostic: refresh the live snapshot for the
		// admin panel but never mutate history or dispatch alert emails.
		if ( $manual ) {
			$state['last_duration_ms'] = $this->elapsed_ms( $started );
			$this->save_state( $state );
			return;
		}

		// Append the new sample and trim the history.
		$state['samples'][] = array(
			't'    => $now,
			'cpu'  => $cpu ? (float) $cpu['pct'] : null,
			'ram'  => $ram ? (float) $ram['used_pct'] : null,
			'db'   => $db['ok'] ? 1 : 0,
			'disk' => $disk ? (float) $disk['used_pct'] : null,
		);
		$state['samples'] = $this->trim_samples( $state['samples'], $now );

		// Evaluate each check (DB/disk debounced over a few samples; CPU/RAM sustained).
		$breaches = array();

		if ( $this->down_streak( $state['samples'], self::DB_CONFIRM ) ) {
			$breaches['db'] = __( 'Database is not reachable.', 'projectmakers-health-endpoint' );
		}

		if ( $disk && $this->over_streak( $state['samples'], 'disk', (int) $s['disk_threshold'], self::DISK_CONFIRM ) ) {
			$breaches['disk'] = sprintf(
				/* translators: 1: used %, 2: threshold %, 3: free MB */
				__( 'Disk usage %1$s%% reached the %2$d%% limit (%3$s MB free).', 'projectmakers-health-endpoint' ),
				$disk['used_pct'],
				(int) $s['disk_threshold'],
				number_format_i18n( $disk['free_mb'] )
			);
		}

		if ( $this->sustained( $state['samples'], 'cpu', (int) $s['cpu_threshold'], (int) $s['cpu_minutes'], $now ) ) {
			$breaches['cpu'] = sprintf(
				/* translators: 1: current %, 2: threshold %, 3: minutes */
				__( 'CPU load %1$s%% per core has stayed above %2$d%% for ~%3$d min.', 'projectmakers-health-endpoint' ),
				$cpu ? $cpu['pct'] : '?',
				(int) $s['cpu_threshold'],
				(int) $s['cpu_minutes']
			);
		}

		if ( $this->sustained( $state['samples'], 'ram', (int) $s['ram_threshold'], (int) $s['ram_minutes'], $now ) ) {
			$breaches['ram'] = sprintf(
				/* translators: 1: current %, 2: threshold %, 3: minutes */
				__( 'RAM usage %1$s%% has stayed above %2$d%% for ~%3$d min.', 'projectmakers-health-endpoint' ),
				$ram ? $ram['used_pct'] : '?',
				(int) $s['ram_threshold'],
				(int) $s['ram_minutes']
			);
		}

		$state['alerts'] = $this->reconcile_alerts( $state['alerts'], $breaches, $s, $now );
		$state['last_duration_ms'] = $this->elapsed_ms( $started );

		$this->save_state( $state );
	}

	private function elapsed_ms( $started ) {
		return max( 0, (int) round( ( microtime( true ) - (float) $started ) * 1000 ) );
	}

	private function trim_samples( $samples, $now ) {
		$cutoff = $now - self::MAX_AGE;
		$samples = array_values(
			array_filter(
				$samples,
				function ( $row ) use ( $cutoff ) {
					return isset( $row['t'] ) && $row['t'] >= $cutoff;
				}
			)
		);
		if ( count( $samples ) > self::MAX_SAMPLES ) {
			$samples = array_slice( $samples, -self::MAX_SAMPLES );
		}
		return $samples;
	}

	/**
	 * True if the newest contiguous breaching streak for $key spans at least
	 * ($minutes - 1) minutes. Time-based, so it tolerates irregular cron cadence.
	 *
	 * @param array  $samples   Rolling samples.
	 * @param string $key       'cpu' or 'ram'.
	 * @param int    $threshold Percent threshold.
	 * @param int    $minutes   Required sustained minutes.
	 * @param int    $now       Current timestamp.
	 * @return bool
	 */
	private function sustained( $samples, $key, $threshold, $minutes, $now ) {
		$required_span = max( 0, ( $minutes - 1 ) * 60 );

		$newest_t = null;
		$oldest_t = null;
		$prev_t   = null;
		$count    = 0;

		// Walk newest to oldest. A genuine sub-threshold reading ends the streak; a
		// null (metric momentarily unavailable) is skipped, not treated as a reset;
		// too large a gap between two breaching samples breaks contiguity.
		for ( $i = count( $samples ) - 1; $i >= 0; $i-- ) {
			$row = $samples[ $i ];
			$val = isset( $row[ $key ] ) ? $row[ $key ] : null;
			$t   = (int) $row['t'];

			if ( null === $val ) {
				continue;
			}
			if ( (float) $val < $threshold ) {
				break;
			}
			if ( null !== $prev_t && ( $prev_t - $t ) > self::GAP_TOLERANCE ) {
				break;
			}

			if ( null === $newest_t ) {
				$newest_t = $t;
			}
			$oldest_t = $t;
			$prev_t   = $t;
			$count++;
		}

		if ( null === $newest_t ) {
			return false; // No current breaching reading.
		}

		// For minutes <= 1 the newest breaching sample alone is enough.
		if ( $required_span <= 0 ) {
			return true;
		}

		return $count >= 2 && ( $newest_t - $oldest_t ) >= $required_span;
	}

	/**
	 * True if the newest $need samples are all DB-down (contiguous).
	 *
	 * @param array $samples History.
	 * @param int   $need    Required consecutive failures.
	 * @return bool
	 */
	private function down_streak( $samples, $need ) {
		$count = 0;
		for ( $i = count( $samples ) - 1; $i >= 0; $i-- ) {
			$v = isset( $samples[ $i ]['db'] ) ? (int) $samples[ $i ]['db'] : 1;
			if ( 0 === $v ) {
				$count++;
			} else {
				break;
			}
		}
		return $count >= $need;
	}

	/**
	 * True if the newest $need samples for $key are all >= $threshold (contiguous;
	 * a null reading stops the streak so an unknown value never asserts a breach).
	 *
	 * @param array  $samples   History.
	 * @param string $key       Sample key.
	 * @param int    $threshold Percent threshold.
	 * @param int    $need      Required consecutive over-threshold samples.
	 * @return bool
	 */
	private function over_streak( $samples, $key, $threshold, $need ) {
		$count = 0;
		for ( $i = count( $samples ) - 1; $i >= 0; $i-- ) {
			$val = isset( $samples[ $i ][ $key ] ) ? $samples[ $i ][ $key ] : null;
			if ( null === $val || (float) $val < $threshold ) {
				break;
			}
			$count++;
		}
		return $count >= $need;
	}

	/**
	 * Update per-check alert state and send mails as needed.
	 *
	 * @param array $alerts   Previous alert states.
	 * @param array $breaches Currently breaching checks (key => message).
	 * @param array $s        Settings.
	 * @param int   $now      Timestamp.
	 * @return array New alert states.
	 */
	private function reconcile_alerts( $alerts, $breaches, $s, $now ) {
		if ( ! is_array( $alerts ) ) {
			$alerts = array();
		}

		$cooldown   = (int) $s['alert_cooldown'] * 60;
		$recipients = $this->recipients( $s );
		$labels     = $this->labels();

		$checks = array( 'db', 'disk', 'cpu', 'ram' );

		foreach ( $checks as $check ) {
			$prev      = isset( $alerts[ $check ] ) && is_array( $alerts[ $check ] ) ? $alerts[ $check ] : array(
				'active'        => false,
				'since'         => 0,
				'last_notified' => 0,
			);
			$breaching = isset( $breaches[ $check ] );

			if ( $breaching ) {
				$is_new    = empty( $prev['active'] );
				$cooled    = $cooldown > 0 && ( $now - (int) $prev['last_notified'] ) >= $cooldown;
				$send_now  = $is_new || $cooled;

				$prev['active'] = true;
				if ( $is_new ) {
					$prev['since'] = $now;
				}

				if ( $send_now && $recipients ) {
					$this->send_alert( $recipients, $labels[ $check ], $breaches[ $check ], $prev['since'], $now );
					$prev['last_notified'] = $now;
				}
			} else {
				if ( ! empty( $prev['active'] ) ) {
					if ( ! empty( $s['recovery_email'] ) && $recipients ) {
						$this->send_recovery( $recipients, $labels[ $check ], (int) $prev['since'], $now );
					}
					$prev['active']        = false;
					$prev['since']         = 0;
					$prev['last_notified'] = 0;
				}
			}

			$alerts[ $check ] = $prev;
		}

		return $alerts;
	}

	private function labels() {
		return array(
			'db'   => __( 'Database', 'projectmakers-health-endpoint' ),
			'disk' => __( 'Disk space', 'projectmakers-health-endpoint' ),
			'cpu'  => __( 'CPU load', 'projectmakers-health-endpoint' ),
			'ram'  => __( 'Memory (RAM)', 'projectmakers-health-endpoint' ),
		);
	}

	/**
	 * @param array $s Settings.
	 * @return array List of valid recipient emails.
	 */
	private function recipients( $s ) {
		$list = array();
		foreach ( preg_split( '/[,\s]+/', (string) $s['alert_email'] ) as $e ) {
			$e = trim( $e );
			if ( '' !== $e && is_email( $e ) ) {
				$list[] = $e;
			}
		}

		// Fall back to the site admin address so alerts are never silently dropped.
		if ( empty( $list ) ) {
			$admin = get_option( 'admin_email' );
			if ( $admin && is_email( $admin ) ) {
				$list[] = $admin;
			}
		}

		return array_values( array_unique( $list ) );
	}

	private function site_label() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = get_bloginfo( 'name' );
		return $name ? $name . ' (' . $host . ')' : $host;
	}

	private function send_alert( $recipients, $label, $message, $since, $now ) {
		$subject = sprintf( '[Health] %s - %s ALERT', $this->site_label(), $label );

		$lines = array(
			sprintf( '%s: %s', $label, $message ),
			'',
			sprintf( 'Site:    %s', home_url( '/' ) ),
			sprintf( 'Server:  %s', $this->server_name() ),
			sprintf( 'Since:   %s UTC', gmdate( 'Y-m-d H:i:s', $since ? $since : $now ) ),
			sprintf( 'Now:     %s UTC', gmdate( 'Y-m-d H:i:s', $now ) ),
			'',
			'-- Health Endpoint',
		);

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	private function send_recovery( $recipients, $label, $since, $now ) {
		$subject = sprintf( '[Health] %s - %s recovered', $this->site_label(), $label );

		$duration = $since ? human_time_diff( $since, $now ) : __( 'unknown', 'projectmakers-health-endpoint' );

		$lines = array(
			sprintf( '%s is back to normal.', $label ),
			'',
			sprintf( 'Site:     %s', home_url( '/' ) ),
			sprintf( 'Server:   %s', $this->server_name() ),
			sprintf( 'Duration: %s', $duration ),
			sprintf( 'Now:      %s UTC', gmdate( 'Y-m-d H:i:s', $now ) ),
			'',
			'-- Health Endpoint',
		);

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	private function server_name() {
		if ( function_exists( 'gethostname' ) ) {
			$h = gethostname();
			if ( $h ) {
				return $h;
			}
		}
		return isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : 'unknown';
	}

	/**
	 * Send a test email to the configured recipients.
	 *
	 * @return bool
	 */
	public function send_test_email() {
		$s          = Settings::get();
		$recipients = $this->recipients( $s );

		if ( ! $recipients ) {
			return false;
		}

		$subject = sprintf( '[Health] %s - test email', $this->site_label() );
		$lines   = array(
			'This is a test alert from the Health Endpoint plugin.',
			'If you received this, email alerts are working.',
			'',
			sprintf( 'Site:   %s', home_url( '/' ) ),
			sprintf( 'Server: %s', $this->server_name() ),
			sprintf( 'Now:    %s UTC', gmdate( 'Y-m-d H:i:s', time() ) ),
			'',
			'-- Health Endpoint',
		);

		return (bool) wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}
}
