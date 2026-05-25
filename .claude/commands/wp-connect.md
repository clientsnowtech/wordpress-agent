---
description: Connect a WP Claude Agent site as a user-scope MCP server — multi-site, no new window, no restart per site
argument-hint: <site-domain> <token>
allowed-tools: Bash(pwd), Bash(test:*), Bash(claude mcp:*), Bash(mkdir:*), Bash(curl:*), Read, Write
---
**Multi-site connect.** Each site registered as its own user-scope MCP server in `~/.claude.json`. Token never lives in the repo. All connected sites are usable from ANY Claude Code window simultaneously — distinguished by tool prefix `mcp__wp-<slug>__wp_*`. Works with permanent OR temporary tokens.

Inputs:
- `$1` = site (domain or URL)
- `$2` = token (permanent or temporary)

Steps (run in order, no waiting on user mid-flow):

1. **Validate args.** Both `$1` and `$2` required. If either empty: print exact usage `/wp-connect <domain> <token>` and stop. Tell user where to get token: **WP Admin → Tools → WP Claude Agent → Permanent token** OR **Generate temporary access** (single-IP lock, more secure — user prefers this). Never invent token.

2. **Normalize domain.** From `$1` strip `https://`/`http://`, any `/wp-json/...` suffix, trailing `/`. Lowercase. Result: bare `<domain>` (e.g. `www.shuddhjivan.in`).

3. **Slug.** Server name = `wp-` + `<domain>` with `.` → `-`. Example: `www.shuddhjivan.in` → `wp-www-shuddhjivan-in`. Letters/digits/dashes only.

4. **Resolve mcp-server path.** Run `pwd`. Must be the `wordpress-agent` repo root (where `mcp-server/index.js` exists). If not, abort with clear error. Build `<ABS-PATH>` = `<repo-root>/mcp-server/index.js` (forward slashes).

5. **Preflight token verify.** Before touching `~/.claude.json`, confirm the token actually works against the bridge. Run:
   ```
   curl -sS -o /tmp/wpc.txt -w "%{http_code}" "https://<domain>/wp-json/claude-bridge/v1/ping?nocache=$(date +%s%N)" -H "Authorization: Bearer <token>" -H "Cache-Control: no-cache"
   ```
   - HTTP `200` → token good, proceed to step 6.
   - HTTP `403` with body containing `"bad_token"` or `"Invalid token"` → STOP. Tell user: "Token rejected by bridge. Verify token in WP admin → Tools → WP Claude Agent (Permanent token field or fresh Temporary access). Make sure permanent token is NOT set if you're using a temp token (permanent overrides). Re-run `/wp-connect <domain> <correct-token>`." Do NOT register anything.
   - HTTP `403` with body containing `"ip_blocked"` or `"not allowlisted"` → STOP. Tell user the public IP (`curl -sS https://api.ipify.org`) and instruct: add it under WP Admin → Tools → WP Claude Agent → Allowed IPs.
   - HTTP `429` → STOP. Brute-force lockout — wait, then retry.
   - HTTP `423` → token is locked to another client. User must regenerate.
   - Network error / non-HTTP → warn but allow proceeding (transient network is plausible; user can retry `/wp-ping` later). Mention the warning in the report.

6. **Detect update vs fresh.** `claude mcp list 2>&1 | grep -E "^<slug>:"` → if present, this is a token/URL update; remove first: `claude mcp remove <slug> -s user 2>&1`. Note that fact for the final report.

7. **Register user-scope MCP.** Run:
   ```
   claude mcp add -s user <slug> \
     --env WP_BRIDGE_URL=https://<domain>/wp-json/claude-bridge/v1 \
     --env WP_BRIDGE_TOKEN=<token> \
     -- node "<ABS-PATH>"
   ```
   User scope = global across all Claude Code windows, stored in `~/.claude.json` (outside repo, no commit/leak risk per [[wp-agent-token-isolation]]).

8. **Verify.** `claude mcp list 2>&1 | grep -E "^<slug>:"` must show the new server. If "Connected" → success. If "Failed to connect" → flag to user (preflight already passed, so likely a node/path issue).

9. **Ensure changelog folder exists.** `mkdir -p sites/<domain>` so the MCP server's per-site changelog writes succeed on first call. Do NOT write `.mcp.json` or any token file here — that violates [[wp-agent-token-isolation]] now that we use user-scope.

10. **Report (concise).** Format:
   ```
   <action> <domain> as MCP server "<slug>".
   Tools available in any window: mcp__<slug>__wp_ping, mcp__<slug>__wp_read_file, mcp__<slug>__wp_db_query, …
   Quick test: ask Claude to call wp_ping on <slug>. (No new window needed.)
   ```
   Where `<action>` = "Connected" (fresh) or "Updated" (existed). If multiple sites already registered, list them: "Connected sites: wp-www-shuddhjivan-in, wp-www-sundek-in, …".

Rules:
- **No `.mcp.json` in repo, no folder switching, no new window.** That was the old per-site design — deprecated by [[wp-agent-multisite]].
- **Tokens live ONLY in `~/.claude.json` user scope** (outside repo). Never write tokens into `sites/<domain>/.mcp.json` or repo root.
- `sites/<domain>/` folder is reserved for the MCP server's per-site `changelog.md` writes. No config files there.
- Re-running `/wp-connect <domain> <new-token>` rotates the token — step 5 handles the remove + re-add.
- REST namespace fixed: `…/wp-json/claude-bridge/v1`.
- 403 troubleshoot:
  1. Direct curl test first: `curl -H "Authorization: Bearer <token>" https://<domain>/wp-json/claude-bridge/v1/ping` — 200 = token good. 403 = token genuinely wrong or admin issue.
  2. If admin has a **permanent token set** but user is passing a **temp token**, the permanent overrides — temp rejected. Tell user to "Remove permanent token" in admin.
  3. If 403 says "Your IP is not allowlisted" → add IP under **WP Admin → Tools → WP Claude Agent → Allowed IPs**.
  4. If old per-site `sites/<domain>/.mcp.json` exists from the deprecated workflow, delete it — could confuse Claude Code if that folder is ever opened as workspace root.
- Existing user-scope registration must not be silently overwritten without telling user (step 5 logs the update).
