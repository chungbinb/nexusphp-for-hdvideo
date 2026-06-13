Set-StrictMode -Version Latest
$ErrorActionPreference = "Continue"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$apiScript = Join-Path $scriptRoot "bt-api.ps1"
$configPath = Join-Path (Split-Path -Parent $scriptRoot) ".btpanel.local.json"

$cfg = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
$basePanelUrl = [string]$cfg.panel_url
$urlNoScheme = $basePanelUrl -replace '^https?://', ''
$candidateUrls = @("http://$urlNoScheme", "https://$urlNoScheme") | Select-Object -Unique

$attempts = @(
    @{ Endpoint = "/system"; Action = "GetSystemTotal" },
    @{ Endpoint = "/system"; Action = "GetNetWork" },
    @{ Endpoint = "/config"; Action = "get_panel_info" }
)

$ok = $false
foreach ($url in $candidateUrls) {
    $tempCfg = [System.IO.Path]::GetTempFileName()
    try {
        @{ panel_url = $url; api_key = $cfg.api_key } | ConvertTo-Json | Set-Content -LiteralPath $tempCfg -Encoding UTF8
        foreach ($a in $attempts) {
            try {
                & $apiScript -ConfigPath $tempCfg -Endpoint $a.Endpoint -Action $a.Action -Insecure
                $ok = $true
                break
            } catch {
                Write-Warning ("BT API probe failed: {0} {1} via {2}, err: {3}" -f $a.Endpoint, $a.Action, $url, $_.Exception.Message)
            }
        }
    } finally {
        Remove-Item -LiteralPath $tempCfg -ErrorAction SilentlyContinue
    }
    if ($ok) {
        break
    }
}

if (-not $ok) {
    throw "All BT API probes failed. Check panel_url/api_key and firewall."
}

Write-Host "BT API connected successfully." -ForegroundColor Green
