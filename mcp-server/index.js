#!/usr/bin/env node
/**
 * WP Claude Bridge MCP server.
 * Exposes WordPress operations to Claude Code by calling the Claude Bridge plugin's REST API.
 *
 * Required env:
 *   WP_BRIDGE_URL    e.g. https://example.com/wp-json/claude-bridge/v1
 *   WP_BRIDGE_TOKEN  session token generated in WP admin (Tools -> Claude Bridge)
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { readFile, mkdir, appendFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import os from "node:os";

const BASE = (process.env.WP_BRIDGE_URL || "").replace(/\/+$/, "");
const TOKEN = process.env.WP_BRIDGE_TOKEN || "";
const TIMEOUT_MS = Number(process.env.WP_BRIDGE_TIMEOUT_MS || 60000);

if (!BASE || !TOKEN) {
  console.error("Missing WP_BRIDGE_URL or WP_BRIDGE_TOKEN env vars.");
  process.exit(1);
}

// ---- Per-site changelog ---------------------------------------------------
// Each connected site gets sites/<domain>/changelog.md in the shared (Drive)
// folder. Every mutating call is appended with timestamp + dev name so all
// developers see a running history of what changed on each site.
const __dirname = dirname(fileURLToPath(import.meta.url));
const DEV = process.env.WP_DEV_NAME || os.userInfo().username || "unknown";
let SITE_DOMAIN = "unknown-site";
try {
  SITE_DOMAIN = new URL(BASE).hostname || SITE_DOMAIN;
} catch { /* keep default */ }
// Default base = <repo>/sites (one level up from mcp-server/). Override with WP_AGENT_DATA_DIR.
const DATA_DIR = process.env.WP_AGENT_DATA_DIR || join(__dirname, "..", "sites");
const SITE_DIR = join(DATA_DIR, SITE_DOMAIN);
const CHANGELOG = join(SITE_DIR, "changelog.md");
let logReady = null; // promise: folder created + session header written

function shorten(s, n = 200) {
  s = String(s ?? "").replace(/\s+/g, " ").trim();
  return s.length > n ? s.slice(0, n) + "…" : s;
}

// path -> turn a request body into a one-line human description. Only listed
// (mutating) endpoints are logged; reads (/ping, /fs/read, etc.) are skipped.
const MUTATIONS = {
  "/fs/write": (b) => `write_file     ${b?.path}`,
  "/fs/delete": (b) => `delete_file    ${b?.path}`,
  "/fs/revert": (b) => `revert_file    ${b?.path || "id:" + b?.id}`,
  "/db/query": (b) => `db_query       ${shorten(b?.sql)}`,
  "/php/eval": (b) => `php_eval       ${shorten(b?.code)}`,
  "/plugins/install": (b) => `install_plugin ${b?.slug || b?.zip_url}`,
  "/plugins/activate": (b) => `activate_plugin ${b?.plugin}`,
  "/options/set": (b) => `set_option     ${b?.name}`,
  "/media/upload": (b) => `upload_media   ${b?.filename}`,
};

async function ensureLog() {
  if (!logReady) {
    logReady = (async () => {
      await mkdir(SITE_DIR, { recursive: true });
      const stamp = new Date().toISOString().replace("T", " ").slice(0, 19);
      await appendFile(CHANGELOG, `\n## Session ${stamp} — ${DEV}\n`, "utf8");
    })().catch((e) => console.error("changelog init failed:", e.message));
  }
  return logReady;
}

async function logChange(path, body) {
  const fmt = MUTATIONS[path];
  if (!fmt) return; // read-only call, skip
  try {
    await ensureLog();
    const t = new Date().toTimeString().slice(0, 8);
    await appendFile(CHANGELOG, `- ${t}  ${fmt(body)}\n`, "utf8");
  } catch (e) {
    console.error("changelog write failed:", e.message); // never block the op
  }
}

const isLocal = /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])/i.test(BASE);
if (!BASE.startsWith("https://") && !isLocal) {
  console.error("WARNING: WP_BRIDGE_URL is not HTTPS. The session token will travel in clear text. Use HTTPS for remote sites.");
}

// Friendly explanations for the plugin's status codes.
const STATUS_HINT = {
  401: "No active token, or it expired. Generate a new key in WP admin → Tools → Claude Bridge.",
  403: "Token rejected, bridge disabled, or HTTPS required. Check the plugin status page.",
  423: "Token is locked to another client (one remote access at a time). Generate a new key.",
  429: "Too many failed attempts — IP temporarily locked out. Wait, then retry with a fresh key.",
};

