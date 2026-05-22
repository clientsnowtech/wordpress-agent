---
description: Check the WP Claude Agent connection (runs wp_ping)
allowed-tools: mcp__wp-bridge__wp_ping
---
Confirm the WordPress bridge is connected by calling the `wp_ping` MCP tool, then report the site URL, WordPress/PHP version, active theme, and connection status in one short line.

If the `wp_ping` / `wp-bridge` tool is not available, it means the MCP server isn't loaded in this session:
- If `/wp-connect` was just run, tell the user to **restart Claude Code** (MCP tools load on restart).
- Otherwise tell them to run `/wp-connect <site-domain>` first.
