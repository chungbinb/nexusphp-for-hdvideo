param(
    [string]$ConfigPath = ".btpanel.local.json",
    [string]$Endpoint = "/system",
    [string]$Action = "GetSystemTotal",
    [hashtable]$ExtraParams,
    [string]$ExtraParamsJson,
    [string[]]$ExtraParamPairs,
    [switch]$Insecure
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

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
$panelUrl = [string]$config.panel_url
$panelUrl = $panelUrl.TrimEnd('/')
$apiKey = [string]$config.api_key

if ([string]::IsNullOrWhiteSpace($panelUrl) -or [string]::IsNullOrWhiteSpace($apiKey)) {
    throw "Invalid config. panel_url or api_key is empty."
}

if ($panelUrl -notmatch '^https?://') {
    $panelUrl = "http://$panelUrl"
}

if ($Insecure -and $panelUrl -match '^https://') {
    add-type @"
using System.Net;
using System.Security.Cryptography.X509Certificates;
public class TrustAllCertsPolicy : ICertificatePolicy {
    public bool CheckValidationResult(ServicePoint srvPoint, X509Certificate certificate, WebRequest request, int certificateProblem) {
        return true;
    }
}
"@ -ErrorAction SilentlyContinue | Out-Null
    [System.Net.ServicePointManager]::CertificatePolicy = New-Object TrustAllCertsPolicy
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
    [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
}

$requestTime = [int][double]::Parse((Get-Date -UFormat %s))
$requestToken = Get-Md5Hex -Text ("{0}{1}" -f $requestTime, (Get-Md5Hex -Text $apiKey))

$body = @{
    request_time = $requestTime
    request_token = $requestToken
    action = $Action
}

if ($ExtraParams) {
    foreach ($k in $ExtraParams.Keys) {
        $body[$k] = $ExtraParams[$k]
    }
}

if (-not [string]::IsNullOrWhiteSpace($ExtraParamsJson)) {
    $jsonObj = ConvertFrom-Json -InputObject $ExtraParamsJson
    foreach ($p in $jsonObj.PSObject.Properties) {
        $body[$p.Name] = $p.Value
    }
}

if ($ExtraParamPairs) {
    foreach ($pair in $ExtraParamPairs) {
        if ([string]::IsNullOrWhiteSpace($pair) -or $pair.IndexOf('=') -lt 1) {
            continue
        }
        $idx = $pair.IndexOf('=')
        $k = $pair.Substring(0, $idx)
        $v = $pair.Substring($idx + 1)
        $body[$k] = $v
    }
}

$uri = "{0}{1}" -f $panelUrl, $Endpoint

Write-Host ("Calling BT API: {0}?action={1}" -f $uri, $Action)

$response = Invoke-RestMethod -Method Post -Uri $uri -Body $body -TimeoutSec 30
$response | ConvertTo-Json -Depth 8
