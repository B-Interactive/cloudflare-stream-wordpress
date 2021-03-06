# Cloudflare Stream for WordPress

A fork from the official Cloudflare Stream plugin 1.0.5 for WordPress. This fork looks to achieve these key features:

* Take full advantage of Cloudflare Stream's security features.
* Uses signed URL's / tokens, so video access can be strictly controlled and limited.
* Uses a limited access API token for API access, eliminating the use of the global API key which presents a huge security risk.
* Uses the zone based API, further reducing potential attack surface area.

The Gutenberg Block method of adding videos appears to be broken (in the original plugin also). I've only left code related to the Gutenberg Block in this fork, so as to not break existing content should you switch from the official plugin. Adding new block content will fail as the global API key it's expecting to use is no longer stored in this plugin.  Note the Gutenberg Block as it currently exists, does not support signed URL's / tokens.

USE THE SHORTCODE METHOD INSTEAD.  See Shortcode section below.


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
* Uses zones API for more secure Cloudflare account access.
* Any existing API Key, API account email and API account ID stored in the database are deleted when the settings page is accessed.
* If updating from version older than 1.0.6, you'll need to enter your API Token and API Zone ID in the configuration page.
* Added admin setting for signed URL/token duration (default is otherwise 1 hour).
* Added admin toggle for whether or not to use signed URLs/tokens.


To-Do
------------
* Rebuild Gutenberg block with dynamic support for signed URL's.


Issues
------------
* The Gutenberg Block method of adding videos is failing. This is failing in the original plugin too. Shortcode method is working though. Details on how to use it are below.



Installation
------------
* Copy the contents of this repository (excluding the .git folder) to the following location within your WordPress installation:
```
/wp-content/plugins/cloudflare-stream/
```
* In the WordPress admin panel, visit the Plugins section Activate the Cloudflare Stream plugin.


Admin Settings
------------
The admin area has been completely revised from the official plugin.  Instead of using the all-controlling global API key, this now makes use of a much more secure API token, which only permits the plugin limited access to a Cloudflare account.  When the admin settings are accessed, any existing API key, email and account ID stored in the database, are deleted from the database as these are no longer needed and their presence is a security risks.

![admin-settings](https://user-images.githubusercontent.com/16984998/166196073-0cd8c58e-9c95-49ef-937a-2798802e3769.png)


### API Token ###
An API token must be created in your Cloudflare dashboard, for this plugin. For security sake, the token should only be used for this plugin and provide only the permissions necesarry for the plugin to work.  I'd recommend setting up Client IP Address Filtering when creating the token too.  Where feasible, restrict access to only the IP addresses that need it (eg: your webserver's IP where WordPress is installed).
* **Cloudflare** > **My Profile** > **API Tokens** > **API Tokens** > [Create Token]
Must have permission for: **Account - Stream:Edit**

### API Zone ID ###
* **Cloudflare** > [domain] > **Overview** > [scroll down to API section on the right and copy the Zone ID].

### Use Signed URLs ###
When this is checked [x], videos are accessed using a temporary time-limited token, aka signed URL.  This alone does not secure your content however.  Please see **Securing Video Access** below for further details on how to do that.

### Signed URL Expiration ###
When **Use Signed URLs** is checked [x], this setting controls how long any particular token / signed ULR is valid for **in minutes**. The Cloudflare default, is 60 minutes. Generally, you'd want to make sure this is larger than your longest video.


Securing Video Access
------------

1. Make sure **Use Signed URLs** is checked [x], in the admin settings.  **This feature alone does not secure your videos.** The original ID of your videos is still accessible and could be used to access them.

![use-signed-urls](https://user-images.githubusercontent.com/16984998/166195570-6e2ecfd4-72af-4f11-a52c-f615df470a36.png)

2. Your videos **must** be set to **Require Signed URLs** on a per-video basis, in your Cloudflare Stream dashboard. This will make the original video ID worthless to would-be thieves, because a signed URL/token can only be created in conjunction with your (secure) API key or API token.

![require-signed-url](https://user-images.githubusercontent.com/16984998/166195689-f52c48c6-86f4-40c5-8e96-b9f6ae5790d0.png)

3. Doing steps #1 and #2 does not prevent someone from sharing even the time-limited signed URL of a video. To restrict even that, specify **Allowed Origins** on a per-video basis in your Cloudflare Stream dashboard.

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
