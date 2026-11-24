[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PhpRuntimePath,

    [Parameter(Mandatory = $true)]
    [string]$SqliteDatabasePath,

    [Parameter(Mandatory = $true)]
    [string]$OutputPath,

    [switch]$EnableSync
)

$ErrorActionPreference = 'Stop'
$sourceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtimeRoot = (Resolve-Path -LiteralPath $PhpRuntimePath).Path
$databasePath = (Resolve-Path -LiteralPath $SqliteDatabasePath).Path
$phpExecutable = Join-Path $runtimeRoot 'php.exe'
$extensionDirectory = Join-Path $runtimeRoot 'ext'

if (!(Test-Path -LiteralPath $phpExecutable -PathType Leaf)) {
    throw "No se encontró php.exe en '$runtimeRoot'."
}
foreach ($extension in @('php_pdo_sqlite.dll', 'php_sqlite3.dll', 'php_mbstring.dll', 'php_openssl.dll', 'php_curl.dll')) {
    if (!(Test-Path -LiteralPath (Join-Path $extensionDirectory $extension) -PathType Leaf)) {
        throw "El runtime PHP no incluye la extensión requerida $extension."
    }
}
$caBundleCandidates = @(
    (Join-Path $runtimeRoot 'curl-ca-bundle.crt'),
    (Join-Path $runtimeRoot 'extras\ssl\cacert.pem'),
    (Join-Path $runtimeRoot '..\apache\bin\curl-ca-bundle.crt')
)
$caBundleSource = $caBundleCandidates |
    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
    Select-Object -First 1
if (!(Test-Path -LiteralPath (Join-Path $sourceRoot 'vendor\autoload.php') -PathType Leaf)) {
    throw 'Falta vendor\autoload.php. Ejecuta composer install antes de crear el paquete.'
}

$syncEndpoint = $null
$syncToken = $null
if ($EnableSync) {
    $syncEndpoint = [Environment]::GetEnvironmentVariable('SYNC_ENDPOINT')
    $syncToken = [Environment]::GetEnvironmentVariable('SYNC_TOKEN')
    if ([string]::IsNullOrWhiteSpace($syncEndpoint) -or
        [string]::IsNullOrWhiteSpace($syncToken) -or $syncToken.Length -lt 32) {
        throw 'Para habilitar sincronización configura SYNC_ENDPOINT y SYNC_TOKEN en esta sesión de PowerShell.'
    }

    try {
        $syncUri = [System.Uri]$syncEndpoint
    } catch {
        throw 'SYNC_ENDPOINT debe ser una URL HTTPS válida.'
    }
    if (!$syncUri.IsAbsoluteUri -or $syncUri.Scheme -ne 'https' -or
        $syncUri.UserInfo -ne '' -or $syncUri.Fragment -ne '') {
        throw 'SYNC_ENDPOINT debe ser una URL HTTPS válida, sin credenciales ni fragmento.'
    }
    if (!$caBundleSource) {
        throw 'El runtime PHP no incluye un paquete de certificados CA para validar HTTPS.'
    }
}

