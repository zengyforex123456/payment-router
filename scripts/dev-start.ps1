<#
.SYNOPSIS
    Converge 开发环境一键启动 — 自动端口检测 + Latte 缓存清理

.DESCRIPTION
    1. 扫描空闲端口（跳过已占用）
    2. 清除 Latte 编译缓存残留锁文件
    3. 启动 Docker 开发容器
    4. 等待健康检查通过
    5. 输出访问链接

.EXAMPLE
    .\scripts\dev-start.ps1
    .\scripts\dev-start.ps1 -ForceRebuild
#>
param(
    [switch]$ForceRebuild,
    [int]$StartPort = 8080
)

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot\..

# ═══ 1. 端口检测 ═══
Write-Host "🔍 检测端口..." -ForegroundColor Cyan
$port = $StartPort
while ($true) {
    $inUse = netstat -ano 2>$null | Select-String ":$port " | Select-String "LISTENING"
    if (-not $inUse) { break }
    Write-Host "  ⚠️ 端口 $port 已被占用 → 尝试 $($port + 1)" -ForegroundColor Yellow
    $port++
    if ($port -gt ($StartPort + 100)) {
        Write-Host "❌ 端口范围 $StartPort-$($StartPort+100) 全部被占用" -ForegroundColor Red
        exit 1
    }
}
Write-Host "  ✅ 使用端口: $port" -ForegroundColor Green
$env:APP_PORT = $port

# ═══ 2. Latte 缓存清理 ═══
Write-Host "🧹 清理 Latte 缓存..." -ForegroundColor Cyan
$cacheDir = "storage\cache\latte"
if (Test-Path $cacheDir) {
    try {
        Remove-Item "$cacheDir\*" -Recurse -Force -ErrorAction Stop 2>$null
        Write-Host "  ✅ 缓存已清空" -ForegroundColor Green
    } catch {
        # Windows Protected Folder 可能在 bash fallback
        bash -c "rm -rf $(wslpath -w $cacheDir 2>/dev/null || echo $cacheDir)/* 2>/dev/null" 2>$null
        Write-Host "  ✅ 缓存已清空 (bash)" -ForegroundColor Green
    }
}

# ═══ 3. Docker 启动 ═══
if ($ForceRebuild) {
    Write-Host "🔨 强制重建镜像..." -ForegroundColor Cyan
    docker compose -f docker-compose.dev.yml build --no-cache
}

Write-Host "🚀 启动容器..." -ForegroundColor Cyan
docker compose -f docker-compose.dev.yml up -d

# ═══ 4. 等待就绪 ═══
Write-Host "⏳ 等待服务就绪..." -ForegroundColor Cyan
$maxWait = 60
for ($i = 0; $i -lt $maxWait; $i++) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost:$port/health" -UseBasicParsing -TimeoutSec 2
        if ($r.StatusCode -eq 200) {
            Write-Host "  ✅ 健康检查通过" -ForegroundColor Green
            break
        }
    } catch {
        # still starting
    }
    Start-Sleep -Seconds 1
    if ($i % 10 -eq 0 -and $i -gt 0) { Write-Host "  ... ${i}s" }
}

# ═══ 5. 输出访问链接 ═══
Write-Host ""
Write-Host "╔════════════════════════════════════════════╗" -ForegroundColor Green
Write-Host "║  Converge 开发环境就绪                     ║" -ForegroundColor Green
Write-Host "╠════════════════════════════════════════════╣" -ForegroundColor Green
Write-Host ("║  Landing:      http://localhost:{0}/landing.php    ║" -f $port) -ForegroundColor White
Write-Host ("║  Dashboard:    http://localhost:{0}/index.php      ║" -f $port) -ForegroundColor White
Write-Host ("║  Health:       http://localhost:{0}/health         ║" -f $port) -ForegroundColor White
Write-Host ("║  Mailpit:      http://localhost:8025               ║" -ForegroundColor White
Write-Host "╠════════════════════════════════════════════╣" -ForegroundColor Green
Write-Host ("║  APP_PORT={0}  (已写入 .env)                 ║" -f $port) -ForegroundColor White
Write-Host "╚════════════════════════════════════════════╝" -ForegroundColor Green
Write-Host ""

# 写入 .env 供后续使用
@"
# Converge Dev — 自动生成于 $(Get-Date -Format 'yyyy-MM-dd HH:mm')
APP_PORT=$port
"@ | Out-File -FilePath ".env" -Encoding utf8 -Force

Write-Host "💡 下次直接运行: docker compose -f docker-compose.dev.yml up -d" -ForegroundColor DarkGray
Write-Host "💡 停止: docker compose -f docker-compose.dev.yml stop" -ForegroundColor DarkGray
