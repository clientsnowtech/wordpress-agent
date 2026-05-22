# WordPress Agent — Claude Bridge

Connect Claude Code to any WordPress site for one session and change anything:
files (themes, Elementor, BeTheme, any plugin), database, options, install/activate
plugins, run PHP, upload media.

> **WARNING:** A session token = full file + database + PHP (RCE) access to the site.
> Use only on sites you own. Always use HTTPS for remote sites. One token, one client, one session.

## ⚡ Quick connect

New session = generate a fresh token in WP admin (Tools → Claude Bridge), then paste it
into the command below. Swap `YOUR-SITE.com` for the target site and use your local
checkout's path to `mcp-server/index.js`.

```powershell
claude mcp add wp-bridge `
  --env WP_BRIDGE_URL=https://YOUR-SITE.com/wp-json/claude-bridge/v1 `
  --env WP_BRIDGE_TOKEN=PASTE_TOKEN_HERE `
  -- node "C:/path/to/wordpress-agent/mcp-server/index.js"
```

Restart Claude Code (MCP tools load on restart, not mid-session), then ask Claude to run `wp_ping`.

**Common gotchas:**
- The `claude mcp add` line the admin page prints can use the **wrong node path** (the WP
  server's plugin dir). Always point `node` at your local checkout's `mcp-server/index.js`.
- If a WAF/firewall (e.g. Wordfence) is active, it may block eval/SQL/file-write from
  unknown IPs. Allowlist the IP you connect from first.
- With server opcache (e.g. LiteSpeed/OPcache), file changes can lag. After a write, read
  it back to confirm it went live.

## Parts

```
wp-plugin/claude-bridge/claude-bridge.php   ← install on the WordPress site
mcp-server/                                 ← MCP server Claude Code loads locally
```

## How it works

```
Claude Code  ──stdio──►  MCP server (Node)  ──HTTPS + Bearer token──►  WP plugin REST API  ──►  WordPress
```

- Plugin generates a **session token** in WP admin.
- Token is **single-session** (expires, default 8h) and **single-client** (locks to the
  first IP that uses it; others get HTTP 423). Generating a new token kills the old one.
- You paste the token into Claude Code's MCP config. New session → generate new token.

## Setup

### 1. Install the plugin on the WordPress site
- Copy the `wp-plugin/claude-bridge/` folder into `wp-content/plugins/` on the site
  (or zip it and upload via **Plugins → Add New → Upload**).
- Activate **Claude Bridge**.

### 2. Generate a session key
- WP admin → **Tools → Claude Bridge → Generate New Session Key**.
- Copy the token (shown once). Note the **API base URL** shown, e.g.
  `https://yoursite.com/wp-json/claude-bridge/v1`.

### 3. Install MCP server deps (one time)
```bash
cd mcp-server
npm install
```

### 4. Add to Claude Code
```bash
claude mcp add wp-bridge \
  --env WP_BRIDGE_URL=https://yoursite.com/wp-json/claude-bridge/v1 \
  --env WP_BRIDGE_TOKEN=PASTE_TOKEN_HERE \
  -- node "d:/Xampp/htdocs/wordpress-agent/mcp-server/index.js"
```
On Windows PowerShell, run it on one line or use backticks for line continuation.

Then in Claude Code: `/mcp` to confirm it's connected, or just ask Claude to run `wp_ping`.

### New session
Token expired or new session? Repeat steps 2 and 4 (update `WP_BRIDGE_TOKEN`).

## Tools Claude gets

| Tool | Does |
|------|------|
| `wp_ping` | Site info + connection check |
| `wp_read_file` / `wp_write_file` / `wp_list_dir` / `wp_delete_file` | File ops anywhere in the install (themes, plugins, BeTheme, Elementor files) |
| `wp_file_history` / `wp_revert_file` | List auto-backups of every write/delete; undo by `path` or backup `id` (revert is itself reversible) |
| `wp_db_query` | Raw SQL (styling stored in DB, Elementor data, options) |
| `wp_php_eval` | Run arbitrary PHP with WP loaded |
| `wp_list_plugins` / `wp_install_plugin` / `wp_activate_plugin` | Add/manage plugins |
| `wp_get_option` / `wp_set_option` | Read/write WP options |
| `wp_upload_media` | Push a local image/file into the media library |

## Screenshot / file → update flow
Drop a screenshot or file into the Claude Code chat. Claude reads it (vision), figures out the
change, then applies it on the site with the tools above — and can `wp_upload_media` to push assets.

## Notes
- If the site is on Apache and `Authorization` is stripped, the MCP server also sends
  `X-Claude-Token`; the plugin reads either.
- Paths are relative to WordPress `ABSPATH` unless absolute.
- Revoke anytime: **Tools → Claude Bridge → Revoke Active Token**.
