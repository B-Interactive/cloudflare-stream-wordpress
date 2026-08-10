=== Cloudflare Stream ===
Plugin Name: Cloudflare Stream
Plugin URI: https://github.com/B-Interactive/cloudflare-stream-wordpress
Description: Securely embeds videos hosted with Cloudflare Stream, in your WordPress website via shortcodes or the block editor.
Version: 1.1.7
Author: B-Interactive
Author URI: https://b-interactive.com.au/
Contributors: cloudflare, stevenkword, davidpurdy
Text Domain: cloudflare-stream
License: GPL2
Tags: video, streaming, cloudflare, stream, block
Requires PHP: 7.4
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.1.7

Description: Securely embeds videos hosted with Cloudflare Stream, in your WordPress website via shortcodes or the block editor.

== Description ==

- Block native player
- Multiple playback options
- Distribute videos with unique URLs or embed code
- Per minute pricing
- Adaptive bitrate streaming
- Video storage included
- Workflow integration with webhooks
- REST API support

= Developers =

* This plugin lets you easily add block native videos to your WordPress sites

= Marketers =

* Stream videos natively without ads or recommended videos
* Minimal streaming costs based on engagement and views

= Site Owners =

* Easily add videos to your pages with no technical or video expertise needed

= * Please Note * =

This plugin requires an account on Cloudflare.com to upload and stream videos. Existing Cloudflare Stream users will be able to retrieve videos from their Stream library from the WordPress editor. Currently only users with the "administrator" role can leverage some features.

== Installation ==

1. Signup for a free or paid account on Cloudflare.com
2. Change your DNS settings to Cloudflare
3. Enable Stream from the Cloudflare dashboard
4. Install the Stream for WordPress plugin
5. Add API exchange keys (Settings → Cloudflare Stream), or prefer PHP constants in wp-config.php for production:

define( 'CLOUDFLARE_STREAM_API_TOKEN', 'your-api-token' );
define( 'CLOUDFLARE_STREAM_API_ACCOUNT', 'your-account-id' ); // optional

Constants are used instead of database options, so secrets stay out of DB backups. Each field shows whether it is stored in wp-config.php, stored in the database, or not set. When a constant is set, the matching database copy is removed the next time you open Settings → Cloudflare Stream, with a one-time notice. The API token is never sent to the browser.


== Frequently Asked Questions ==

1) Do I need a Cloudflare account to use this plugin?

Yes. Sign up for a free or paid Cloudflare plan that maps to your site’s domain address. Once your account is activated, you can obtain the API key to enable the plugin.

2) Is there a cost to use this plugin?

Stream charges for storage and views based on minutes. Storage costs $5 per thousand minutes worth of video content. As videos get watched, incremental costs of $1 per thousand minutes viewed apply. These costs are in addition to any other Cloudflare free or paid subscription plan you signed up.

3) My site is already connected to Cloudflare, can I use this?

Yes. If you are already a Stream user you only need to activate the plugin using the API key. If you haven’t enabled Stream yet, login to your Cloudflare account and enable the feature to receive an API activation key for the plugin.

4) Can I use this plugin to deliver live streams?

No. Stream only supports on-demand video streaming.

5) How is this different from using an embedded YouTube player link?

Stream lets you own and control the video viewing experience and is ideal for videos that require a paid subscription.

6) Can I use full-page caching with signed URLs?

Signed tokens are written into the page HTML when a Stream video is rendered. If a full-page cache stores that HTML longer than the token lifetime, visitors may share one token or get an expired player. When signed URLs are enabled, the plugin marks those front-end responses so common WordPress page caches (such as WP Super Cache and LiteSpeed Cache) do not store them, and it sends no-cache headers. Keep any external full-page cache TTL no longer than your signed URL expiration for pages that embed Stream videos.

7) What is a signing key vs the /token API?

A Stream signing key lets the plugin build RS256 playback JWTs on the server (OpenSSL) with no per-render call to POST .../stream/{uid}/token. Preferred setup: Generate signing key under Settings → Cloudflare Stream, copy the short-lived wp-config.php lines (both defines), then choose "I have added this to wp-config.php". That checks the lines work and keeps no database copy. You can instead save the key in the database. If a key is already in the database, use Show wp-config.php lines and confirm after adding them. Both constants are needed together; when they work, the database copy is removed on the next settings page visit. The PEM is only shown on the admin settings page and is never sent to the browser. If no signing key is configured, signed playback still uses the Cloudflare /token API with a short cache.

8) Does Use Signed URLs change videos in Cloudflare?

For new uploads only: when Use Signed URLs is enabled, the plugin sets requireSignedURLs and allowedOrigins (site host from the WordPress home URL) on direct upload create. Existing videos are not bulk-updated; change those in the Cloudflare dashboard if needed. When the setting is off, new uploads are not forced private.

9) Can I set the API token outside the database?

Yes. Prefer CLOUDFLARE_STREAM_API_TOKEN (and optionally CLOUDFLARE_STREAM_API_ACCOUNT) in wp-config.php above "That's all, stop editing!". Constants are used instead of Settings options and keep the token out of database backups. Settings shows which one is in use. The matching database copy is removed when you open Settings → Cloudflare Stream with the constants set. Without constants, options still work; leave the token field blank on save to keep a stored token.

== Screenshots ==

1. Uploading a video
2. Browsing the Cloudflare Stream Library

See Releases for release notes:
https://github.com/B-Interactive/cloudflare-stream-wordpress/releases