<?php
/**
 * Plugin Name:       Live Media Offloader
 * Plugin URI:        https://github.com/microcurse/live-media-offloader
 * Description:       Reference the media library from the live environment.
 * Version:           1.0.6
 * Author:            Marc Maninang
 * Author URI:        https://github.com/microcurse/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       live-media-offloader
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Collects every uploads base URL that may appear in HTML (tunnel, DDEV primary,
 * historical imports, etc.) so output buffering can rewrite missing files consistently.
 *
 * @return string[] Unique absolute base URLs (no trailing slash), excluding the live site.
 */
function lmo_get_local_upload_base_urls() {
	$uploads = wp_get_upload_dir();
	$local_base_path = parse_url( $uploads['baseurl'], PHP_URL_PATH );
	if ( empty( $local_base_path ) ) {
		$local_base_path = '/wp-content/uploads';
	}

	$candidates = array( rtrim( $uploads['baseurl'], '/' ) );

	// When using ddev share, WP_SITEURL is the tunnel but post content may still
	// reference DDEV_PRIMARY_URL from older renders or imports.
	$ddev_primary = getenv( 'DDEV_PRIMARY_URL' );
	if ( is_string( $ddev_primary ) && $ddev_primary !== '' ) {
		$candidates[] = rtrim( $ddev_primary, '/' ) . $local_base_path;
	}

	if ( defined( 'LMO_LOCAL_UPLOAD_BASE_URLS' ) && is_array( LMO_LOCAL_UPLOAD_BASE_URLS ) ) {
		foreach ( LMO_LOCAL_UPLOAD_BASE_URLS as $extra ) {
			if ( is_string( $extra ) && $extra !== '' ) {
				$candidates[] = rtrim( $extra, '/' );
			}
		}
	}

	$live_host = '';
	if ( defined( 'LMO_LIVE_SITE_URL' ) && LMO_LIVE_SITE_URL ) {
		$live_host = wp_parse_url( rtrim( LMO_LIVE_SITE_URL, '/' ), PHP_URL_HOST );
	}

	$out = array();
	foreach ( array_unique( $candidates ) as $url ) {
		if ( $live_host && wp_parse_url( $url, PHP_URL_HOST ) === $live_host ) {
			continue;
		}
		$out[] = $url;
	}

	/**
	 * Filter the list of local upload base URLs used when rewriting the page buffer.
	 *
	 * @param string[] $out             Absolute upload base URLs (scheme + host + path), no trailing slash.
	 * @param string   $local_base_path Upload path only, e.g. /wp-content/uploads.
	 */
	return apply_filters( 'lmo_local_upload_base_urls', $out, $local_base_path );
}

/**
 * Replaces the local site URL with the live site URL in the provided buffer.
 *
 * @param string $buffer The output buffer.
 * @return string The modified buffer.
 */
function lmo_url_replace_callback( $buffer ) {
	$uploads = wp_get_upload_dir();
	$local_basedir = rtrim( $uploads['basedir'], '/' );
	$canonical_upload_base = rtrim( $uploads['baseurl'], '/' );
	$local_base_path = parse_url( $uploads['baseurl'], PHP_URL_PATH );
	if ( empty( $local_base_path ) ) {
		$local_base_path = '/wp-content/uploads';
	}

	$live_baseurl = rtrim( LMO_LIVE_SITE_URL, '/' ) . $local_base_path;

	foreach ( lmo_get_local_upload_base_urls() as $local_baseurl ) {
		$pattern = '~' . preg_quote( $local_baseurl, '~' ) . '(/[^"\'\s<\)]*)~i';

		$buffer = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $local_basedir, $live_baseurl, $canonical_upload_base ) {
				$relative_with_slash = $matches[1];
				$path_only = $relative_with_slash;
				$qpos = strpos( $path_only, '?' );
				if ( $qpos !== false ) {
					$path_only = substr( $path_only, 0, $qpos );
				}
				$decoded_path_only = rawurldecode( $path_only );
				$local_file = $local_basedir . $decoded_path_only;
				if ( file_exists( $local_file ) ) {
					// Use this request's uploads host (e.g. tunnel) so DDEV URLs in content work via ddev share.
					return $canonical_upload_base . $relative_with_slash;
				}
				return $live_baseurl . $relative_with_slash;
			},
			$buffer
		);
	}

	return $buffer;
}

/**
 * Starts output buffering to replace media URLs.
 */
function lmo_start_buffering() {
	// Ensure the constant is defined and we are not on the live site.
	if ( defined( 'LMO_LIVE_SITE_URL' ) && LMO_LIVE_SITE_URL ) {
		$local_url = rtrim( get_site_url(), '/' );
		$live_url  = rtrim( LMO_LIVE_SITE_URL, '/' );
		if ( strcasecmp( $local_url, $live_url ) !== 0 ) {
			ob_start( 'lmo_url_replace_callback' );
		}
	}
}
add_action( 'template_redirect', 'lmo_start_buffering' );

/**
 * Flushes the output buffer on shutdown.
 */
function lmo_flush_buffer() {
	if ( ob_get_level() > 0 ) {
		ob_end_flush();
	}
}
add_action( 'shutdown', 'lmo_flush_buffer' );