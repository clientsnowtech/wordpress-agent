# Claude Bridge — update channel

Self-hosted auto-update for the Claude Bridge plugin. WordPress checks the
manifest here, sees a newer version, and shows the normal **Update now** button.

## Files
- `channel.json` — the update manifest WP polls (version + zip URL + changelog).
- `claude-bridge.zip` — the packaged plugin that `download_url` points to.

## One-time: point sites at the channel
On each WordPress site, add to `wp-config.php`:

```php
define( 'CLAUDE_BRIDGE_UPDATE_MANIFEST', 'https://YOUR-PUBLIC-HOST/claude-bridge/channel.json' );
```

> `channel.json` and the zip must be on a **public HTTPS URL** — a OneDrive/Drive
> *file path* won't work; WordPress fetches over HTTP. A OneDrive **share link**
> also won't work directly (it returns an HTML preview, not the raw file).
> See "Hosting on OneDrive" below to turn share links into direct URLs.

## Hosting on OneDrive
1. Put `channel.json` + `claude-bridge.zip` in a OneDrive folder.
2. Get an **"Anyone with the link — can view"** share link for *each* file.
3. Convert each share link to a raw direct-download URL:
   ```powershell
   .\onedrive-direct-url.ps1 "https://1drv.ms/u/s!Abc...xyz"
   ```
4. Use the converted URLs:
   - the **channel.json** direct URL → `CLAUDE_BRIDGE_UPDATE_MANIFEST` in `wp-config.php`.
   - the **claude-bridge.zip** direct URL → `download_url` in `channel.json`.

Notes:
- Overwrite the *same* files on new releases (don't delete+recreate) so the share
  links — and therefore the direct URLs — stay stable.
- This direct URL works for **consumer** OneDrive. OneDrive **for Business** /
  SharePoint instead appends `?download=1` to the share link.

## Release a new version
1. Bump the version in **two** places (must match):
   - `wp-plugin/claude-bridge/claude-bridge.php` header `Version:` and `CLAUDE_BRIDGE_VERSION`.
2. Zip the plugin folder so the zip's top dir is `claude-bridge/`:
   ```powershell
   Compress-Archive -Path wp-plugin\claude-bridge -DestinationPath update-channel\claude-bridge.zip -Force
   ```
3. Edit `channel.json`: set `version`, `last_updated`, `download_url`, and add a
   `<h4>` changelog entry.
4. Upload `channel.json` + `claude-bridge.zip` to the public host.
5. Sites pick it up within 12h (manifest is cached), or sooner via
   **Dashboard → Updates → Check again**.