async function call(path, { method = "POST", body } = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
  let res;
  try {
    res = await fetch(`${BASE}${path}`, {
      method,
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${TOKEN}`,
        "X-Claude-Token": TOKEN, // fallback when Apache strips Authorization
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: ctrl.signal,
    });
  } catch (e) {
    if (e.name === "AbortError") throw new Error(`Request timed out after ${TIMEOUT_MS}ms: ${path}`);
    throw new Error(`Cannot reach WP_BRIDGE_URL (${BASE}): ${e.message}`);
  } finally {
    clearTimeout(timer);
  }

  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    json = { raw: text };
  }
  if (!res.ok) {
    const msg = json?.message || json?.raw || res.statusText;
    const hint = STATUS_HINT[res.status] ? ` — ${STATUS_HINT[res.status]}` : "";
    throw new Error(`HTTP ${res.status}: ${msg}${hint}`);
  }
  await logChange(path, body); // record mutating ops to the per-site changelog
  return json;
}

const ok = (data) => ({ content: [{ type: "text", text: JSON.stringify(data, null, 2) }] });

const server = new McpServer({ name: "wp-claude-bridge", version: "1.1.0" });

server.tool(
  "wp_ping",
  "Check the WordPress connection and get site info (WP/PHP version, theme, active plugins).",
  {},
  async () => ok(await call("/ping", { method: "GET" }))
);

server.tool(
  "wp_read_file",
  "Read a file from the WordPress install. Path is relative to ABSPATH (or absolute). Returns decoded text.",
  { path: z.string().describe("e.g. wp-content/themes/betheme/style.css") },
  async ({ path }) => {
    const r = await call("/fs/read", { body: { path } });
    return ok({ path: r.path, size: r.size, content: Buffer.from(r.content_base64, "base64").toString("utf8") });
  }
);

server.tool(
  "wp_write_file",
  "Write/overwrite a file on the WordPress install. Creates parent dirs. Path relative to ABSPATH or absolute.",
  { path: z.string(), content: z.string().describe("full UTF-8 file content") },
  async ({ path, content }) =>
    ok(await call("/fs/write", { body: { path, content_base64: Buffer.from(content, "utf8").toString("base64") } }))
);

server.tool(
  "wp_list_dir",
  "List files and folders in a directory of the WordPress install.",
  { path: z.string().default(".").describe("relative to ABSPATH, e.g. wp-content/plugins") },
  async ({ path }) => ok(await call("/fs/list", { body: { path } }))
);

server.tool(
  "wp_delete_file",
  "Delete a single file (not directories) on the WordPress install.",
  { path: z.string() },
  async ({ path }) => ok(await call("/fs/delete", { body: { path } }))
);

server.tool(
  "wp_revert_file",
  "Undo a previous write or delete. Pass `id` for an exact backup, or `path` to revert that file's most recent change. Restores prior content, or removes the file if it didn't exist before. The revert is itself reversible (current state is backed up first).",
  { path: z.string().optional().describe("file to revert (latest change)"), id: z.string().optional().describe("exact backup_id from a write/delete or history") },
  async ({ path, id }) => ok(await call("/fs/revert", { body: { path, id } }))
);

server.tool(
  "wp_file_history",
  "List recorded file-change backups (newest first), each with backup id, action, size, and whether already reverted. Optionally filter by path. Use the id with wp_revert_file.",
  { path: z.string().optional().describe("filter to one file; omit for all") },
  async ({ path }) => ok(await call("/fs/history", { body: { path } }))
);

server.tool(
  "wp_db_query",
  "Run a raw SQL query against the WordPress database. SELECT/SHOW return rows; others return affected count + insert_id. FULL ACCESS — be careful.",
  { sql: z.string() },
  async ({ sql }) => ok(await call("/db/query", { body: { sql } }))
);

server.tool(
  "wp_php_eval",
  "Execute arbitrary PHP inside WordPress (WP functions available). Use `return $x;` to return a value; echo for output. FULL RCE — be careful.",
  { code: z.string().describe("PHP code, no opening <?php tag") },
  async ({ code }) => ok(await call("/php/eval", { body: { code } }))
);

server.tool(
  "wp_list_plugins",
  "List installed plugins with active status.",
  {},
  async () => ok(await call("/plugins/list"))
);

server.tool(
  "wp_install_plugin",
  "Install a plugin from the wp.org repo (slug) or from a zip URL.",
  { slug: z.string().optional().describe("wp.org slug, e.g. classic-editor"), zip_url: z.string().optional().describe("direct .zip URL") },
  async ({ slug, zip_url }) => ok(await call("/plugins/install", { body: { slug, zip_url } }))
);

server.tool(
  "wp_activate_plugin",
  "Activate an installed plugin by its file path (e.g. elementor/elementor.php).",
  { plugin: z.string() },
  async ({ plugin }) => ok(await call("/plugins/activate", { body: { plugin } }))
);

server.tool(
  "wp_get_option",
  "Get a WordPress option value by name (e.g. blogname, elementor settings).",
  { name: z.string() },
  async ({ name }) => ok(await call("/options/get", { body: { name } }))
);

server.tool(
  "wp_set_option",
  "Set a WordPress option value by name. Value may be string, number, array, or object.",
  { name: z.string(), value: z.any() },
  async ({ name, value }) => ok(await call("/options/set", { body: { name, value } }))
);

server.tool(
  "wp_upload_media",
  "Upload a local file (image/etc.) into the WordPress media library. Reads the file from the local Claude Code machine.",
  { local_path: z.string().describe("path to a file on this machine"), filename: z.string().optional().describe("name to store in WP; defaults to basename") },
  async ({ local_path, filename }) => {
    const buf = await readFile(local_path);
    const name = filename || local_path.split(/[\\/]/).pop();
    return ok(await call("/media/upload", { body: { filename: name, content_base64: buf.toString("base64") } }));
  }
);

const transport = new StdioServerTransport();
await server.connect(transport);
console.error("wp-claude-bridge MCP server running.");
