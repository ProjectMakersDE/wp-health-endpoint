<?php
/**
 * Self-updater: lets WordPress see and install new versions published as GitHub
 * Releases. Works with public repos out of the box; for a private repo provide a
 * read-only token (settings field or HEALTH_ENDPOINT_GITHUB_TOKEN constant).
 *
 * @package HealthEndpoint
 */

namespace ProjectMakers\HealthEndpoint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Updater {

	/** @var string Absolute path to the main plugin file. */
	private $file;

	/** @var string owner/repo */
	private $repo;

	/** @var string plugin basename, e.g. health-endpoint/health-endpoint.php */
	private $basename;

	/** @var string plugin folder slug, e.g. health-endpoint */
	private $slug;

	/** @var string */
	private $transient_key;

	public function __construct( $file, $repo ) {
		$this->file     = $file;
		$this->repo     = trim( (string) $repo, '/' );
		$this->basename = plugin_basename( $file );

		$dir        = dirname( $this->basename );
		$this->slug = ( '.' === $dir || '' === $dir ) ? basename( $this->basename, '.php' ) : $dir;

		$this->transient_key = 'health_endpoint_update_' . md5( $this->repo );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'auth_headers' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * The token for private-repo access: constant wins over the settings option.
	 *
	 * @return string
	 */
	private function token() {
		if ( defined( 'HEALTH_ENDPOINT_GITHUB_TOKEN' ) && '' !== trim( (string) HEALTH_ENDPOINT_GITHUB_TOKEN ) ) {
			return (string) HEALTH_ENDPOINT_GITHUB_TOKEN;
		}
		$s = Settings::get();
		return (string) $s['github_token'];
	}

	/**
	 * Fetch (and cache) the latest GitHub release for the repo.
	 *
	 * @return object|null
	 */
	private function get_release() {
		$cached = get_transient( $this->transient_key );
		if ( is_object( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$url   = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$args  = array(
			'timeout' => 12,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'health-endpoint-updater',
			),
		);
		$token = $this->token();
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so we don't hammer the API / rate-limit.
			set_transient( $this->transient_key, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $data ) || empty( $data->tag_name ) ) {
			set_transient( $this->transient_key, 'none', HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $this->transient_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	private function version_from_tag( $tag ) {
		return ltrim( (string) $tag, 'vV' );
	}

	/**
	 * Resolve the downloadable package URL for a release.
	 *
	 * With a token (private repo) we use the API asset URL; the actual download is
	 * resolved in pre_download() so the token never reaches the CDN. Without a token
	 * (public repo) we use the direct browser_download_url so no auth is involved.
	 *
	 * @param object $release Release object.
	 * @return string
	 */
	private function package_url( $release ) {
		$has_token = '' !== $this->token();

		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->name ) && preg_match( '/\.zip$/i', $asset->name ) ) {
					if ( $has_token && ! empty( $asset->url ) ) {
						return (string) $asset->url; // API endpoint; resolved in pre_download().
					}
					if ( ! empty( $asset->browser_download_url ) ) {
						return (string) $asset->browser_download_url; // public, direct, no auth.
					}
					return ! empty( $asset->url ) ? (string) $asset->url : '';
				}
			}
		}
		return isset( $release->zipball_url ) ? (string) $release->zipball_url : '';
	}

