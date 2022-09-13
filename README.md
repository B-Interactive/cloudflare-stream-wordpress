# Cloudflare Stream for WordPress

<a href="https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/latest"><img src="https://badgen.net//github/release/B-Interactive/cloudflare-stream-wordpress" /></a>
<a href="https://github.com/B-Interactive/cloudflare-stream-wordpress/blob/main/LICENSE"><img src="https://badgen.net//github/license/B-Interactive/cloudflare-stream-wordpress" /></a>

<img src="https://github.com/B-Interactive/cloudflare-stream-wordpress/actions/workflows/wpcs.yml/badge.svg" />

A fork from the official Cloudflare Stream plugin 1.0.5 for WordPress. This fork looks to achieve these key features:

* Take full advantage of Cloudflare Stream's security features.
* Uses signed URL's / tokens, so video access can be strictly controlled and limited.
* Uses a limited access API token for API access, eliminating the use of the global API key which presents a huge security risk.
* Incorporate additional features and new features as they're made available.

The Gutenberg Block method of adding videos appears to be broken (in the original plugin also). I've only left code related to the Gutenberg Block in this fork, so as to not break existing content should you switch from the official plugin. Adding new block content will fail as the global API key it's expecting to use is no longer stored in this plugin.  Note the Gutenberg Block as it currently exists, does not support signed URL's / tokens.

USE THE SHORTCODE METHOD INSTEAD.  [See Shortcode section below](#shortcode).


The original official plugin:
https://wordpress.org/plugins/cloudflare-stream/

Official plugin GitHub page:
https://github.com/cloudflare/stream-wordpress


Changes from Official
------------
* Shortcode method (optionally) uses signed URL's / tokens.
* Removed analytics.
* Added additional shortcode options: controls, autoplay, loop, preload and muted.
* Uses API Token based API access, for MUCH more secure Cloudflare account access.
* Any existing API Key and API account email stored in the database are deleted when the settings page is accessed.
* If updating from version older than 1.0.6, you'll need to enter your Cloudflare API Token and Cloudflare Account ID in the configuration page.
* Added admin setting for signed URL/token duration (default is otherwise 1 hour).
* Added admin toggle for whether or not to use signed URLs/tokens.
* Can select Cloudflare media domain, including new account specific sub-domain.


To-Do
------------
* Rebuild Gutenberg block with dynamic support for signed URL's.


Issues
------------
* The Gutenberg Block method of adding videos is failing. This is failing in the original plugin too. Shortcode method is working though. Details on how to use it are below.



Installation
------------
* Download the full plugin ZIP file from the [latest release of this plugin](https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/latest)
* In the WordPress admin panel, go to Plugins > Add New > Upload Plugin and upload the ZIP file
* Click the "Activate" button
* In the WordPress admin panel, visit the Plugins section Activate the Cloudflare Stream plugin.


Admin Settings
------------
The admin area has been completely revised from the official plugin.  Instead of using the all-controlling global API key, this now makes use of a much more secure API token, which only permits the plugin limited access to a Cloudflare account.  When the admin settings are accessed, any existing API key and email stored in the database, are deleted from the database as these are no longer needed and their presence is a security risks.

![admin-settings](https://user-images.githubusercontent.com/16984998/188538819-ac0b9905-7d62-4118-81ff-d92a78ba7ea7.png)


### API Account ID ###
* **Cloudflare** > [domain] > **Overview** > [scroll down to API section on the right and copy the Account ID].

### API Token ###
An API token must be created in your Cloudflare dashboard, for this plugin. For security sake, the token should only be used for this plugin and provide only the permissions necesarry for the plugin to work.  I'd recommend setting up Client IP Address Filtering when creating the token too.  Where feasible, restrict access to only the IP addresses that need it (eg: your webserver's IP where WordPress is installed).
* **Cloudflare** > **My Profile** > **API Tokens** > **API Tokens** > [Create Token]
Must have permission for: **Account - Stream:Edit**

### Use Signed URLs ###
When this is checked [x], videos are accessed using a temporary time-limited token, aka signed URL.  This alone does not secure your content however.  Please see **[Securing Video Access](#securing-video-access)** below for further details on how to do that.

### Signed URL Expiration ###
When **Use Signed URLs** is checked [x], this setting controls how long any particular token / signed ULR is valid for **in minutes**. The Cloudflare default, is 60 minutes. Generally, you'd want to make sure this is larger than your longest video.

### Preferred Media Domain ###
This option allows you to select from a small list of different Cloudflare media domains. This domain is used when delivering content to your users. The 3rd option is a unique subdomain specific to your Cloudflare account. This option will only be presented if you have at least one video already uploaded to your Cloudflare Stream account.


Securing Video Access
------------

1. Make sure **Use Signed URLs** is checked [x], in the admin settings.  **This feature alone does not secure your videos.** The original ID of your videos is still accessible and could be used to access them.

![use-signed-urls](https://user-images.githubusercontent.com/16984998/166195570-6e2ecfd4-72af-4f11-a52c-f615df470a36.png)

2. Your videos **must** be set to **Require Signed URLs** on a per-video basis, in your Cloudflare Stream dashboard. This will make the original video ID worthless to would-be thieves, because a signed URL/token can only be created in conjunction with your (secure) API key or API token.

![require-signed-url](https://user-images.githubusercontent.com/16984998/166195689-f52c48c6-86f4-40c5-8e96-b9f6ae5790d0.png)

3. To further restrict which domains can embed your videos, specify **Allowed Origins** on a per-video basis in your Cloudflare Stream dashboard.

![allowed-origins](https://user-images.githubusercontent.com/16984998/166195828-80c23260-fc02-47bb-89b1-ceb8a4217638.png)


Shortcode
------------

`[cloudflare_stream uid="`_some video id_`"]`

Replace _some video id_ with an actual Cloudflare Stream video ID.

These are optional shortcode flags (with defaults shown here). These are all "true" or "false" options:

* controls="true"
* autoplay="false"
* loop="false"
* preload="false"
* muted="false"


They can be used in this way:

`[cloudflare_stream uid="`_some video id_`" controls="true" autoplay="false" loop="false" preload="false" muted="false"]`


Developers
------------
Clone this repo, cd into the `cloudflare-stream-wordpress` directory and run

```bash
$ npm install
```

Build for development (uses development mode for Webpack, making browser debugging easier):

```bash
$ npm run build:dev
```

Build for production:

```bash
$ npm run build
```

Acknowledgements
----------------
* Cloudflare and WP Engine for developing the original plugin this was forked from.


License
-------

Copyright (C) 2020 Cloudflare

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
