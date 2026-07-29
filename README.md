# Cloudflare Stream for WordPress

![latest-release](https://badgen.net//github/release/B-Interactive/cloudflare-stream-wordpress)
![license](https://badgen.net//github/license/B-Interactive/cloudflare-stream-wordpress)

![blocks-build](https://github.com/B-Interactive/cloudflare-stream-wordpress/actions/workflows/node.js.yml/badge.svg)

A fork from the official Cloudflare Stream plugin 1.0.5 for WordPress. This fork looks to achieve these key features:

- Rebuild and upgrade the Block that is currently broken in official plugin.
- Take full advantage of Cloudflare Stream's security features.
- Uses signed URL's / tokens, so video access can be strictly controlled and limited.
- Uses a limited access API token for API access, eliminating the use of the global API key which presents security risk.
- Incorporate additional features and new features as they're made available.

The Block method of adding videos is currently limited to upload only. Browsing and selecting content from your Cloudflare Stream Library is not yet fixed. Legacy Block content is supported in a deprecated form, but will not take advantage of new features such as signed URLs.
For now, using the shortcode method is still the most appropriate way to insert content already in your Stream Library. [See Shortcode section below](#shortcode).

- Original plugin [on WordPress](https://wordpress.org/plugins/cloudflare-stream/).
- Original plugin [on GitHub](https://github.com/cloudflare/stream-wordpress).

## Changes from Official

- Optionally use signed URL's / tokens.
- Removed deprecated analytics.
- Added additional shortcode options: controls, autoplay, loop, preload and muted.
- Uses API Token based API access, for MUCH more secure Cloudflare account access.
- Any existing API Key and API account email stored in the database are deleted when the settings page is accessed.
- If updating from version older than 1.0.6, you'll need to enter your Cloudflare API Token and Cloudflare Account ID in the configuration page.
- Added admin setting for signed URL/token duration (default is otherwise 1 hour).
- Added admin toggle for whether or not to use signed URLs/tokens.
- When Use Signed URLs is on, new direct uploads set `requireSignedURLs` and `allowedOrigins` (WordPress home URL host). Existing videos are not bulk-updated.
- Can select Cloudflare media domain, including new account specific sub-domain.
- Can set poster/thumbnail location globally, and per-video.
- Can specify a poster/thumbnail URL per-video.

## Installation

- Download the full plugin ZIP file from the [latest release of this plugin](https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/latest)
- In the WordPress admin panel, go to Plugins > Add New > Upload Plugin and upload the ZIP file
- Click the "Activate" button
- In the WordPress admin panel, visit the Plugins section Activate the Cloudflare Stream plugin.

## Admin Settings

The admin area has been completely revised from the official plugin. Instead of using the all-controlling global API key, this now makes use of a much more secure API token, which only permits the plugin limited access to a Cloudflare account. When the admin settings are accessed, any existing API key and email stored in the database, are deleted from the database as these are no longer needed and their presence is a security risks.

![admin-settings](https://github.com/B-Interactive/cloudflare-stream-wordpress/assets/16984998/8b41a360-23d0-4230-99f0-7754ffc93c0f)

### API Account ID

- **Cloudflare** > [domain] > **Overview** > [scroll down to API section on the right and copy the Account ID].

### API Token

An API token must be created in your Cloudflare dashboard, for this plugin.
See [Cloudflare Docs - Create an API token](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)

The created token should only be used for this plugin. I strongly recommend setting up [Client IP Address Filtering](https://developers.cloudflare.com/fundamentals/api/how-to/restrict-tokens) when creating the token. Where feasible, restrict access to only the IP addresses that need it (eg: your webserver's IP where WordPress is installed).  This will significantly improve the security of your API Token.

Only grant the API Token permissions necesarry for the plugin to work, to again improve the security of this API Token.
  Must have permission for: **Account - Stream:Edit**

### Use Signed URLs

When this is checked [x], videos are accessed using a temporary time-limited token, aka signed URL. This alone does not secure your content however. Please see **[Securing Video Access](#securing-video-access)** below for further details on how to do that.

Signed tokens are written into the page HTML when the video is rendered. If a full-page cache (for example WP Super Cache, LiteSpeed Cache, or Cloudflare HTML cache) stores that page for longer than the token lifetime, visitors can share one token or hit an expired player. With signed URLs on, this plugin marks front-end responses that include a Stream embed so common WordPress page caches skip storing them, and it sends no-cache headers on those responses. Still keep any external full-page cache TTL no longer than your signed URL expiration for pages that embed Stream videos.

### Signed URL Expiration

When **Use Signed URLs** is checked [x], this setting controls how long any particular token / signed ULR is valid for **in minutes**. The Cloudflare default, is 60 minutes. Generally, you'd want to make sure this is larger than your longest video.

### Signing Key

Signed playback can use either:

1. **Local RS256 JWT (preferred)** when a Stream signing key is configured. Tokens are built on the server with OpenSSL. No per-render call to Cloudflare `POST .../stream/{uid}/token`.
2. **Cloudflare `/token` API (fallback)** when no signing key is configured. The plugin still short-caches those tokens. Existing sites keep working without a key.

The private key never goes to the browser. Only the short-lived playback token is written into the embed HTML.

#### Recommended: PHP constants (production)

In `wp-config.php` (or another file loaded before the plugin):

```php
define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'your-key-id' );
// Decoded PEM text, or the base64 value Cloudflare returns once when the key is created:
define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n" );
```

Constants override any key stored in the database.

#### Settings UI

On **Settings → Cloudflare Stream** you can:

- See whether a signing key is active (**Key on file**), from constants or options
- **Generate signing key** via the Cloudflare API (does **not** write to the database yet)
- On the short-lived setup panel: copy a ready-to-paste `wp-config.php` snippet, then choose either:
  1. **I have pasted this into wp-config.php** (preferred) — checks constants work, deletes any DB copy, discards the temporary key
  2. **Store in the WordPress database** (less secure) — saves id + PEM to options
- **Show wp-config snippet** while the key is only in options, then confirm you moved it to constants (removes the DB copy) or keep it in the database
- **Remove stored signing key** from WordPress options even when constants are active (clears leftover DB copies; does not revoke the key at Cloudflare)

#### Recommended generate flow

1. Click **Generate signing key** in **Settings → Cloudflare Stream**.
2. Copy the one-time `wp-config.php` snippet (both defines) into `wp-config.php`.
3. Click **I have pasted this into wp-config.php**. If constants are not detected yet, add them and try again while the panel is still available.
4. Confirm the settings page shows the key **from PHP constants**. No database copy is kept when you confirm constants.

If you already stored a key in options, use **Show wp-config snippet**, paste into `wp-config.php`, then **I moved it to wp-config.php** so the DB copy is removed.

Storing PEM in `wp_options` matches how the API token is stored today; constants are the better path for production secrets. Confirming constants does not keep a DB copy. Leftover DB keys can still be removed while constants are set. The private key is only shown on the admin settings page (`manage_options`), never in front-end or block editor JS.
### Preferred Media Domain

This option allows you to select from a small list of different Cloudflare media domains. This domain is used when delivering content to your users. The 3rd option is a unique subdomain specific to your Cloudflare account. This option will only be presented if you have at least one video already uploaded to your Cloudflare Stream account.

### Thumbnail Time

Thumbnails for videos will be auto-generated, taken from a location (in seconds) within each video.  By default, this is the first frame of the video (0 seconds), but you can change the site-wide default here.  This settings can be overridden on a per-video basis, by specifying the `postertime` shortcode attribute (see [Shortcode](#shortcode) below).


## Securing Video Access

1. Make sure **Use Signed URLs** is checked [x], in the admin settings. With this on, **new uploads** from the plugin set Cloudflare `requireSignedURLs` and `allowedOrigins` (the site host from the WordPress home URL) at direct-upload creation time. Existing library videos are not bulk-updated; change those in the Cloudflare Stream dashboard if needed. When the setting is off, new uploads are left public (no automated `requireSignedURLs` / `allowedOrigins`).
    
    ![use-signed-urls](https://user-images.githubusercontent.com/16984998/166195570-6e2ecfd4-72af-4f11-a52c-f615df470a36.png)
    
2. Optionally configure a **Signing Key** so tokens are minted locally (see above). Without a key, the plugin falls back to Cloudflare’s `/token` endpoint.
3. For videos uploaded before this automation (or outside the plugin), set **Require Signed URLs** per video in the Cloudflare Stream dashboard so the raw video ID cannot be played without a token.
    
    ![require-signed-url](https://user-images.githubusercontent.com/16984998/166195689-f52c48c6-86f4-40c5-8e96-b9f6ae5790d0.png)
    
4. **Allowed Origins** for new plugin uploads defaults to the home URL host only (e.g. `example.com` does not cover `www.example.com`). Adjust per video in the dashboard if you need more hosts.
    
    ![allowed-origins](https://user-images.githubusercontent.com/16984998/166195828-80c23260-fc02-47bb-89b1-ceb8a4217638.png)
    

## Shortcode

`[cloudflare_stream uid="`_some video id_`"]`

Replace _some video id_ with an actual Cloudflare Stream video ID.

These are optional shortcode flags (with defaults shown here):

- controls="true" | Expects: `true` or `false`
- autoplay="false" | Expects: `true` or `false`
- loop="false" | Expects: `true` or `false`
- preload="false" | Expects: `true` or `false`
- muted="false" | Expects: `true` or `false`
- postertime="" | Expects a number (eg: "60") representing seconds. 
- posterurl="" | Expects a URL (eg: "https://example.com/image.jpg") pointing to an image.

They can be used in this way:

`[cloudflare_stream uid="`_some video id_`" controls="true" autoplay="false" loop="false" preload="false" muted="false" postertime="60"]`

Optionally, `posterurl` is used to point to a URL of an image that will be used as a poster.  This will override `postertime`.

## Developers

Clone this repo, cd into the `cloudflare-stream-wordpress` directory and run

```bash
npm install
```

Build for development (uses development mode for Webpack, making browser debugging easier):

```bash
npm run build:dev
```

Build for production:

```bash
npm run build
```

Package plugin for WordPress:

```bash
npm run package
```

## Acknowledgements

- Cloudflare and WP Engine for developing the original plugin this was forked from.

## License

Copyright (C) 2020 Cloudflare

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

[https://www.gnu.org/licenses/old-licenses/gpl-2.0.html](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
