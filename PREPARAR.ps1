param([switch]$SemPausa)
$ErrorActionPreference='Stop'
Set-Location -LiteralPath $PSScriptRoot
function NativeCheck($message){if($LASTEXITCODE -ne 0){throw $message}}
try {
    # Pastas vazias podem ser omitidas ao compactar ou extrair o projeto.
    foreach ($relative in @('api/bootstrap/cache','api/storage/framework/cache/data','api/storage/framework/sessions','api/storage/framework/views','api/storage/logs','frontend/storage/sessions-api')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $PSScriptRoot $relative) | Out-Null
    }
    $php=Join-Path $PSScriptRoot 'runtime\php\php.exe'
    $ini=Join-Path $PSScriptRoot 'runtime\php\php.ini'
    $ext='extension_dir='+(Join-Path $PSScriptRoot 'runtime\php\ext')
    & $php -c $ini -d $ext '.\VALIDAR.php' --runtime-only
    NativeCheck 'O PHP nao iniciou. Instale o Microsoft Visual C++ 2015-2022 x64 da pasta instaladores e tente novamente.'
    docker info --format '{{.OSType}}' 2>$null
    NativeCheck 'Abra o Docker Desktop, aguarde iniciar e use containers Linux.'
    $image = 'pgvector/pgvector:pg16'
    $tar = Join-Path $PSScriptRoot 'banco\postgres-pgvector-pg16.tar'
    $local = docker image ls --quiet $image
    NativeCheck 'Falha ao consultar as imagens locais.'
    if ($local) {
        Write-Output 'Usando a imagem PostgreSQL/pgvector ja instalada.'
    } else {
        if (Test-Path -LiteralPath $tar -PathType Leaf) {
            Write-Output 'Carregando a imagem PostgreSQL/pgvector do pacote...'
            docker load --input $tar
            NativeCheck 'Falha ao carregar a imagem do banco.'
        } else {
            Write-Output 'TAR ausente. Baixando pgvector/pgvector:pg16 (requer internet)...'
            docker pull $image
            NativeCheck 'Falha no download. Confira a internet ou obtenha o TAR original e coloque-o na pasta banco.'
        }
        $local = docker image ls --quiet $image
        NativeCheck 'Falha ao verificar a imagem do banco.'
        if (-not $local) { throw "A imagem esperada nao esta disponivel: $image" }
    }
    docker compose -f compose.yaml up -d postgres
    NativeCheck 'Nao foi possivel iniciar o banco separado. Confira se a porta 55432 esta livre.'
    $ready=$false
    for($attempt=0;$attempt -lt 45;$attempt++) {
        docker compose -f compose.yaml exec -T postgres pg_isready -U intellecta_portatil -d escola 2>$null | Out-Null
        if($LASTEXITCODE -eq 0){$ready=$true;break}
        Start-Sleep -Seconds 2
    }
    if(!$ready){throw 'O banco nao ficou pronto. Confira o Docker Desktop.'}
    docker compose -f compose.yaml cp '.\banco\verificar.sql' 'postgres:/tmp/verificar.sql' | Out-Null
    NativeCheck 'Falha ao preparar a verificacao do banco.'
    $tables=docker compose -f compose.yaml exec -T postgres psql -U intellecta_portatil -d escola -Atf /tmp/verificar.sql
    NativeCheck 'Falha ao conferir o banco.'
    if(($tables | Out-String).Trim() -eq '0') {
        Write-Output 'Restaurando o backup completo em banco separado...'
        docker compose -f compose.yaml cp '.\banco\escola.dump' 'postgres:/tmp/escola.dump'
        NativeCheck 'Falha ao copiar o backup para o banco.'
        docker compose -f compose.yaml exec -T postgres pg_restore -U intellecta_portatil -d escola --no-owner --no-acl --exit-on-error --single-transaction /tmp/escola.dump
        NativeCheck 'Falha na restauracao. Os dados antigos do notebook nao foram alterados.'
    } else {
        Write-Output 'O banco do pacote ja possui dados. Restauracao ignorada para preserva-los.'
    }
    Push-Location '.\api'
    try {
        & $php -c $ini -d $ext artisan config:clear
        NativeCheck 'Falha ao preparar a configuracao local.'
        & $php -c $ini -d $ext artisan migrate --force
        NativeCheck 'Falha ao atualizar a estrutura do banco.'
        & $php -c $ini -d $ext artisan route:clear
        NativeCheck 'Falha ao preparar as rotas.'
        & $php -c $ini -d $ext (Join-Path $PSScriptRoot 'VALIDAR.php')
        NativeCheck 'A API nao conseguiu conectar ao banco do pacote.'
    } finally {Pop-Location}
    Write-Output 'PRONTO. Abra 2-INICIAR.bat e depois http://localhost:8080'
} catch {Write-Host ('ERRO: '+$_.Exception.Message) -ForegroundColor Red; if(!$SemPausa){Read-Host 'Enter para fechar'}; exit 1}
if(!$SemPausa){Read-Host 'Enter para fechar'}
