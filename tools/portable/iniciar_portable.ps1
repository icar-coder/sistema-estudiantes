$ErrorActionPreference = 'Stop'
$appRoot = $PSScriptRoot
$phpDirectory = Join-Path $appRoot 'php'
$phpExecutable = Join-Path $phpDirectory 'php.exe'
$phpIni = Join-Path $phpDirectory 'php.ini'
$caBundle = Join-Path $phpDirectory 'curl-ca-bundle.crt'
$database = Join-Path $appRoot 'database\datos_locales.sqlite'
$router = Join-Path $appRoot 'router.php'
$sessionDirectory = Join-Path $appRoot 'tmp'

New-Item -ItemType Directory -Path $sessionDirectory -Force | Out-Null

foreach ($requiredFile in @($phpExecutable, $phpIni, $database, $router)) {
    if (!(Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "Falta un archivo necesario para iniciar el sistema: $requiredFile"
    }
}

Push-Location $phpDirectory
try {
    $modules = & $phpExecutable -c $phpIni -m
    if ($LASTEXITCODE -ne 0) {
        throw 'El runtime PHP no pudo cargar las extensiones requeridas.'
    }
    foreach ($extension in @('pdo_sqlite', 'sqlite3', 'mbstring', 'openssl', 'curl')) {
        if ($modules -notcontains $extension) {
            throw "Falta la extensión PHP requerida: $extension."
        }
    }
} finally {
    Pop-Location
}

$listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
$url = "http://127.0.0.1:$port/"
$stdout = Join-Path $appRoot 'logs\servidor-salida.log'
$stderr = Join-Path $appRoot 'logs\servidor-errores.log'
$server = $null

$phpArguments = @(
    '-c',
    ('"' + $phpIni + '"'),
    '-d',
    ('"session.save_path=' + $sessionDirectory + '"')
)
if (Test-Path -LiteralPath $caBundle -PathType Leaf) {
    $phpArguments += @('-d', ('"curl.cainfo=' + $caBundle + '"'))
}
$phpArguments += @(
    '-S',
    "127.0.0.1:$port",
    '-t',
    ('"' + $appRoot + '"'),
    ('"' + $router + '"')
)

try {
    $server = Start-Process -FilePath $phpExecutable `
        -ArgumentList $phpArguments `
        -WorkingDirectory $phpDirectory `
        -PassThru `
        -NoNewWindow `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr

    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($server.HasExited) {
            throw "El servidor PHP se cerró. Revisa logs\servidor-errores.log."
        }
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $client.Connect([System.Net.IPAddress]::Loopback, $port)
            $ready = $true
            break
        } catch {
            Start-Sleep -Milliseconds 250
        } finally {
            $client.Dispose()
        }
    }

    if (!$ready) {
        throw 'No se pudo iniciar el servidor local en un puerto disponible.'
    }

    Write-Host "Sistema abierto en $url"
    Write-Host 'Para cerrar el servidor y retirar el USB de forma segura, presiona Ctrl+C.'
    Start-Process $url
    $server.WaitForExit()
} finally {
    if ($null -ne $server -and !$server.HasExited) {
        Stop-Process -Id $server.Id
    }
}
