<?php
/**
 * Self-updater: lets WordPress see and install new versions published as GitHub
 * Releases from the public ProjectMakers repository.
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
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
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
	 * @param object $release Release object.
	 * @return string
	 */
	private function package_url( $release ) {
		if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( isset( $asset->name ) && preg_match( '/\.zip$/i', $asset->name ) ) {
					if ( ! empty( $asset->browser_download_url ) ) {
						return (string) $asset->browser_download_url; // public, direct, no auth.
					}
				}
			}
		}
		return isset( $release->zipball_url ) ? (string) $release->zipball_url : '';
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
		$info->name         = isset( $data['Name'] ) ? $data['Name'] : 'ProjectMakers Health Endpoint';
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
