# Crea ti_neo_deploy.zip en la carpeta "New project".
# Para produccion, crea config\config.local.server.php; se copiara como config.local.php.

$source = Split-Path $PSScriptRoot -Parent
$zip = Join-Path (Split-Path $source -Parent) 'ti_neo_deploy.zip'

if (Test-Path $zip) { Remove-Item $zip -Force }

$temp = Join-Path $env:TEMP 'ti_neo_deploy'
if (Test-Path $temp) { Remove-Item $temp -Recurse -Force }
New-Item -ItemType Directory -Path $temp | Out-Null

robocopy $source $temp /E /XD logs .git /XF "*.log" "config.local.php" /NFL /NDL /NJH /NJS /nc /ns /np | Out-Null

$serverConfig = Join-Path $source 'config\config.local.server.php'
if (Test-Path $serverConfig) {
    Copy-Item $serverConfig (Join-Path $temp 'config\config.local.php') -Force
} else {
    Write-Host 'AVISO: no existe config\config.local.server.php; el ZIP no incluira config.local.php de produccion.'
}

Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $zip -Force
Remove-Item $temp -Recurse -Force

Write-Host "Listo: $zip"
