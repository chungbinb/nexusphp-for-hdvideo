param(
    [string]$ConfigPath = ".btpanel.local.json"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-Md5Hex {
    param([Parameter(Mandatory = $true)][string]$Text)
    $md5 = [System.Security.Cryptography.MD5]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Text)
        $hashBytes = $md5.ComputeHash($bytes)
        return -join ($hashBytes | ForEach-Object { $_.ToString("x2") })
    } finally {
        $md5.Dispose()
    }
}

$config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
$panelUrl = [string]$config.panel_url
$apiKey = [string]$config.api_key

if ($panelUrl -notmatch '^https?://') {
    $panelUrl = "https://$panelUrl"
}
$panelUrl = $panelUrl.TrimEnd('/')

add-type @"
using System.Net;
using System.Security.Cryptography.X509Certificates;
public class TrustAllCertsPolicySync : ICertificatePolicy {
    public bool CheckValidationResult(ServicePoint srvPoint, X509Certificate certificate, WebRequest request, int certificateProblem) {
        return true;
    }
}
"@ -ErrorAction SilentlyContinue | Out-Null
[System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicySync
[System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12

$requestTime = [int][double]::Parse((Get-Date -UFormat %s))
$requestToken = Get-Md5Hex -Text ("{0}{1}" -f $requestTime, (Get-Md5Hex -Text $apiKey))

$repoRoot = Split-Path -Parent $PSScriptRoot
$items = @(
    @{ local = Join-Path $repoRoot "public/login.php"; remote = "/www/wwwroot/hdvideo.wkwl.cc/public/login.php" },
    @{ local = Join-Path $repoRoot "public/signup.php"; remote = "/www/wwwroot/hdvideo.wkwl.cc/public/signup.php" },
    @{ local = Join-Path $repoRoot "public/image.php"; remote = "/www/wwwroot/hdvideo.wkwl.cc/public/image.php" },
    @{ local = Join-Path $repoRoot "public/css/login-modern.css"; remote = "/www/wwwroot/hdvideo.wkwl.cc/public/css/login-modern.css" },
    @{ local = Join-Path $repoRoot "public/styles/modern-refresh.css"; remote = "/www/wwwroot/hdvideo.wkwl.cc/public/styles/modern-refresh.css" },
    @{ local = Join-Path $repoRoot "app/Services/Captcha/Drivers/ImageCaptchaDriver.php"; remote = "/www/wwwroot/hdvideo.wkwl.cc/app/Services/Captcha/Drivers/ImageCaptchaDriver.php" },
    @{ local = Join-Path $repoRoot "public/torrents.php"; remote = "/www/wwwroot/hdvideo.wkwl.cc/public/torrents.php" },
    @{ local = Join-Path $repoRoot "include/functions.php"; remote = "/www/wwwroot/hdvideo.wkwl.cc/include/functions.php" }
)

foreach ($it in $items) {
    if (-not (Test-Path -LiteralPath $it.local)) {
        throw "Local file not found: $($it.local)"
    }
    $content = Get-Content -LiteralPath $it.local -Raw
    $body = @{
        request_time = $requestTime
        request_token = $requestToken
        path = $it.remote
        data = $content
        encoding = "utf-8"
    }
    $uri = "$panelUrl/files?action=SaveFileBody"
    Write-Host "Syncing: $($it.local) -> $($it.remote)"
    $resp = Invoke-RestMethod -Method Post -Uri $uri -Body $body -TimeoutSec 60
    $respJson = $resp | ConvertTo-Json -Depth 4
    Write-Host $respJson
}

Write-Host "BT file sync completed." -ForegroundColor Green
