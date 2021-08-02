# Cloudflare Stream for WordPress

A fork of the official Cloudflare Stream plugin 1.0.4 for WordPress.

The Gutenberg Block method of adding videos appears to be broken (fails to list online videos in backend), in the original plugin also.
Use the Shortcode method instead.  See Shortcode section below.


The original official plugin:
https://wordpress.org/plugins/cloudflare-stream/


Changes
------------
* Analytics reporting opt-in/out clearer.
* Shortcode method now always uses signed URL's / tokens.
* Added additional shortcode options, controls, autoplay, loop, preload and muted.


To-Do
------------
* Include support for API Token based API.
* Include support for zones API.


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

Copyright (C) 2021 B-Interactive
Copyright (C) 2020 Cloudflare
Copyright (C) 2020 WP Engine

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
