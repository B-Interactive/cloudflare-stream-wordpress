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

You can store the Account ID in Settings, or (preferred for production / env-injected config) define it in `wp-config.php`:

```php
define( 'CLOUDFLARE_STREAM_API_ACCOUNT', 'your-account-id' );
```

When the constant is set it is used instead of the database value, and the settings field becomes read only. Settings shows a one-line status: **Stored in wp-config.php**, **Stored in the database**, or **Not set**.

### API Token

An API token must be created in your Cloudflare dashboard, for this plugin.
See [Cloudflare Docs - Create an API token](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)

The created token should only be used for this plugin. I strongly recommend setting up [Client IP Address Filtering](https://developers.cloudflare.com/fundamentals/api/how-to/restrict-tokens) when creating the token. Where feasible, restrict access to only the IP addresses that need it (eg: your webserver's IP where WordPress is installed).  This will significantly improve the security of your API Token.

Only grant the API Token permissions necesarry for the plugin to work, to again improve the security of this API Token.
  Must have permission for: **Account - Stream:Edit**

#### Recommended: PHP constant (production)

Prefer defining the token in `wp-config.php` (or another file loaded before the plugin) so it is not stored in the database or included in DB backups:

```php
define( 'CLOUDFLARE_STREAM_API_TOKEN', 'your-api-token' );
// Optional, often paired in env config:
define( 'CLOUDFLARE_STREAM_API_ACCOUNT', 'your-account-id' );
```

Constants override any values stored in WordPress options. Each field shows a one-line status, and constant-backed fields are read only. The API token is never shown in the browser.

To migrate an existing install: add the defines above “That’s all, stop editing!”, then open **Settings → Cloudflare Stream**. When a constant is set, the matching database copy is removed on that visit and a one-time notice lists what was removed. Without constants, the token and account ID can still be saved in Settings as before. Leaving the token field blank on save keeps the existing stored token.

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

Both constants are needed together. When they are set and the private key can be read, they are used instead of any key in the database, and the database copy is removed the next time you open **Settings → Cloudflare Stream** (with a one-time notice). If only one is set, or the key cannot be read, Settings says so and signed playback falls back to Cloudflare.

#### Settings UI

On **Settings → Cloudflare Stream** the Signing Key field shows a one-line status, the key ID when there is one, and only the actions that apply:

- **Generate signing key** when no key is set. This creates the key in Cloudflare but saves nothing yet
- On the short-lived setup panel: copy the `wp-config.php` lines, then choose either **I have added this to wp-config.php** (checks the lines work, keeps no database copy) or **Save in the database instead**
- **Show wp-config.php lines** and **Remove signing key** while the key is in the database

#### Recommended generate flow

1. Click **Generate signing key** in **Settings → Cloudflare Stream**.
2. Copy both lines into `wp-config.php`, above “That’s all, stop editing!”.
3. Click **I have added this to wp-config.php**. If they are not working yet, fix them and try again while the panel is still shown.
4. Confirm the status reads **Stored in wp-config.php**.

If you already saved a key in the database, use **Show wp-config.php lines**, add them to `wp-config.php`, then confirm so the database copy is removed.

Saving the PEM in `wp_options` works, but `wp-config.php` keeps it out of database backups. Removing a key here does not revoke it at Cloudflare. The private key is only shown on the admin settings page (`manage_options`), never in front-end or block editor JS.

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
