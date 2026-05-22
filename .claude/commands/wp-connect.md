---
description: Connect Claude Code to a WP Claude Agent site (adds/updates the wp-bridge MCP server)
argument-hint: <site-domain> [permanent-token]
allowed-tools: Bash(claude mcp:*), Bash(node:*), Bash(pwd), Bash(ls:*)
---
Connect this machine to the WP Claude Agent bridge on a WordPress site.

Input:
- Site (domain or full URL): `$1`
- Permanent token (optional): `$2`

Do this:
1. Normalize `$1` into a bare domain `<domain>` — strip any `https://`/`http://`, any `/wp-json/...` suffix, and trailing slashes. If `$1` is empty, ask the user which site and stop.
2. Find the ABSOLUTE path to `mcp-server/index.js` for THIS project (it lives under the current project directory; resolve it from the working directory — do not hardcode a username).
3. Token: if `$2` is non-empty use it. Otherwise tell the user to get it from the site's **WP admin → Tools → WP Claude Agent → Permanent token** (set once, never expires), then stop and wait for them to re-run `/wp-connect <domain> <token>`. Never invent a token.
4. If a `wp-bridge` server is already configured, remove it first: `claude mcp remove wp-bridge` (ignore "not found").
5. Add it:
   ```
   claude mcp add wp-bridge --env WP_BRIDGE_URL=https://<domain>/wp-json/claude-bridge/v1 --env WP_BRIDGE_TOKEN=<token> -- node "<abs-path>/mcp-server/index.js"
   ```
6. Tell the user clearly: **MCP tools load on restart** — restart Claude Code (or reload the window), then run `/wp-ping`. Also remind them this is a one-time setup per machine: because the token is permanent, every future chat is already connected — they do NOT need to run this again unless they switch sites or change the token.

Notes:
- The REST path is always `…/wp-json/claude-bridge/v1` (namespace unchanged even though the plugin is named "WP Claude Agent").
- If `wp_ping` later returns 403, the site's **Allowed IPs** list doesn't include this machine's public IP — fix it in Tools → WP Claude Agent → Connection settings.
