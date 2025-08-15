<?php
/**
 * Plugin Name:       Live Media Offloader
 * Plugin URI:        https://github.com/microcurse/live-media-offloader
 * Description:       Reference the media library from the live environment.
 * Version:           1.0.4
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
 * Replaces the local site URL with the live site URL in the provided buffer.
 *
 * @param string $buffer The output buffer.
 * @return string The modified buffer.
 */
function lmo_url_replace_callback( $buffer ) {
	$local_url = rtrim( get_site_url(), '/' );
	$live_url  = rtrim( LMO_LIVE_SITE_URL, '/' );

	$local_uploads_url = $local_url . '/wp-content/uploads';
	$live_uploads_url  = $live_url . '/wp-content/uploads';

	$pattern = '~' . preg_quote( $local_uploads_url, '~' ) . '(/[^"\'\s<\)]*)~i';

	$buffer = preg_replace_callback(
		$pattern,
		function ( $matches ) use ( $live_uploads_url ) {
			$relative_with_slash = $matches[1];
			$path_only = $relative_with_slash;
			$qpos = strpos( $path_only, '?' );
			if ( $qpos !== false ) {
				$path_only = substr( $path_only, 0, $qpos );
			}
			$decoded_path_only = rawurldecode( $path_only );
			$local_file = WP_CONTENT_DIR . '/uploads' . $decoded_path_only;
			if ( file_exists( $local_file ) ) {
				return $matches[0];
			}
			return $live_uploads_url . $relative_with_slash;
		},
		$buffer
	);

	return $buffer;
}

/**
 * Starts output buffering to replace media URLs.
 */
function lmo_start_buffering() {
	// Ensure the constant is defined and we are not on the live site.
	if ( defined( 'LMO_LIVE_SITE_URL' ) && LMO_LIVE_SITE_URL ) {
		$local_url = get_site_url();
		$live_url  = LMO_LIVE_SITE_URL;
		if ( $local_url !== $live_url ) {
			ob_start( 'lmo_url_replace_callback' );
		}
	}
}
add_action( 'init', 'lmo_start_buffering' );

/**
 * Flushes the output buffer on shutdown.
 */
function lmo_flush_buffer() {
	if ( ob_get_level() > 0 ) {
		ob_end_flush();
	}
}
add_action( 'shutdown', 'lmo_flush_buffer' );