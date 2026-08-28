# start-dev.ps1 - Sobe API, worker de filas e APP no Windows.
#
# Equivalente ao start-dev.sh (Linux/macOS). A diferença principal está no
# encerramento: no Windows, matar o processo do "npm" não mata o "vite" que ele
# criou -- o neto sobrevive e continua segurando a porta 3000. Por isso aqui a
# árvore de processos é coletada ANTES de qualquer kill e derrubada inteira.
#
# Uso:  .\start-dev.ps1        (Ctrl+C encerra tudo)

$ErrorActionPreference = 'Stop'

$Root     = $PSScriptRoot
$ApiPath  = Join-Path $Root 'API'
$AppPath  = Join-Path $Root 'APP'
$ApiPort  = 8000
$AppPort  = 3000

# Processos iniciados por este script: @{ Label; Process; Critical; Notified }
$Started = @()
$CleanedUp = $false

function Write-Step { param([string]$Text) Write-Host $Text -ForegroundColor Cyan }
function Write-Ok   { param([string]$Text) Write-Host $Text -ForegroundColor Green }
function Write-Warn { param([string]$Text) Write-Host $Text -ForegroundColor Yellow }
function Write-Fail { param([string]$Text) Write-Host $Text -ForegroundColor Red }

# ---------------------------------------------------------------------------
# Árvore de processos
# ---------------------------------------------------------------------------

# Todos os descendentes de um PID, em profundidade. Precisa ser chamada
# enquanto a árvore ainda está viva: depois que o pai morre, a ligação
# ParentProcessId se perde e os netos viram órfãos impossíveis de rastrear.
function Get-DescendantPids {
    param([int]$ParentId)

    $found = @()
    try {
        $children = Get-CimInstance Win32_Process -Filter "ParentProcessId=$ParentId" -ErrorAction Stop
    } catch {
        return $found
    }
    foreach ($child in $children) {
        $childId = [int]$child.ProcessId
        $found += $childId
        $found += Get-DescendantPids -ParentId $childId
    }
    return $found
}

function Stop-ProcessTree {
    param([int]$RootId, [string]$Label)

    if (-not $RootId) { return }

    # Coleta antes de matar, senão os netos se perdem.
    $targets = @()
    $targets += Get-DescendantPids -ParentId $RootId
    $targets += $RootId

    # taskkill /T como primeira tentativa (o redirecionamento roda dentro do
    # cmd para que o PowerShell não trate a saída de erro do taskkill como
    # falha do script).
    cmd /c "taskkill /PID $RootId /T /F >nul 2>&1"

    # Filhos primeiro, pai por último.
    foreach ($procId in $targets) {
        try { Stop-Process -Id $procId -Force -ErrorAction Stop } catch { }
    }

    # Segunda passada: quem tiver demorado a morrer.
    Start-Sleep -Milliseconds 300
    foreach ($procId in $targets) {
        $alive = Get-Process -Id $procId -ErrorAction SilentlyContinue
        if ($alive) {
            try { Stop-Process -Id $procId -Force -ErrorAction Stop } catch { }
        }
    }

    if ($Label) { Write-Host "   $Label encerrado" -ForegroundColor DarkGray }
}

function Invoke-Cleanup {
    if ($script:CleanedUp) { return }
    $script:CleanedUp = $true

    Write-Host ''
    Write-Warn 'Parando servidores...'

    # Ordem inversa: APP, worker, API.
    for ($i = $script:Started.Count - 1; $i -ge 0; $i--) {
        $entry = $script:Started[$i]
        if ($entry.Process) {
            Stop-ProcessTree -RootId $entry.Process.Id -Label $entry.Label
        }
    }

    try { [Console]::TreatControlCAsInput = $false } catch { }
    Write-Ok 'Servidores parados'
}

# ---------------------------------------------------------------------------
# Pré-requisitos
# ---------------------------------------------------------------------------

# Resolve um executável de verdade para o Start-Process. Necessário porque
# "Get-Command npm" devolve o npm.ps1 primeiro, e o Start-Process não consegue
# executar um .ps1 nem o "npm" sem extensão (o shell script Unix): ambos falham
# com "%1 não é um aplicativo Win32 válido". Só .cmd/.bat/.exe servem.
function Test-Command {
    param([string]$Name)

    $candidates = @(Get-Command $Name -All -ErrorAction SilentlyContinue |
                    Where-Object { $_.CommandType -eq 'Application' })

    foreach ($ext in @('.cmd', '.bat', '.exe')) {
        foreach ($candidate in $candidates) {
            if ([System.IO.Path]::GetExtension($candidate.Source).ToLower() -eq $ext) {
                return $candidate.Source
            }
        }
    }
    return $null
}

function Test-PortInUse {
    param([int]$Port)
    try {
        $conns = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction Stop
        if ($conns) { return $conns }
    } catch { }
    return $null
}

Write-Host ''
Write-Host 'Iniciando servidores de desenvolvimento...' -ForegroundColor Blue
Write-Host ''

$phpPath = Test-Command 'php'
if (-not $phpPath) {
    Write-Fail 'PHP não encontrado no PATH. Instale o PHP 8.2+ e tente de novo.'
    exit 1
}

$npmPath = Test-Command 'npm'
if (-not $npmPath) {
    Write-Fail 'npm não encontrado no PATH. Instale o Node.js 18+ e tente de novo.'
    exit 1
}

$missing = @()
if (-not (Test-Path (Join-Path $ApiPath '.env')))         { $missing += 'API/.env (copie de API/.env.example e rode: php artisan key:generate)' }
if (-not (Test-Path (Join-Path $ApiPath 'vendor')))       { $missing += 'API/vendor (rode: composer install)' }
if (-not (Test-Path (Join-Path $AppPath 'node_modules'))) { $missing += 'APP/node_modules (rode: npm install)' }

