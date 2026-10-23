$ErrorActionPreference='Stop'
$php=Join-Path $PSScriptRoot 'runtime/php/php.exe'
$ini=Join-Path $PSScriptRoot 'runtime/php/php.ini'
$ext=Join-Path $PSScriptRoot 'runtime/php/ext'
$script=Join-Path $PSScriptRoot 'api/realtime.php'
# Um processo separado atende todas as conexões SSE, sem bloquear requisições da API.
Start-Process -FilePath $php -ArgumentList @('-c',('"'+$ini+'"'),'-d',('"extension_dir='+$ext+'"'),('"'+$script+'"')) -WorkingDirectory $PSScriptRoot -WindowStyle Hidden
