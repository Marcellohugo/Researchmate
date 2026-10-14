param([ValidateRange(1, 65535)][int]$Port = 8000)

$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskArtisan = Join-Path $taskRoot 'artisan'
$taskArtisanArgument = '"' + $taskArtisan + '"'
$taskRuntime = Join-Path $env:LOCALAPPDATA 'ResearchMate\runtime'
$taskPhp = Join-Path $taskRuntime 'php\php.exe'
if (-not (Test-Path -LiteralPath $taskPhp)) { $taskPhp = (Get-Command php -ErrorAction Stop).Source }
$taskLogDir = Join-Path $taskRoot 'storage\logs'
New-Item -ItemType Directory -Path $taskLogDir -Force | Out-Null

$taskPgBin = Join-Path $taskRuntime 'postgresql16\pgsql\bin'
$taskPgData = Join-Path $env:LOCALAPPDATA 'ResearchMate\postgres16-data'
if ((Test-Path -LiteralPath (Join-Path $taskPgBin 'pg_ctl.exe')) -and (Test-Path -LiteralPath (Join-Path $taskPgData 'PG_VERSION'))) {
    $taskDbPort = if ($env:DB_PORT) { $env:DB_PORT } else { ((Get-Content -LiteralPath (Join-Path $taskRoot '.env') | Where-Object { $_ -match '^DB_PORT=' } | Select-Object -First 1) -replace '^DB_PORT=', '').Trim('"', "'") }
    if ($taskDbPort -notmatch '^\d{1,5}$' -or [int]$taskDbPort -lt 1 -or [int]$taskDbPort -gt 65535) { throw 'DB_PORT harus berisi port PostgreSQL yang valid.' }
    & (Join-Path $taskPgBin 'pg_ctl.exe') -D $taskPgData status *> $null
    if ($LASTEXITCODE -ne 0) {
        & (Join-Path $taskPgBin 'pg_ctl.exe') -D $taskPgData -l (Join-Path $taskPgData 'server.log') -o "-h 127.0.0.1 -p $taskDbPort" -w start
        if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL lokal tidak dapat dimulai. Periksa server.log.' }
    }
}

Push-Location -LiteralPath $taskRoot
try {
    & $taskPhp artisan config:clear
    & $taskPhp artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Migrasi gagal. Periksa konfigurasi database.' }
    $taskWorkerPattern = '(?:^|\s)"?' + [regex]::Escape($taskArtisan) + '"?\s+queue:work(?:\s|$)'
    $taskWorkers = Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match $taskWorkerPattern -and $_.ExecutablePath -eq $taskPhp }
    if (-not $taskWorkers) {
        Start-Process -FilePath $taskPhp -ArgumentList @($taskArtisanArgument, 'queue:work', '--tries=3', '--timeout=1800', '--sleep=1') -WorkingDirectory $taskRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $taskLogDir 'queue-out.log') -RedirectStandardError (Join-Path $taskLogDir 'queue-error.log') | Out-Null
    }
    $taskListener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    if (-not $taskListener) {
        Start-Process -FilePath $taskPhp -ArgumentList @($taskArtisanArgument, 'serve', '--host=127.0.0.1', "--port=$Port", '--no-reload') -WorkingDirectory $taskRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $taskLogDir 'server-out.log') -RedirectStandardError (Join-Path $taskLogDir 'server-error.log') | Out-Null
    }
    Write-Output "ResearchMate lokal: http://127.0.0.1:$Port"
    Write-Output 'Log server dan antrean tersedia di storage/logs. Proses dijalankan tanpa jendela tambahan.'
} finally { Pop-Location }
