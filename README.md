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

### Signed URL Expiration

When **Use Signed URLs** is checked [x], this setting controls how long any particular token / signed ULR is valid for **in minutes**. The Cloudflare default, is 60 minutes. Generally, you'd want to make sure this is larger than your longest video.

### Preferred Media Domain

This option allows you to select from a small list of different Cloudflare media domains. This domain is used when delivering content to your users. The 3rd option is a unique subdomain specific to your Cloudflare account. This option will only be presented if you have at least one video already uploaded to your Cloudflare Stream account.

### Thumbnail Time

Thumbnails for videos will be auto-generated, taken from a location (in seconds) within each video.  By default, this is the first frame of the video (0 seconds), but you can change the site-wide default here.  This settings can be overridden on a per-video basis, by specifying the `postertime` shortcode attribute (see [Shortcode](#shortcode) below).


## Securing Video Access

1. Make sure **Use Signed URLs** is checked [x], in the admin settings. **This feature alone does not secure your videos.** The original ID of your videos is still accessible and could be used to access them.
    
    ![use-signed-urls](https://user-images.githubusercontent.com/16984998/166195570-6e2ecfd4-72af-4f11-a52c-f615df470a36.png)
    
2. Your videos **must** be set to **Require Signed URLs** on a per-video basis, in your Cloudflare Stream dashboard. This will make the original video ID worthless to would-be thieves, because a signed URL/token can only be created in conjunction with your (secure) API key or API token.
    
    ![require-signed-url](https://user-images.githubusercontent.com/16984998/166195689-f52c48c6-86f4-40c5-8e96-b9f6ae5790d0.png)
    
3. To further restrict which domains can embed your videos, specify **Allowed Origins** on a per-video basis in your Cloudflare Stream dashboard.
    
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
