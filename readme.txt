=== Live Media Offloader ===
Contributors: kilocode, Marc Maninang
Tags: media, offload, cdn, development
Requires at least: 5.0
Tested up to: 6.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple plugin to reference the media library from the live environment, avoiding the need to store media files locally.

== Description ==

This plugin allows you to define a live site URL in your `wp-config.php` file. When active on a local or staging environment, it will rewrite media URLs to point to your live site. This is useful for local development when you don't want to download the entire uploads folder.

== Installation ==

1. Upload the `live-media-offloader` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Add `define( 'LMO_LIVE_SITE_URL', 'https://your-live-site.com' );` to your `wp-config.php` file on your local/staging environment.

== Changelog ==

= 1.0.0 =
* Initial release.