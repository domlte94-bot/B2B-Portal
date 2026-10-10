<?php
/**
 * One-click updates from the plugin's GitHub releases.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells WordPress about newer versions published as GitHub releases.
 * A release counts when its tag is a version number (0.3.1 or v0.3.1) and it has
 * the plugin zip attached (b2b-contract-portal-*.zip, with the plugin folder inside).
 * The plugin header's "Update URI" routes WordPress's update check here.
 */
class B2BCP_Updater {

	const REPO      = 'domlte94-bot/B2B-Portal';
	const TRANSIENT = 'b2bcp_latest_release';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		// "Check again" on the Updates screen clears WordPress's cache; clear ours with it.
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Answer WordPress's update check for this plugin.
	 *
	 * @param array|false $update      Update data from another filter.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file relative to the plugins folder.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( B2BCP_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'slug'    => dirname( $plugin_file ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * "View details" window for this plugin.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Request.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( B2BCP_FILE ) ) !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'B2B Contract Portal for WooCommerce',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => 'Muvves Studio',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Drop the cached release.
	 */
	public static function forget() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Latest published release that carries the plugin zip, cached for an hour.
	 *
	 * @return array|null version, url, package, notes.
	 */
	private static function latest_release() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$release  = array();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$version = is_array( $data ) ? ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' ) : '';
			if ( preg_match( '/^\d+(\.\d+)+$/', $version ) ) {
				foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
					$name = (string) ( $asset['name'] ?? '' );
					if ( 0 === strpos( $name, 'b2b-contract-portal' ) && '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
						$release = array(
							'version' => $version,
							'url'     => esc_url_raw( (string) ( $data['html_url'] ?? '' ) ),
							'package' => esc_url_raw( (string) $asset['browser_download_url'] ),
							'notes'   => (string) ( $data['body'] ?? '' ),
						);
						break;
					}
				}
			}
		}

		// An empty array remembers "nothing usable" so a failed lookup is not repeated on every page.
		set_site_transient( self::TRANSIENT, $release, HOUR_IN_SECONDS );
		return $release ? $release : null;
	}
}
