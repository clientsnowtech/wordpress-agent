# Convert a OneDrive "Anyone with the link" share URL into a direct-download URL
# that WordPress (wp_remote_get) can read raw. Works for consumer OneDrive.
#
#   .\onedrive-direct-url.ps1 "https://1drv.ms/u/s!Abc...xyz"
#
param([Parameter(Mandatory=$true)][string]$ShareUrl)

$b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($ShareUrl))
$enc = $b64.TrimEnd('=').Replace('/', '_').Replace('+', '-')
"https://api.onedrive.com/v1.0/shares/u!$enc/root/content"
