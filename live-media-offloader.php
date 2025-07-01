<?php
/**
 * Plugin Name:       Live Media Offloader
 * Plugin URI:        https://github.com/microcurse/live-media-offloader
 * Description:       Reference the media library from the live environment.
 * Version:           1.0.0
 * Author:            Marc Maninang
 * Author URI:        https://github.com/microcurse/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       live-media-offloader
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

function lmo_filter_media_url( $url, $post_id ) {
    if ( defined( 'LMO_LIVE_SITE_URL' ) && LMO_LIVE_SITE_URL ) {
        $live_url = LMO_LIVE_SITE_URL;
        $local_url = get_site_url();

        // Ensure we don't replace the URL on the live site itself
        if ( $local_url !== $live_url ) {
            return str_replace( $local_url, $live_url, $url );
        }
    }
    return $url;
}
add_filter( 'wp_get_attachment_url', 'lmo_filter_media_url', 10, 2 );