$outputFullPath = [System.IO.Path]::GetFullPath($OutputPath)
$sourcePrefix = $sourceRoot.TrimEnd('\') + '\'
if ($outputFullPath.StartsWith($sourcePrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'El destino debe estar fuera de la carpeta del proyecto.'
}
if (Test-Path -LiteralPath $outputFullPath) {
    throw "El destino ya existe; elige una carpeta nueva: '$outputFullPath'."
}

$databaseStream = [System.IO.File]::OpenRead($databasePath)
try {
    $header = New-Object byte[] 16
    $bytesRead = $databaseStream.Read($header, 0, $header.Length)
} finally {
    $databaseStream.Dispose()
}
if ($bytesRead -ne 16 -or [System.Text.Encoding]::ASCII.GetString($header) -ne "SQLite format 3`0") {
    throw 'El archivo indicado no parece ser una base de datos SQLite válida.'
}

$probeDirectory = Join-Path ([System.IO.Path]::GetTempPath()) (
    'sistema-estudiantes-portable-probe-' + [guid]::NewGuid().ToString('N')
)
New-Item -ItemType Directory -Path $probeDirectory | Out-Null
$probeScript = Join-Path $probeDirectory 'check_database.php'
$probeIni = Join-Path $probeDirectory 'php.ini'
$probeCode = @'
<?php
$pdo = new PDO('sqlite:' . $argv[1]);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
    exit(1);
}

if (($argv[3] ?? '') === 'sync') {
    require_once $argv[2] . '/includes/sync_receiver.php';
    $quote = function (string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    };
    $metadataExists = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'offline_sync_metadata'"
    )->fetchColumn();
    if ($metadataExists === false ||
        $pdo->query(
            'SELECT value FROM offline_sync_metadata WHERE key = "trigger_version"'
        )->fetchColumn() !== '2') {
        fwrite(STDERR, "SQLite aún no está inicializado para sincronización.\n");
        exit(1);
    }
    if ($pdo->query(
        'SELECT value FROM offline_sync_metadata WHERE key = "database_origin"'
    )->fetchColumn() !== 'mysql_export') {
        fwrite(STDERR, "Solo una exportación inicial de MySQL puede habilitar sincronización.\n");
        exit(1);
    }

    foreach (syncReceiverOrder() as $table) {
        $quotedTable = $quote($table);
        $columns = array_column(
            $pdo->query('PRAGMA table_info(' . $quotedTable . ')')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $required = array_merge(['id', 'uuid', 'sincronizado'], syncReceiverSchema()[$table]['fields']);
        if (array_diff($required, $columns) !== []) {
            fwrite(STDERR, "La copia SQLite no tiene el esquema requerido para sincronizar.\n");
            exit(1);
        }

        $triggerBase = 'trg_outbox_' . substr(hash('sha256', $table), 0, 20);
        $triggerCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type = 'trigger' AND name IN (?, ?, ?)"
        );
        $triggerCheck->execute([
            $triggerBase . '_insert',
            $triggerBase . '_update',
            $triggerBase . '_delete',
        ]);
        if ((int) $triggerCheck->fetchColumn() !== 3) {
            fwrite(STDERR, "La copia SQLite no tiene los triggers de sincronización.\n");
            exit(1);
        }

        $unmapped = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . $quotedTable . ' AS source_row
             LEFT JOIN `sync_map` AS mapped
               ON mapped.`uuid` = source_row.`uuid` AND mapped.`tabla` = ?
             WHERE mapped.`id` IS NULL OR source_row.`uuid` IS NULL OR length(source_row.`uuid`) <> 36'
        );
        $unmapped->execute([$table]);
        if ((int) $unmapped->fetchColumn() !== 0) {
            fwrite(STDERR, "La copia SQLite no conserva el mapa de UUID del servidor.\n");
            exit(1);
        }
    }
}
'@
$probeIniContents = @"
extension_dir = "$extensionDirectory"
extension=php_pdo_sqlite.dll
extension=php_sqlite3.dll
"@
[System.IO.File]::WriteAllText($probeScript, $probeCode, (New-Object System.Text.UTF8Encoding($false)))
[System.IO.File]::WriteAllText($probeIni, $probeIniContents, (New-Object System.Text.UTF8Encoding($false)))
try {
    Push-Location $runtimeRoot
    $probeMode = if ($EnableSync) { 'sync' } else { 'local' }
    & $phpExecutable -c $probeIni $probeScript $databasePath $sourceRoot $probeMode
    if ($LASTEXITCODE -ne 0) {
        if ($EnableSync) {
            throw 'El SQLite no es una exportación sincronizable válida o el runtime PHP no pudo abrirlo.'
        }
        throw 'El runtime PHP indicado no pudo abrir o validar la base SQLite.'
    }
} finally {
    Pop-Location
    Remove-Item -LiteralPath $probeDirectory -Recurse -Force
}

New-Item -ItemType Directory -Path $outputFullPath | Out-Null

function Copy-AppTree {
    param([string]$Source, [string]$Destination)

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    foreach ($item in Get-ChildItem -LiteralPath $Source -Force) {
        if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
            continue
        }
        if ($item.PSIsContainer) {
            if ($item.Name -in @('.git', '.vscode', 'backups', 'database', 'logs', 'tests', 'tools')) {
                continue
            }
            Copy-AppTree -Source $item.FullName -Destination (Join-Path $Destination $item.Name)
            continue
        }

        if ($item.Name -in @('.env', 'config.local.php', 'config.local.example.php', '.gitignore') -or
            $item.Extension -in @('.sql', '.sqlite', '.db', '.log', '.zip')) {
            continue
        }
        Copy-Item -LiteralPath $item.FullName -Destination (Join-Path $Destination $item.Name)
    }
}

function Copy-PhpRuntime {
    param([string]$Source, [string]$Destination)

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    foreach ($item in Get-ChildItem -LiteralPath $Source -Force) {
        if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
            continue
        }
        if ($item.PSIsContainer) {
            Copy-PhpRuntime -Source $item.FullName -Destination (Join-Path $Destination $item.Name)
            continue
        }
        if ($item.Name -ieq '.env' -or
            $item.Extension -in @('.ini', '.sql', '.sqlite', '.db', '.log', '.zip')) {
            continue
        }
        Copy-Item -LiteralPath $item.FullName -Destination (Join-Path $Destination $item.Name)
    }
}

