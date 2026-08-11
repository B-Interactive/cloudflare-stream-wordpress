# Cloudflare Stream for WordPress

![latest-release](https://badgen.net//github/release/B-Interactive/cloudflare-stream-wordpress)
![license](https://badgen.net//github/license/B-Interactive/cloudflare-stream-wordpress)
![blocks-build](https://github.com/B-Interactive/cloudflare-stream-wordpress/actions/workflows/build.yml/badge.svg)

Upload, browse and embed [Cloudflare Stream](https://developers.cloudflare.com/stream/) videos in WordPress. This is a fork of the official Cloudflare Stream plugin 1.0.5 ([WordPress](https://wordpress.org/plugins/cloudflare-stream/) / [GitHub](https://github.com/cloudflare/stream-wordpress)), rebuilt to use a limited-scope API Token instead of the global API key, and to support optional signed (token-protected) playback.

## Features

- Block editor block to upload new videos, or browse and select videos already in your Stream library.
- Shortcode for embedding any video by ID, with extra playback options.
- Optional Signed URLs, so playback requires a short-lived token whose lifetime you control.
- Signing keys can be generated from the plugin's own settings page and tokens minted locally (RS256 JWT), or you can fall back to Cloudflare's token endpoint.
- Credentials and signing keys can be defined as PHP constants, so they never touch the database.
- New uploads can automatically be locked to signed playback and to your own site's host.
- Choose your preferred Cloudflare media domain, and set an automatic thumbnail time site-wide or per video.

## Installation

1. Download the plugin ZIP from the [latest release](https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Click **Activate**.
4. Configure the plugin at **Settings → Cloudflare Stream** in the WordPress admin.

The block and library browser only load once an Account ID and API Token are saved.

## Configuration

### API credentials

Both of these come from the Cloudflare dashboard at [dash.cloudflare.com](https://dash.cloudflare.com/), not from WordPress:

- **Account ID** — open your domain's **Overview** page and copy the Account ID from the API section on the right.
- **API Token** — create a token for this plugin only, following [Cloudflare's create-token guide](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/). It needs one permission: **Account → Stream:Edit**. Also apply [Client IP Address Filtering](https://developers.cloudflare.com/fundamentals/api/how-to/restrict-tokens), restricted to your web server's IP where practical.

Back in WordPress, both values can be saved at **Settings → Cloudflare Stream**, or defined in `wp-config.php` (preferred in production, as they stay out of database backups):

```php
define( 'CLOUDFLARE_STREAM_API_ACCOUNT', 'your-account-id' );
define( 'CLOUDFLARE_STREAM_API_TOKEN', 'your-api-token' );
```

Constants always override database values, and those fields become read only. Each field shows its status: **Stored in wp-config.php**, **Stored in the database**, or **Not set**. When you open the plugin settings with a constant set, the matching database copy is removed and a one-time notice tells you what was cleared. Any API key and account email left over from the official plugin are also deleted at that point. The API Token is never sent to the browser, and leaving its field blank on save keeps the stored value.

### Signed URLs

Three terms get used loosely and are worth separating:

- **API Token** — the long-lived server-side secret this plugin uses to manage your Stream account. It never appears on the front end.
- **Playback token** — a short-lived signed credential (a JWT) that authorises playing one specific video until it expires.
- **Signed URL** — the player URL with a playback token substituted in place of the plain video ID, so `https://iframe.cloudflarestream.com/VIDEO_ID` becomes `https://iframe.cloudflarestream.com/<playback-token>`. The signed URL is not a separate credential; it is simply how the playback token is delivered.

Plugin settings, at **Settings → Cloudflare Stream** in the WordPress admin:

- **Use Signed URLs** (default: on) — render embeds as signed URLs rather than plain video IDs.
- **Signed URL Expiration** (default: 60 minutes, range 1–1440) — how long each playback token stays valid, and therefore how long its signed URL works. Keep this longer than your longest video.

Playback tokens are minted locally with OpenSSL when a signing key is configured. Without a key, the plugin asks Cloudflare's `/token` endpoint for them instead. If a key is configured but local signing fails, the plugin falls back to that same Cloudflare `/token` API so playback stays signed, and surfaces the issue in Settings, an admin notice, Site Health, and an editor-only HTML comment. If both local signing and the API fail, embeds are left empty (unsigned playback is never used while Use Signed URLs is on).

To set up a key, click **Generate signing key** on the plugin settings page (the plugin creates it at Cloudflare for you), copy the two lines it shows into `wp-config.php` above "That's all, stop editing!", then click **I have added this to wp-config.php** so the plugin verifies them and keeps no database copy:

```php
define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'your-key-id' );
define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n" );
```

Both constants are required together; setting only one leaves signing on the Cloudflare fallback. You can choose **Save in the database instead**. If the key is already stored in the database, use **Move key to wp-config.php**: a new key is shown once, you confirm the constants, the database copy is removed, and the previous key is revoked in Cloudflare. **Remove signing key** clears the stored key and revokes it in Cloudflare when possible. The private key material is only shown once during setup or move, and is never exposed to the front end or the editor.

Enabling these settings is only half the job — see [Securing video access](#securing-video-access) for the steps that actually restrict playback.

### Media domain and thumbnails

- **Preferred Media Domain** (default: `cloudflarestream.com`) — also offers `videodelivery.net`, plus your account-specific subdomain once at least one video exists in your Stream account.
- **Thumbnail Time** (default: 0 seconds) — the point each auto-generated poster image is taken from. Override per video with the `postertime` shortcode attribute.

## Securing video access

Locking down playback is the main reason this fork exists, and it takes two halves working together: WordPress must issue signed URLs, and Cloudflare must refuse to play the video without one. Turning on **Use Signed URLs** by itself is not enough — a video that does not require signed URLs at Cloudflare will still play from its bare video ID.

That means you will be working in two separate places, and the steps below say which one each time:

- **The plugin settings**, in your own WordPress admin area, at **Settings → Cloudflare Stream**.
- **The Cloudflare dashboard**, at [dash.cloudflare.com](https://dash.cloudflare.com/), under **Stream → Videos**. Nothing here is part of WordPress.

### In WordPress (plugin settings)

1. **Turn on Use Signed URLs** at **Settings → Cloudflare Stream** in your WordPress admin (it is on by default). Every embed the plugin renders then uses a signed URL carrying a fresh playback token, and each **new** upload made through the plugin is created at Cloudflare with `requireSignedURLs` enabled and `allowedOrigins` set to your site's host.

    ![The plugin's Use Signed URLs setting in the WordPress admin](https://user-images.githubusercontent.com/16984998/166195570-6e2ecfd4-72af-4f11-a52c-f615df470a36.png)

    With the setting off, new uploads are left public — no `requireSignedURLs`, no `allowedOrigins`.

2. **Set Signed URL Expiration** to suit your content. The default is 60 minutes; keep it comfortably longer than your longest video, or playback can expire mid-view. Shorter lifetimes mean a leaked URL is useful for less time.

3. **Optionally configure a signing key** so playback tokens are minted on your own server with OpenSSL, instead of calling Cloudflare for each one. See [Signed URLs](#signed-urls) above. The private key stays server-side; only the short-lived playback token ever reaches the browser.

### In the Cloudflare dashboard

4. **Protect your existing videos.** The plugin does not update your library in bulk, so anything uploaded before you enabled "Use Signed URLs", or uploaded outside WordPress, is still public. Log in to [dash.cloudflare.com](https://dash.cloudflare.com/), go to **Stream → Videos**, open each video's settings and switch on **Require Signed URLs** there. This is a Cloudflare-side setting, and there is no equivalent control in the plugin.

    ![The Require Signed URLs toggle on a video in the Cloudflare dashboard](https://user-images.githubusercontent.com/16984998/166195689-f52c48c6-86f4-40c5-8e96-b9f6ae5790d0.png)

5. **Check each video's allowed origins.** Uploads made through the plugin are restricted to the host from your WordPress home URL only, which means `example.com` does not also cover `www.example.com`, and staging or CDN hostnames are not included. Add any additional hosts on the same Cloudflare video settings screen, and keep the list as tight as you can — it stops a valid playback token being replayed on someone else's page.

    ![The Allowed Origins field on a video in the Cloudflare dashboard](https://user-images.githubusercontent.com/16984998/166195828-80c23260-fc02-47bb-89b1-ceb8a4217638.png)

### On your server

6. **Mind your page caches.** The signed URL, playback token and all, is written into the page HTML, so a full-page cache that outlives the token will hand every visitor the same URL, or an expired player once it lapses. The plugin marks front-end responses containing a Stream embed as uncacheable for common WordPress caches and sends no-cache headers, but you must still keep any external HTML cache TTL (for example Cloudflare's own HTML caching) no longer than your expiration setting.

## Adding videos

### Block

Insert the **Cloudflare Stream Video** block (`cloudflare-stream/block-video`), then either drop a video file onto the placeholder, choose **Upload**, or open **Stream Library** to browse and select a video already in your account. Uploads go straight to Cloudflare in resumable chunks, with a progress bar while transferring and again while Cloudflare processes the video; **Cancel** stops an upload, and **Retry** reappears if one fails. No WordPress media attachment is created. Once a video is ready, the block toolbar's **Replace video** button swaps it for another.

The block inspector offers **Autoplay**, **Loop**, **Muted** and **Playback Controls**, and the block supports the standard alignment controls.

The block renders dynamically on the front end, so it uses the same signed embed path as the shortcode. There is no block UI for `preload`, `postertime` or `posterurl` — use the shortcode when you need those. Blocks saved by older versions (static iframe or the old `<stream>` tag) keep working in a deprecated form, but will not get signed playback until the block is updated or converted.

Content editors (`edit_posts`) can open the Stream library, select a video, and preview playback. Uploading, renaming, deleting, and plugin settings require the `manage_options` capability.

### Shortcode

```text
[cloudflare_stream uid="VIDEO_ID"]
```

| Attribute | Default | Notes |
|---|---|---|
| `uid` | required | Cloudflare Stream video ID |
| `controls` | `true` | `true` or `false` |
| `autoplay` | `false` | `true` or `false` |
| `loop` | `false` | `true` or `false` |
| `preload` | `false` | `true` or `false` |
| `muted` | `false` | `true` or `false` |
| `postertime` | site default (0) | seconds, e.g. `60` |
| `posterurl` | empty | image URL; overrides `postertime` |

Example:

```text
[cloudflare_stream uid="VIDEO_ID" autoplay="false" muted="true" postertime="60"]
```

## Developers

The block editor interface is built with WordPress block editor components; `tus-js-client` is the only production dependency. Node 22 or newer is required.

Clone the repository, then from the project directory:

```bash
npm install
npm run build:dev   # development build, easier browser debugging
npm run build       # production build
npm run package     # production build plus plugin ZIP
```

Checks:

```bash
npm run lint:css     # stylelint
npm run test:compat  # PHPCompatibility and PHPCS
npm run test:php     # PHPUnit via wp-env, skipped when Docker is unavailable
```

## Licence

Copyright (C) 2020 Cloudflare. Released under the GNU General Public Licence, version 2 or later. See [LICENSE](LICENSE) or [gnu.org](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

## Acknowledgements

Thanks to Cloudflare and WP Engine for the original plugin this was forked from.
