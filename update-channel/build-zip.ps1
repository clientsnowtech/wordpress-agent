# Build wp-claude-agent.zip with FORWARD-SLASH entries and a single top-level
# folder (wp-claude-agent/). PowerShell's Compress-Archive writes backslashes,
# which WordPress (Linux) mis-extracts -> "Plugin file does not exist." Use this.
#
#   .\build-zip.ps1
#
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent           # repo root
$src  = Join-Path $root 'wp-plugin\wp-claude-agent' # plugin folder
$outs = @(
  (Join-Path $root 'wp-claude-agent.zip'),                 # handy local copy
  (Join-Path $root 'update-channel\wp-claude-agent.zip')   # release asset
)
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$base = Split-Path $src -Parent
foreach ($out in $outs) {
  if (Test-Path $out) { Remove-Item $out -Force }
  $zip = [System.IO.Compression.ZipFile]::Open($out, [System.IO.Compression.ZipArchiveMode]::Create)
  try {
    Get-ChildItem -Path $src -Recurse -File | ForEach-Object {
      $rel = $_.FullName.Substring($base.Length + 1).Replace('\', '/')
      $e   = $zip.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
      $es  = $e.Open(); $fs = [System.IO.File]::OpenRead($_.FullName)
      $fs.CopyTo($es); $fs.Dispose(); $es.Dispose()
    }
  } finally { $zip.Dispose() }
  Write-Host "built $out"
}
