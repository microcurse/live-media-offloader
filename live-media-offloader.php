<?php
/**
 * Plugin Name:       Live Media Offloader
 * Plugin URI:        https://github.com/microcurse/live-media-offloader
 * Description:       Reference the media library from the live environment.
 * Version:           1.0.7
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
 * Build a local proxy URL for a live uploads asset.
 *
 * Some live environments block hotlinking (403) when the Referer is a tunnel host.
 * Proxying through the local WP instance avoids that by fetching server-side.
 *
 * @param string $uploads_relative_with_query Starts with "/{yyyy}/{mm}/file.ext" (may include query string).
 * @return string Absolute URL to this site that will stream the asset.
 */
function lmo_proxy_url( $uploads_relative_with_query ) {
	$uploads_relative_with_query = (string) $uploads_relative_with_query;
	if ( $uploads_relative_with_query === '' || $uploads_relative_with_query[0] !== '/' ) {
		$uploads_relative_with_query = '/' . ltrim( $uploads_relative_with_query, '/' );
	}

	$path = '/wp-content/uploads' . $uploads_relative_with_query;

	return add_query_arg(
		[
			'lmo_media' => '1',
			'lmo_path'  => rawurlencode( $path ),
		],
		home_url( '/' )
	);
}

/**
 * Stream a live uploads file through this local site (media proxy).
 */
function lmo_maybe_proxy_request() {
	if ( empty( $_GET['lmo_media'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! defined( 'LMO_LIVE_SITE_URL' ) || ! LMO_LIVE_SITE_URL ) {
		status_header( 404 );
		exit;
	}

	$raw = isset( $_GET['lmo_path'] ) ? (string) $_GET['lmo_path'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw = rawurldecode( $raw );

	if ( ! is_string( $raw ) || $raw === '' || ! str_starts_with( $raw, '/wp-content/uploads/' ) ) {
		status_header( 400 );
		exit;
	}

	$live_url = rtrim( (string) LMO_LIVE_SITE_URL, '/' ) . $raw;
	if ( function_exists( 'set_url_scheme' ) ) {
		$live_url = set_url_scheme( $live_url );
	}

	$response = wp_safe_remote_get(
		$live_url,
		[
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => [
				'User-Agent' => 'WordPress/LiveMediaOffloader',
				// Some WAFs allow if Referer matches live origin.
				'Referer'    => rtrim( (string) LMO_LIVE_SITE_URL, '/' ) . '/',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		status_header( 502 );
		exit;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		status_header( $code ?: 502 );
		exit;
	}

	$body = (string) wp_remote_retrieve_body( $response );
	if ( $body === '' ) {
		status_header( 404 );
		exit;
	}

	$headers      = wp_remote_retrieve_headers( $response );
	$content_type = '';
	if ( is_array( $headers ) && ! empty( $headers['content-type'] ) ) {
		$content_type = (string) $headers['content-type'];
	} elseif ( is_object( $headers ) && isset( $headers['content-type'] ) ) {
		$content_type = (string) $headers['content-type'];
	}

	if ( $content_type ) {
		header( 'Content-Type: ' . $content_type );
	} else {
		header( 'Content-Type: application/octet-stream' );
	}
	header( 'Cache-Control: public, max-age=300' );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}
add_action( 'template_redirect', 'lmo_maybe_proxy_request', 0 );

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
	if ( function_exists( 'set_url_scheme' ) ) {
		$canonical_upload_base = set_url_scheme( $canonical_upload_base );
	}
	$local_base_path = parse_url( $uploads['baseurl'], PHP_URL_PATH );
	if ( empty( $local_base_path ) ) {
		$local_base_path = '/wp-content/uploads';
	}

	$live_baseurl = rtrim( LMO_LIVE_SITE_URL, '/' ) . $local_base_path;
	if ( function_exists( 'set_url_scheme' ) ) {
		$live_baseurl = set_url_scheme( $live_baseurl );
	}

	$resolve_upload_url = static function ( $relative_with_slash ) use ( $local_basedir, $live_baseurl, $canonical_upload_base ) {
		$path_only = $relative_with_slash;
		$qpos      = strpos( $path_only, '?' );
		if ( $qpos !== false ) {
			$path_only = substr( $path_only, 0, $qpos );
		}
		$decoded_path_only = rawurldecode( $path_only );
		$local_file        = $local_basedir . $decoded_path_only;
		if ( file_exists( $local_file ) ) {
			// Use this request's uploads host (e.g. tunnel) so DDEV URLs in content work via ddev share.
			return $canonical_upload_base . $relative_with_slash;
		}
		// Prefer proxying through local WP to avoid live hotlink/WAF 403s.
		return lmo_proxy_url( $relative_with_slash );
	};

	foreach ( lmo_get_local_upload_base_urls() as $local_baseurl ) {
		$pattern = '~' . preg_quote( $local_baseurl, '~' ) . '(/[^"\'\s<\)]*)~i';

		$buffer = preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $resolve_upload_url ) {
				return $resolve_upload_url( $matches[1] );
			},
			$buffer
		);
	}

	// Also rewrite relative uploads paths (common in some themes/builders), including url(...) CSS usage.
	$rel_pattern = '~(["\'])(' . preg_quote( $local_base_path, '~' ) . '/[^"\'\s<\)]*)~i';
	$buffer      = preg_replace_callback(
		$rel_pattern,
		static function ( $m ) use ( $resolve_upload_url ) {
			$quote = $m[1];
			$path  = $m[2];
			// $path begins with /wp-content/uploads...; convert it to "/{yyyy}/{mm}/file.ext?..." for the resolver.
			$local_base_path = '/wp-content/uploads';
			$uploads_relative_with_query = substr( $path, strlen( $local_base_path ) );
			if ( $uploads_relative_with_query === false ) {
				return $m[0];
			}
			$resolved = $resolve_upload_url( $uploads_relative_with_query );
			// Do NOT append a quote here: the match doesn't include the closing quote; it remains in the buffer.
			return $quote . $resolved;
		},
		$buffer
	);

	$css_pattern = '~(url\(\s*)(["\']?)(' . preg_quote( $local_base_path, '~' ) . '/[^"\'\)\s]+)(\2\s*\))~i';
	$buffer      = preg_replace_callback(
		$css_pattern,
		static function ( $m ) use ( $resolve_upload_url ) {
			$prefix = $m[1];
			$q      = $m[2];
			$path   = $m[3];
			$suffix = $m[4];
			$local_base_path = '/wp-content/uploads';
			$uploads_relative_with_query = substr( $path, strlen( $local_base_path ) );
			if ( $uploads_relative_with_query === false ) {
				return $m[0];
			}
			$resolved = $resolve_upload_url( $uploads_relative_with_query );
			// $suffix already includes the closing quote (if any) and ')'.
			return $prefix . $q . $resolved . $suffix;
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