function Download-PortableAsset {
    param([string]$Url, [string]$Destination)

    New-Item -ItemType Directory -Path (Split-Path -Parent $Destination) -Force | Out-Null
    Invoke-WebRequest -Uri $Url -OutFile $Destination -UseBasicParsing
    if (!(Test-Path -LiteralPath $Destination -PathType Leaf) -or
        (Get-Item -LiteralPath $Destination).Length -lt 100) {
        throw "No se pudo descargar un recurso requerido: $Url"
    }
}

Copy-AppTree -Source $sourceRoot -Destination $outputFullPath
Copy-PhpRuntime -Source $runtimeRoot -Destination (Join-Path $outputFullPath 'php')
if ($caBundleSource) {
    Copy-Item -LiteralPath $caBundleSource `
        -Destination (Join-Path $outputFullPath 'php\curl-ca-bundle.crt')
}
New-Item -ItemType Directory -Path (Join-Path $outputFullPath 'database') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $outputFullPath 'tmp') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $outputFullPath 'logs') -Force | Out-Null
Copy-Item -LiteralPath $databasePath `
    -Destination (Join-Path $outputFullPath 'database\datos_locales.sqlite')

$assetsRoot = Join-Path $outputFullPath 'assets\vendor'
Download-PortableAsset `
    -Url 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' `
    -Destination (Join-Path $assetsRoot 'bootstrap\css\bootstrap.min.css')
Download-PortableAsset `
    -Url 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js' `
    -Destination (Join-Path $assetsRoot 'bootstrap\js\bootstrap.bundle.min.js')
Download-PortableAsset `
    -Url 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' `
    -Destination (Join-Path $assetsRoot 'fontawesome\css\all.min.css')
Download-PortableAsset `
    -Url 'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js' `
    -Destination (Join-Path $assetsRoot 'chart.js\chart.umd.min.js')

$fontAwesomeCssPath = Join-Path $assetsRoot 'fontawesome\css\all.min.css'
$fontAwesomeCss = [System.IO.File]::ReadAllText($fontAwesomeCssPath)
$fontFiles = [System.Text.RegularExpressions.Regex]::Matches(
    $fontAwesomeCss,
    'url\(["'']?\.\./webfonts/([^)"''?]+)'
)
foreach ($match in $fontFiles) {
    $fontName = $match.Groups[1].Value
    Download-PortableAsset `
        -Url "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/webfonts/$fontName" `
        -Destination (Join-Path $assetsRoot "fontawesome\webfonts\$fontName")
}
if ($fontFiles.Count -eq 0) {
    throw 'No se pudieron identificar las fuentes de Font Awesome.'
}

Download-PortableAsset `
    -Url 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/LICENSE' `
    -Destination (Join-Path $assetsRoot 'bootstrap\LICENSE')
Download-PortableAsset `
    -Url 'https://raw.githubusercontent.com/FortAwesome/Font-Awesome/6.0.0/LICENSE.txt' `
    -Destination (Join-Path $assetsRoot 'fontawesome\LICENSE.txt')
Download-PortableAsset `
    -Url 'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/LICENSE.md' `
    -Destination (Join-Path $assetsRoot 'chart.js\LICENSE.md')

$replacements = @{
    'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' = '/assets/vendor/bootstrap/css/bootstrap.min.css'
    'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js' = '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' = '/assets/vendor/fontawesome/css/all.min.css'
    'https://cdn.jsdelivr.net/npm/chart.js' = '/assets/vendor/chart.js/chart.umd.min.js'
    'https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600&display=swap' = 'data:text/css,'
    '"https://cdn.jsdelivr.net/npm/tesseract.js@v".concat(c,"/dist/worker.min.js")' = '"/js/ocr/vendor/worker.min.js"'
    'o||"https://cdn.jsdelivr.net/npm/tesseract.js-core@v".concat(s.substring(1))' = 'o||"/js/ocr/vendor/tesseract-core-simd-lstm.wasm.js"'
    's||"https://cdn.jsdelivr.net/npm/@tesseract.js-data/".concat(i,m?"/4.0.0_best_int":"/4.0.0")' = 's||"/js/ocr/vendor"'
}
foreach ($file in Get-ChildItem -LiteralPath $outputFullPath -Recurse -File |
    Where-Object { $_.Extension -in @('.php', '.js', '.css') }) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    foreach ($url in $replacements.Keys) {
        $content = $content.Replace($url, $replacements[$url])
    }
    if ($content -match 'https?://(cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|fonts\.googleapis\.com)') {
        throw "Quedó una dependencia visual remota en $($file.FullName)."
    }
    [System.IO.File]::WriteAllText(
        $file.FullName,
        $content,
        (New-Object System.Text.UTF8Encoding($false))
    )
}

