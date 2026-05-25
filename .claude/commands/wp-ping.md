---
description: Ping ONE WP Claude Agent site. Default = the site for your current folder. Never pings all sites unless explicitly told.
argument-hint: [site-domain | --all]
allowed-tools: Bash(pwd), Bash(claude mcp:*)
---
**Single-site by default.** This skill pings exactly ONE site. Pinging multiple sites is opt-in via `--all`.

Resolve the target with this hard priority — STOP at the first match:

1. **`$1 == "--all"`** → ping every registered `wp-*` MCP server. Only this flag enables multi-ping.
2. **`$1` is a non-empty domain** → use that domain. Done.
3. **`pwd` is inside a site folder** → run `pwd`. If the path contains `/wordpress-agent/sites/<DOMAIN>` (anywhere, including subfolders), `domain = <DOMAIN>`. **This is the common case — cd into the site folder, run `/wp-ping`, only that site pings.**
4. **No match** → STOP. Tell user: "Not inside a `sites/<domain>/` folder and no `$1` given. Run `/wp-ping <domain>` or `/wp-ping --all` or `cd sites/<domain>` first." Do NOT ping anything.

Steps:

1. Run `pwd`. Apply priority above to get `domain` (or `--all` mode).
2. If `domain` set: compute slug = `wp-` + `<domain>` with `.` → `-` (e.g. `www.sundek.in` → `wp-www-sundek-in`).
3. Confirm registration: `claude mcp list 2>&1 | grep -E "^<slug>:"`. If missing → tell user to run `/wp-connect <domain> <token>` first. Stop.
4. Call `mcp__<slug>__wp_ping`. Report one line: site URL, WP version, PHP version, theme, connection status. **Stop.** Do not call any other site's wp_ping.
5. **`--all` mode only:** loop through every `wp-*` slug in `claude mcp list`, call each `mcp__<slug>__wp_ping`, report one line per site.

Fail modes:
- Tool `mcp__<slug>__wp_ping` not loaded in this session but server IS in `claude mcp list` → session started before the user-scope registration. Tell user to restart Claude Code window. Site is fine, just not loaded yet.
- HTTP 403 / failure → suggest `/wp-connect <domain> <fresh-token>` and a direct curl test: `curl -H "Authorization: Bearer <token>" https://<domain>/wp-json/claude-bridge/v1/ping`.

**Iron-clad rule:** Never ping a site that wasn't selected by steps 1–3 (or `--all`). Pinging both sites when the user is inside one site's folder is a BUG — the cwd is the answer.

Notes:
- Folder = site context. The `sites/<domain>/CLAUDE.md` file in each site folder declares which site that folder represents.
- All sites are user-scope MCP servers ([[wp-agent-multisite]]); they're always loaded, but `/wp-ping` only invokes the one the cwd/arg selects.
