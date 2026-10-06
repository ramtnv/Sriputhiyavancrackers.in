$ErrorActionPreference = 'Stop'

$profilePath = Join-Path $env:USERPROFILE 'Playwright\smartftp.json'
$localPath = Join-Path $PSScriptRoot 'index.html'

if (-not (Test-Path -LiteralPath $profilePath -PathType Leaf)) {
  throw "FTP profile not found: $profilePath"
}
if (-not (Test-Path -LiteralPath $localPath -PathType Leaf)) {
  throw "Page to upload not found: $localPath"
}

$config = Get-Content -LiteralPath $profilePath -Raw | ConvertFrom-Json
if ($config.protocol -ne 'ftp') {
  throw 'The saved profile is not configured for FTP. Upload cancelled.'
}
if (-not $config.host -or -not $config.username -or -not $config.password) {
  throw 'The saved FTP profile is missing the host or login details.'
}

$uriBuilder = [System.UriBuilder]::new('ftp', [string]$config.host, [int]$config.port, '/index.html')
$uri = $uriBuilder.Uri
$localBytes = [System.IO.File]::ReadAllBytes($localPath)

Write-Host 'FTP is unencrypted: credentials and page contents are not protected in transit.' -ForegroundColor Yellow
Write-Host "Destination: $($config.host):$($config.port)/index.html"
Write-Host "Local file:  $localPath"
$confirmation = Read-Host 'Type UPLOAD to overwrite the remote /index.html'
if ($confirmation -cne 'UPLOAD') {
  Write-Host 'Upload cancelled.'
  exit 0
}

$credential = [System.Net.NetworkCredential]::new(
  [string]$config.username,
  [string]$config.password
)

$request = [System.Net.FtpWebRequest]::Create($uri)
$request.Credentials = $credential
$request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
$request.UsePassive = $true
$request.UseBinary = $true
$request.KeepAlive = $false
$request.Timeout = 60000
$request.ReadWriteTimeout = 60000
$request.ContentLength = $localBytes.Length

try {
  $uploadStream = $request.GetRequestStream()
  try {
    $uploadStream.Write($localBytes, 0, $localBytes.Length)
  }
  finally {
    $uploadStream.Dispose()
  }

  $uploadResponse = [System.Net.FtpWebResponse]$request.GetResponse()
  $uploadStatus = $uploadResponse.StatusDescription.Trim()
  $uploadResponse.Dispose()
}
catch {
  $status = if ($_.Exception.InnerException) { $_.Exception.InnerException.Message } else { $_.Exception.Message }
  throw "FTP upload failed: $status"
}

$downloadRequest = [System.Net.FtpWebRequest]::Create($uri)
$downloadRequest.Credentials = $credential
$downloadRequest.Method = [System.Net.WebRequestMethods+Ftp]::DownloadFile
$downloadRequest.UsePassive = $true
$downloadRequest.UseBinary = $true
$downloadRequest.KeepAlive = $false
$downloadRequest.Timeout = 60000
$downloadRequest.ReadWriteTimeout = 60000

try {
  $downloadResponse = [System.Net.FtpWebResponse]$downloadRequest.GetResponse()
  $remoteStream = $downloadResponse.GetResponseStream()
  $memoryStream = [System.IO.MemoryStream]::new()
  try {
    $remoteStream.CopyTo($memoryStream)
    $remoteBytes = $memoryStream.ToArray()
  }
  finally {
    $memoryStream.Dispose()
    $remoteStream.Dispose()
    $downloadResponse.Dispose()
  }
}
catch {
  $status = if ($_.Exception.InnerException) { $_.Exception.InnerException.Message } else { $_.Exception.Message }
  throw "Upload completed, but remote verification failed: $status"
}

$sha256 = [System.Security.Cryptography.SHA256]::Create()
try {
  $localHash = [BitConverter]::ToString($sha256.ComputeHash($localBytes)).Replace('-', '')
  $remoteHash = [BitConverter]::ToString($sha256.ComputeHash($remoteBytes)).Replace('-', '')
}
finally {
  $sha256.Dispose()
}

if ($remoteHash -ne $localHash) {
  throw 'Upload verification failed: remote /index.html does not match the local file.'
}

Write-Host "Upload verified: /index.html ($($remoteBytes.Length) bytes)." -ForegroundColor Green
Write-Host "FTP server response: $uploadStatus"
