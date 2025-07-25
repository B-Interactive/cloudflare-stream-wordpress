=== Cloudflare Stream ===
Plugin Name: Cloudflare Stream
Plugin URI: https://github.com/B-Interactive/cloudflare-stream-wordpress
Description: Securely embeds videos hosted with Cloudflare Stream, in your WordPress website via shortcodes or the block editor.
Version: 1.1.1
Author: B-Interactive
Author URI: https://b-interactive.com.au/
Contributors: cloudflare, stevenkword, davidpurdy
Text Domain: cloudflare-stream
License: GPL2
Tags: video, streaming, cloudflare, stream, block
Requires PHP: 7.1
Requires at least: 5.0
Tested up to: 6.8.2
Stable tag: 1.1.0.0

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
5. Add API exchange keys


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

== Screenshots ==

1. Uploading a video
2. Browsing the Cloudflare Stream Library

== Changelog ==
= 1.0.9.5 =
* Added video duration to media modal view (@fului).
* Support poster_time and poster_url null value (@fului).

== Changelog ==
= 1.0.9.4 =
* Added poster time adjustment capability to shortcode.
* Added poster URL option to shortcode.
* Added global thumbnail time to admin settings which acts as default.
* Merged separate method for embed template.
* Bumped package.json dependency versions.
* Added Player Settings section in admin configuration.
* Added class to div wrapping player for easier styling.

= 1.0.9.3 =
* Big update to the Block, making it once again possible to upload and use new content.
* Revise handling of video embed playback parameters.

= 1.0.9.2 =
* Fix handling of video embed playback parameters.
* Conforms to PHP WordPress Coding Standards.
* Deprecated zones related methods.
* Cleaned up admin CSS enqueuing.

= 1.0.9.1 =
* Minor internationalization fixes.
* get_account_id fixed to use zones API.
* Success of get_account_subdomain is now tested.

= 1.0.9 =
* Preferred media domain can now be selected.
* Supports new account specific media domain.
* Now uses accounts API (instead of zones).
* Updates to API handling code.
* Updates to media embed code. Also now references video poster.
* Updates to internationalization.
* Updates to admin settings page.

= 1.0.8 =
* Added build process for blocks.
* Error cleanup.

= 1.0.7 =
* Added setting for signed URL/token duration.
* Removed analytics related features.

= 1.0.6 =
* API Requests now using much more secure API token + zone instead.
* Any existing API key / account email / account ID is deleted from the database when accessing the settings page.

= 1.0.5 =
* Analytics reporting opt-in/out clearer.
* Shortcode method now always uses signed URL's / tokens.
* Added additional shortcode options, controls, autoplay, loop, preload and muted.

= 1.0.3 =
* TUS uploader fix to support large files.

= 1.0.2 =
* Restores Javascript-based uploader client for TUS protocol for users with the administrator role.

= 1.0.1 =
* Security Patch - Removes Javascript-based uploader client for TUS protocol in preparation for PHP-based client.
* Updates Heap Analytics application ID.

= 1.0 =
* First release of Stream Plugin for WordPress