if ($missing.Count -gt 0) {
    Write-Fail 'Configuração incompleta:'
    foreach ($item in $missing) { Write-Host "   - $item" -ForegroundColor Red }
    Write-Host ''
    Write-Host 'Veja a seção "Instalação" do README.md.' -ForegroundColor Yellow
    exit 1
}

# SQLite: o arquivo do banco precisa existir antes das migrations.
$envFile = Join-Path $ApiPath '.env'
$usesSqlite = Select-String -Path $envFile -Pattern '^\s*DB_CONNECTION\s*=\s*sqlite' -Quiet -ErrorAction SilentlyContinue
if ($usesSqlite) {
    $sqliteFile = Join-Path $ApiPath 'database\database.sqlite'
    if (-not (Test-Path $sqliteFile)) {
        Write-Warn 'Banco SQLite não encontrado. Criando database/database.sqlite...'
        New-Item -ItemType File -Path $sqliteFile | Out-Null
        Write-Warn "Rode 'php artisan migrate' dentro de API para criar as tabelas."
    }
}

# Aviso (não bloqueia): outra coisa já escutando nas portas.
foreach ($check in @(@{ Port = $ApiPort; Nome = 'API' }, @{ Port = $AppPort; Nome = 'APP' })) {
    $busy = Test-PortInUse -Port $check.Port
    if ($busy) {
        $ownerId = ($busy | Select-Object -First 1).OwningProcess
        $owner = Get-Process -Id $ownerId -ErrorAction SilentlyContinue
        $ownerName = 'processo desconhecido'
        if ($owner) { $ownerName = "$($owner.ProcessName) (PID $ownerId)" }
        Write-Warn "Porta $($check.Port) já está em uso por $ownerName - o $($check.Nome) pode não subir nela."
    }
}

# ---------------------------------------------------------------------------
# Sobe os serviços
# ---------------------------------------------------------------------------

function Start-DevService {
    param(
        [string]$Label,
        [string]$FilePath,
        [string[]]$Arguments,
        [string]$WorkingDirectory,
        [bool]$Critical
    )

    $proc = Start-Process -FilePath $FilePath -ArgumentList $Arguments `
                          -WorkingDirectory $WorkingDirectory `
                          -NoNewWindow -PassThru

    $script:Started += @{ Label = $Label; Process = $proc; Critical = $Critical; Notified = $false }
    return $proc
}

try {
    Write-Step "Iniciando API Laravel (porta $ApiPort)..."
    $api = Start-DevService -Label 'API Laravel' -FilePath $phpPath `
                            -Arguments @('artisan', 'serve', '--host=127.0.0.1', "--port=$ApiPort") `
                            -WorkingDirectory $ApiPath -Critical $true
    Start-Sleep -Seconds 2

    if ($api.HasExited) {
        Write-Fail 'Erro ao iniciar a API Laravel'
        Invoke-Cleanup
        exit 1
    }

    Write-Step 'Iniciando worker de filas...'
    $queue = Start-DevService -Label 'Worker de filas' -FilePath $phpPath `
                              -Arguments @('artisan', 'queue:work', '--tries=3') `
                              -WorkingDirectory $ApiPath -Critical $false
    Start-Sleep -Seconds 1

    if ($queue.HasExited) {
        Write-Warn 'Worker de filas não iniciou (verifique QUEUE_CONNECTION no .env)'
    }

    Write-Step "Iniciando APP Vue.js (porta $AppPort)..."
    $app = Start-DevService -Label 'APP Vue.js' -FilePath $npmPath `
                            -Arguments @('run', 'dev') `
                            -WorkingDirectory $AppPath -Critical $true
    Start-Sleep -Seconds 3

    if ($app.HasExited) {
        Write-Fail 'Erro ao iniciar o APP Vue.js'
        Invoke-Cleanup
        exit 1
    }

    Write-Host ''
    Write-Host '========================================' -ForegroundColor Blue
    Write-Ok   'Servidores iniciados com sucesso!'
    Write-Host "   API:   http://localhost:$ApiPort" -ForegroundColor Cyan
    Write-Host '   Filas: worker ativo (importação de faturas)' -ForegroundColor Cyan
    Write-Host "   APP:   http://localhost:$AppPort" -ForegroundColor Magenta
    Write-Host '========================================' -ForegroundColor Blue
    Write-Host ''
    Write-Warn 'Pressione Ctrl+C para parar os servidores'
    Write-Host ''

    # Ctrl+C vira input comum para que o cleanup rode por completo antes da
    # saída. Sem isso o PowerShell pode encerrar o script deixando netos vivos.
    $interactive = $false
    try {
        if (-not [Console]::IsInputRedirected) {
            [Console]::TreatControlCAsInput = $true
            $interactive = $true
        }
    } catch { }

    while ($true) {
        if ($interactive -and [Console]::KeyAvailable) {
            $key = [Console]::ReadKey($true)
            if (($key.Modifiers -band [ConsoleModifiers]::Control) -and ($key.Key -eq 'C')) {
                break
            }
        }

        foreach ($entry in $Started) {
            if ($entry.Process -and $entry.Process.HasExited) {
                if ($entry.Critical) {
                    Write-Fail "$($entry.Label) parou inesperadamente"
                    Invoke-Cleanup
                    exit 1
                }
                elseif (-not $entry.Notified) {
                    $entry.Notified = $true
                    Write-Warn "$($entry.Label) parou"
                }
            }
        }

        Start-Sleep -Milliseconds 500
    }
}
finally {
    # Rede de segurança: fechar a janela ou um erro inesperado também limpa.
    Invoke-Cleanup
}
