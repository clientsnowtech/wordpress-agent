# WP Claude Agent — update channel

Self-hosted auto-update for the WP Claude Agent plugin, hosted on GitHub.
WordPress polls the manifest, sees a newer version, and shows the normal
**Update now** button.

## Files
- `channel.json` — the update manifest WP polls (version + zip URL + changelog).
- `build-zip.ps1` — builds `wp-claude-agent.zip` correctly (see warning below).
- `wp-claude-agent.zip` — the packaged plugin; uploaded as a GitHub Release asset.

## Hosting (GitHub)
- Repo: https://github.com/clientsnowtech/wordpress-agent (public)
- Manifest (raw): https://raw.githubusercontent.com/clientsnowtech/wordpress-agent/main/update-channel/channel.json
- Zip: a Release asset, e.g. `.../releases/download/v1.2.0/wp-claude-agent.zip`

## One-time: point sites at the channel
On each WordPress site, add to `wp-config.php`:

```php
define( 'CLAUDE_BRIDGE_UPDATE_MANIFEST', 'https://raw.githubusercontent.com/clientsnowtech/wordpress-agent/main/update-channel/channel.json' );
```

## Release a new version
1. Bump the version in **two** places (must match):
   `wp-plugin/wp-claude-agent/wp-claude-agent.php` header `Version:` **and** `CLAUDE_BRIDGE_VERSION`.
2. Build the zip — **always use the script**, not `Compress-Archive`:
   ```powershell
   .\update-channel\build-zip.ps1
   ```
   > ⚠️ `Compress-Archive` writes Windows backslashes into the zip; WordPress on
   > Linux then mis-extracts it and fails with **"Plugin file does not exist."**
   > `build-zip.ps1` writes forward slashes and a single `wp-claude-agent/` top dir.
3. Edit `channel.json`: set `version`, `last_updated`, `download_url`, add a `<h4>` changelog entry.
4. Commit + push, then publish the asset:
   ```powershell
   git add -A; git commit -F msg.txt; git push
   gh release create vX.Y.Z update-channel\wp-claude-agent.zip --title "WP Claude Agent X.Y.Z" --notes "..."
   ```
5. Sites pick it up within 12h (manifest cached), or sooner via **Dashboard → Updates → Check again**.

## First install (no auto-update yet)
On a site that has never had the plugin: WP admin → **Plugins → Add New → Upload Plugin**
→ `wp-claude-agent.zip` → Install → Activate. Auto-update works from then on.
