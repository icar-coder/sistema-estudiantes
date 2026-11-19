$ErrorActionPreference = 'Stop'
$appRoot = $PSScriptRoot
$phpDirectory = Join-Path $appRoot 'php'
$phpExecutable = Join-Path $phpDirectory 'php.exe'
$phpIni = Join-Path $phpDirectory 'php.ini'
$caBundle = Join-Path $phpDirectory 'curl-ca-bundle.crt'
$database = Join-Path $appRoot 'database\datos_locales.sqlite'
$router = Join-Path $appRoot 'router.php'
$sessionDirectory = Join-Path $appRoot 'tmp'
$logsDirectory = Join-Path $appRoot 'logs'

function Show-PortableStartupError {
    param([string]$Message)

    try {
        New-Item -ItemType Directory -Path $logsDirectory -Force | Out-Null
        Add-Content -LiteralPath (Join-Path $logsDirectory 'inicio-portable.log') -Value (
            '[' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '] ' + $Message
        )
    } catch {
    }

    try {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show(
            "No se pudo iniciar el sistema.`n$Message`n`nRevisa logs\inicio-portable.log.",
            'Sistema de Estudiantes',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    } catch {
    }
}

try {
    New-Item -ItemType Directory -Path $sessionDirectory -Force | Out-Null
    New-Item -ItemType Directory -Path $logsDirectory -Force | Out-Null

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
} catch {
    Show-PortableStartupError -Message $_.Exception.Message
    exit 1
}

$script:server = $null
$script:notifyIcon = $null
$script:healthTimer = $null
$script:appUrl = $null
$listener = $null
$stdout = Join-Path $appRoot 'logs\servidor-salida.log'
$stderr = Join-Path $appRoot 'logs\servidor-errores.log'

try {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $port = $listener.LocalEndpoint.Port
    $listener.Stop()
    $script:appUrl = "http://127.0.0.1:$port/"

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

    $script:server = Start-Process -FilePath $phpExecutable `
        -ArgumentList $phpArguments `
        -WorkingDirectory $phpDirectory `
        -PassThru `
        -NoNewWindow `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr

    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($script:server.HasExited) {
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

    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()

    $contextMenu = New-Object System.Windows.Forms.ContextMenuStrip
    $openItem = $contextMenu.Items.Add('Abrir sistema')
    $separator = New-Object System.Windows.Forms.ToolStripSeparator
    $null = $contextMenu.Items.Add($separator)
    $closeItem = $contextMenu.Items.Add('Salir y cerrar servidor')

    $script:notifyIcon = New-Object System.Windows.Forms.NotifyIcon
    $script:notifyIcon.Icon = [System.Drawing.SystemIcons]::Application
    $script:notifyIcon.Text = 'Sistema de Estudiantes'
    $script:notifyIcon.ContextMenuStrip = $contextMenu
    $script:notifyIcon.Visible = $true

    $openAction = {
        try {
            Start-Process -FilePath $script:appUrl
        } catch {
            [System.Windows.Forms.MessageBox]::Show(
                "No se pudo abrir el sistema en el navegador.`n$($_.Exception.Message)",
                'Sistema de Estudiantes',
                [System.Windows.Forms.MessageBoxButtons]::OK,
                [System.Windows.Forms.MessageBoxIcon]::Error
            ) | Out-Null
        }
    }
    $openItem.add_Click($openAction)
    $script:notifyIcon.add_DoubleClick($openAction)

    $closeItem.add_Click({
        try {
            if ($null -ne $script:server -and !$script:server.HasExited) {
                Stop-Process -Id $script:server.Id -ErrorAction Stop
                if (!$script:server.WaitForExit(5000)) {
                    throw 'El servidor no se cerró dentro del tiempo esperado.'
                }
            }
            [System.Windows.Forms.Application]::Exit()
        } catch {
            [System.Windows.Forms.MessageBox]::Show(
                "No se pudo cerrar el servidor de forma segura.`n$($_.Exception.Message)",
                'Sistema de Estudiantes',
                [System.Windows.Forms.MessageBoxButtons]::OK,
                [System.Windows.Forms.MessageBoxIcon]::Error
            ) | Out-Null
        }
    })

    $script:healthTimer = New-Object System.Windows.Forms.Timer
    $script:healthTimer.Interval = 1000
    $script:healthTimer.add_Tick({
        if ($null -eq $script:server -or $script:server.HasExited) {
            $script:healthTimer.Stop()
            $script:notifyIcon.ShowBalloonTip(
                5000,
                'Sistema de Estudiantes',
                'El servidor se detuvo. Revisa logs\servidor-errores.log si no fue intencional.',
                [System.Windows.Forms.ToolTipIcon]::Warning
            )
            [System.Windows.Forms.Application]::Exit()
        }
    })
    $script:healthTimer.Start()

    $script:notifyIcon.ShowBalloonTip(
        3000,
        'Sistema de Estudiantes',
        'El sistema está listo. Usa el ícono de la bandeja para abrirlo o cerrarlo.',
        [System.Windows.Forms.ToolTipIcon]::Info
    )
    Start-Process -FilePath $script:appUrl
    [System.Windows.Forms.Application]::Run()
} catch {
    Show-PortableStartupError -Message $_.Exception.Message
    exit 1
} finally {
    if ($null -ne $listener) {
        try {
            $listener.Stop()
        } catch {
        }
    }
    if ($null -ne $script:healthTimer) {
        $script:healthTimer.Stop()
        $script:healthTimer.Dispose()
    }
    if ($null -ne $script:notifyIcon) {
        $script:notifyIcon.Visible = $false
        $script:notifyIcon.Dispose()
    }
    if ($null -ne $script:server -and !$script:server.HasExited) {
        Stop-Process -Id $script:server.Id -ErrorAction SilentlyContinue
        $script:server.WaitForExit(5000) | Out-Null
    }
}