$syncEnabledValue = if ($EnableSync) { 'true' } else { 'false' }
$syncConfigSection = ''
if ($EnableSync) {
    $endpointLiteral = "'" + $syncEndpoint.Replace('\', '\\').Replace("'", "\'") + "'"
    $tokenLiteral = "'" + $syncToken.Replace('\', '\\').Replace("'", "\'") + "'"
    $syncConfigSection = @"
    'sync' => [
        'endpoint' => $endpointLiteral,
        'token' => $tokenLiteral,
    ],
"@
}
$portableConfig = @"
<?php

return [
    'db_driver' => 'sqlite',
    'app_url' => 'http://127.0.0.1',
    'offline_db_path' => dirname(__DIR__) . '/database/datos_locales.sqlite',
    'offline_sync_enabled' => $syncEnabledValue,
$syncConfigSection];
"@
[System.IO.File]::WriteAllText(
    (Join-Path $outputFullPath 'includes\config.local.php'),
    $portableConfig,
    (New-Object System.Text.UTF8Encoding($false))
)

$phpIni = @'
extension_dir = "ext"
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
extension=openssl
extension=curl
date.timezone = America/Santo_Domingo
default_charset = "UTF-8"
display_errors = Off
log_errors = On
error_log = "../logs/php-errors.log"
session.save_path = "../tmp"
session.use_strict_mode = 1
upload_tmp_dir = "../tmp"
max_execution_time = 120
memory_limit = 256M
post_max_size = 16M
upload_max_filesize = 16M
'@
[System.IO.File]::WriteAllText(
    (Join-Path $outputFullPath 'php\php.ini'),
    $phpIni,
    (New-Object System.Text.UTF8Encoding($false))
)

Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'portable\iniciar_portable.ps1') `
    -Destination (Join-Path $outputFullPath 'iniciar_portable.ps1')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'portable\iniciar_portable.vbs') `
    -Destination (Join-Path $outputFullPath 'iniciar_portable.vbs')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'portable\iniciar.bat') `
    -Destination (Join-Path $outputFullPath 'iniciar.bat')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'portable\router.php') `
    -Destination (Join-Path $outputFullPath 'router.php')

$syncReadme = if ($EnableSync) {
    'La sincronizacion con Alwaysdata esta habilitada para los cambios compatibles.'
} else {
    'Los cambios se guardan en database\datos_locales.sqlite y no se envian a Alwaysdata.'
}
$syncSecurityReadme = if ($EnableSync) {
    'El token de sincronizacion esta guardado en texto plano en includes\config.local.php.'
} else {
    'Esta copia no contiene un token de sincronizacion.'
}
$readme = @"
SISTEMA DE GESTION ESTUDIANTIL - VERSION PORTABLE

USO
1. Conecta el USB y abre la carpeta del sistema.
2. Ejecuta iniciar_portable.vbs para abrirlo sin mostrar una consola.
3. Usa el icono del sistema de estudiantes junto al reloj para abrirlo o salir.
4. Selecciona "Salir y cerrar servidor" antes de retirar el USB.

Esta copia usa una base SQLite local y no necesita XAMPP, MySQL ni Internet para
abrir la aplicacion. Los estilos, iconos y graficos estan incluidos localmente.
$syncReadme
Solo las cuentas administrativas y las funciones habilitadas para SQLite funcionan.
$syncSecurityReadme

PRIVACIDAD Y SEGURIDAD
La base contiene informacion personal y credenciales de usuarios. Protege el USB,
no compartas ni subas esta carpeta a servicios publicos y conserva una copia privada.
No abras la misma base simultaneamente en dos computadoras ni retires el USB mientras
el sistema este abierto. Antes de moverlo, selecciona "Salir y cerrar servidor".

El servidor web escucha solamente en 127.0.0.1 (este equipo). No permite acceso
desde otros dispositivos de la red. Esta carpeta incluye el runtime y sus licencias.
"@
[System.IO.File]::WriteAllText(
    (Join-Path $outputFullPath 'LEEME_PORTABLE.txt'),
    $readme,
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Output "Paquete portable creado en: $outputFullPath"