	/**
	 * Authenticated download for private-repo packages.
	 *
	 * The api.github.com asset/zipball URL 302-redirects to a presigned CDN URL.
	 * We resolve that redirect ourselves WITH the token (auth_headers() adds it),
	 * then let WordPress download the presigned URL WITHOUT any auth header — so the
	 * token is never transmitted to the CDN/S3 host (GitHub explicitly warns against
	 * forwarding it). Returns false for any non-matching package so other plugins
	 * download normally.
	 *
	 * @param mixed  $reply   Default (false) to let WP handle it.
	 * @param string $package Package URL.
	 * @param object $upgrader Upgrader instance.
	 * @return mixed false|string|\WP_Error
	 */
	public function pre_download( $reply, $package, $upgrader ) {
		if ( ! is_string( $package ) || false === strpos( $package, 'api.github.com/repos/' . $this->repo . '/' ) ) {
			return $reply;
		}
		if ( '' === $this->token() ) {
			return $reply;
		}

		// redirection => 0 so we capture the Location instead of following it with the token.
		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 20,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( $code >= 300 && $code < 400 && $location ) {
			// Presigned CDN URL — download with no auth header (avoids token leak).
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return download_url( $location );
		}

		if ( 200 === $code ) {
			// Some setups return the binary directly; stream it to a temp file.
			$body = wp_remote_retrieve_body( $response );
			if ( '' !== $body ) {
				if ( ! function_exists( 'wp_tempnam' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$tmp = wp_tempnam( 'health-endpoint-update.zip' );
				if ( $tmp && false !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					return $tmp;
				}
			}
		}

		return new \WP_Error(
			'health_endpoint_download_failed',
			__( 'Could not download the update package from GitHub.', 'health-endpoint' )
		);
	}

	/**
	 * Add auth/accept headers to GitHub API requests (version check + asset download).
	 *
	 * @param array  $args HTTP args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function auth_headers( $args, $url ) {
		if ( false === strpos( (string) $url, 'api.github.com/repos/' . $this->repo ) ) {
			return $args;
		}

		$token = $this->token();
		if ( '' === $token ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;

		// Downloading a release asset via the API needs the octet-stream Accept.
		if ( false !== strpos( (string) $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Tell WordPress an update is available when the release is newer.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version  = $this->version_from_tag( $release->tag_name );
		$current_version = HEALTH_ENDPOINT_VERSION;

		$item = array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $remote_version,
			'url'         => 'https://github.com/' . $this->repo,
			'package'     => $this->package_url( $release ),
		);

		if ( version_compare( $remote_version, $current_version, '>' ) && '' !== $item['package'] ) {
			$transient->response[ $this->basename ] = (object) $item;
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$item['new_version']                      = $current_version;
			$transient->no_update[ $this->basename ] = (object) $item;
		}

		return $transient;
	}

	/**
	 * Provide the "View details" modal data.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $this->file, false, false );

		$info               = new \stdClass();
		$info->name         = isset( $data['Name'] ) ? $data['Name'] : 'Health Endpoint';
		$info->slug         = $this->slug;
		$info->version      = $this->version_from_tag( $release->tag_name );
		$info->author       = isset( $data['Author'] ) ? $data['Author'] : 'ProjectMakers';
		$info->homepage     = 'https://github.com/' . $this->repo;
		$info->download_link = $this->package_url( $release );
		$info->sections     = array(
			'description' => isset( $data['Description'] ) ? $data['Description'] : '',
			'changelog'   => isset( $release->body ) && '' !== $release->body
				? nl2br( esc_html( $release->body ) )
				: esc_html__( 'See GitHub releases for details.', 'health-endpoint' ),
		);

		if ( ! empty( $release->published_at ) ) {
			$info->last_updated = $release->published_at;
		}

		return $info;
	}

	/**
	 * Drop the cached release when an upgrade finishes.
	 */
	public function flush_cache() {
		delete_transient( $this->transient_key );
	}

	/**
	 * Ensure the extracted release folder is renamed to the plugin slug, even if
	 * the zip's top-level directory differs (e.g. GitHub source zipballs).
	 *
	 * @param string $source        Extracted source dir.
	 * @param string $remote_source Remote source dir.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $args          Hook args.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( ! isset( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		$source  = untrailingslashit( $source );

		if ( $source === untrailingslashit( $desired ) ) {
			return trailingslashit( $source );
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return new \WP_Error(
			'health_endpoint_rename_failed',
			__( 'Could not rename the update folder to the plugin slug.', 'health-endpoint' )
		);
	}
}
