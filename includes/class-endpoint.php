<?php
/**
 * Public health endpoints: pretty /health, ?health_check=1 fallback, and the
 * REST route. Minimal public payload; token-protected diagnostics on top.
 *
 * @package HealthEndpoint
 */

namespace ProjectMakers_Health_Endpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Endpoint {

	/** @var Endpoint|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'render' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Rewrite slug for the pretty endpoint (default "health").
	 *
	 * @return string
	 */
	public function slug() {
		$slug = defined( 'HEALTH_ENDPOINT_SLUG' ) ? (string) HEALTH_ENDPOINT_SLUG : 'health';
		$slug = trim( $slug, '/' );
		return '' !== $slug ? $slug : 'health';
	}

	public function query_vars( $vars ) {
		$vars[] = 'health_check';
		return $vars;
	}

	public function register_rewrite_rules() {
		add_rewrite_rule( '^' . $this->slug() . '/?$', 'index.php?health_check=1', 'top' );
	}

	public function register_rest() {
		register_rest_route(
			'health/v1',
			'/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_callback' ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_callback( $request ) {
		nocache_headers();

		$detailed = $this->token_ok( $request->get_param( 'token' ) );
		list( $payload, $code ) = $this->build_payload( $detailed );

		if ( 'plain' === $request->get_param( 'format' ) ) {
			$response = new \WP_REST_Response( 200 === $code ? 'OK' : 'ERROR', $code );
			$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
			return $response;
		}

		return new \WP_REST_Response( $payload, $code );
	}

	/**
	 * Constant-time check of the diagnostics token.
	 *
	 * @param string|null $provided Token from query/body, if any.
	 * @return bool
	 */
	public function token_ok( $provided = null ) {
		$configured = Settings::token();
		$configured = (string) apply_filters( 'health_endpoint_token', $configured );

		if ( '' === trim( $configured ) ) {
			return false;
		}

		if ( null === $provided || '' === $provided ) {
			$provided = isset( $_SERVER['HTTP_X_HEALTH_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HEALTH_TOKEN'] ) ) : '';
		}

		$provided = is_scalar( $provided ) ? (string) $provided : '';
		if ( '' === $provided ) {
			return false;
		}

		return hash_equals( $configured, $provided );
	}

	/**
	 * Build the JSON payload and HTTP status. Status reflects DB connectivity only.
	 *
	 * @param bool $detailed Include token-only diagnostics.
	 * @return array{0:array,1:int}
	 */
	public function build_payload( $detailed = false ) {
		$db = check_db();

		$healthy = (bool) $db['ok'];

		$payload = array(
			'status' => $healthy ? 'ok' : 'error',
			'db'     => $db['ok'] ? 'connected' : 'down',
			'time'   => gmdate( 'c' ),
		);

		if ( $detailed ) {
			$payload['detail'] = $this->detail( $db['latency_ms'] );
		}

		/** Filter the full response payload. */
		$payload = apply_filters( 'health_endpoint_payload', $payload, $detailed, $healthy );

		$code = $healthy ? 200 : 503;

		/** Filter the HTTP status code. */
		$code = (int) apply_filters( 'health_endpoint_status_code', $code, $healthy, $payload );

		return array( $payload, $code );
	}

	/**
	 * Extended diagnostics (token-only).
	 *
	 * @param int|null $db_latency_ms Measured DB round-trip.
	 * @return array
	 */
	private function detail( $db_latency_ms ) {
		$disk = disk_usage();
		$cpu  = cpu_load();
		$ram  = ram_usage();

		$detail = array(
			'plugin_version' => HEALTH_ENDPOINT_VERSION,
			'php_version'    => PHP_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'object_cache'   => wp_using_ext_object_cache() ? 'external' : 'internal',
			'db_latency_ms'  => $db_latency_ms,
			'disk_used_pct'  => $disk ? $disk['used_pct'] : null,
			'disk_free_mb'   => $disk ? $disk['free_mb'] : null,
			'cpu_pct'        => $cpu ? $cpu['pct'] : null,
			'cpu_load_1m'    => $cpu ? $cpu['load_1m'] : null,
			'cpu_cores'      => $cpu ? $cpu['cores'] : null,
			'ram_used_pct'   => $ram ? $ram['used_pct'] : null,
			'ram_avail_mb'   => $ram ? $ram['avail_mb'] : null,
			'https'          => is_ssl() ? 'yes' : 'no',
			'memory_limit'   => ini_get( 'memory_limit' ),
			'server_time'    => gmdate( 'c' ),
		);

		if ( function_exists( 'WC' ) ) {
			$detail['woocommerce'] = defined( 'WC_VERSION' ) ? WC_VERSION : 'active';
		}

		/** Filter the diagnostics block (add custom checks here). */
		return apply_filters( 'health_endpoint_detail', $detail );
	}

	/**
	 * Render for the pretty rule and the query-string fallback.
	 */
	public function render() {
		$is_health_request = get_query_var( 'health_check' );

		if ( '1' !== $is_health_request && 'true' !== $is_health_request ) {
			return;
		}

		// Prevent storing (cannot stop serving an already-cached page; see README).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		nocache_headers();

		$token    = ( isset( $_GET['token'] ) && is_scalar( $_GET['token'] ) ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$detailed = $this->token_ok( $token );

		list( $payload, $code ) = $this->build_payload( $detailed );

		status_header( $code );

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$format         = ( isset( $_GET['format'] ) && is_scalar( $_GET['format'] ) ) ? sanitize_text_field( wp_unslash( $_GET['format'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$is_head        = 'HEAD' === strtoupper( $request_method );
		$is_plain       = 'plain' === $format;

		if ( $is_plain ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			if ( ! $is_head ) {
				echo 200 === $code ? 'OK' : 'ERROR';
			}
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! $is_head ) {
			echo wp_json_encode( $payload );
		}
		exit;
	}
}
