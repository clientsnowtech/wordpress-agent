=== WP Claude Agent ===
Contributors: clientsnow
Tags: claude, mcp, remote management, developer tools
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0+

Connects this WordPress site to Claude Code via a session token and REST API: read and write files, run database queries, run PHP, manage plugins and options, upload media.

== Description ==

WP Claude Agent pairs a WordPress site with Claude Code for a session. A token generated under Tools → WP Claude Agent gives the local MCP server full file, database and PHP access to the site.

A token grants complete control of the site (remote code execution). Use it only on sites you own, always over HTTPS, and lock it to your IP with the Allowed IPs setting.

== Upgrade Notice ==

= 1.5.0 =
Updates now come from the Powerhouse update server and are checksum-verified before they install. The update-manifest setting is removed.

== Changelog ==

= 1.5.0 =
**Verified over-the-air updates from the Powerhouse server**

* Updates come from the Powerhouse update server. The plugin downloads each update itself, checks its SHA-256 against the published checksum, requires it to be served over HTTPS by that server, and confirms the zip really is WP Claude Agent at the advertised version. Anything else is refused and the installed version is left untouched.
* Security: the update source can no longer be changed through a WordPress option. Options can be written through this plugin's own REST API, so a short-lived token could point every future update at a server of its choosing — a backdoor that outlived the token. The source is now the Powerhouse server, or `CLAUDE_BRIDGE_UPDATE_ORIGIN` in wp-config.php. The Update manifest URL field and the `claude_bridge_manifest_url` option are removed.
* Updates work with no configuration. Before, a site without a manifest URL never updated.
* Updates are actually offered. The previous check waited for data WordPress does not pass on a normal update check, so new versions were silently skipped.
* New Updates panel under Tools → WP Claude Agent: installed and latest version, last check, update server and last install result, plus Check for updates, Update now, View details and an automatic-updates switch. Check for updates is also on the Plugins screen.
* `Update URI` header: WordPress will never offer a same-named plugin from WordPress.org as an update for this one.

= 1.4.2 =
* Security: emit `nocache_headers()` on every Claude Bridge REST request, including auth-rejection paths. Prevents LiteSpeed, Cloudflare or a browser from caching a privileged 200 response and re-serving it to later unauthenticated callers.

= 1.4.1 =
* Lockout fixes: rotating a token now clears stale brute-force fails. The admin status panel shows the current IP, fail count and lockout state, with a one-click Clear all lockouts button.

= 1.4.0 =
* Configure everything from the admin page (no wp-config or cPanel): Allowed IPs, permanent token, and update manifest URL.

= 1.3.0 =
* Static token mode: set a permanent token via the `CLAUDE_BRIDGE_STATIC_TOKEN` wp-config constant (no expiry, no single-client lock); access is still gated by the IP allowlist.

= 1.2.0 =
* Renamed to WP Claude Agent.
* IP allowlist: lock the bridge to specific IPs via the `CLAUDE_BRIDGE_IP_ALLOWLIST` wp-config constant.

= 1.1.0 =
* Self-hosted auto-update via channel.json.
* Per-site changelog on the MCP side.
