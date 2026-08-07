<#
.SYNOPSIS
    一键修复执行策略 + 提交全部文件 + 强制推送到远程仓库
.DESCRIPTION
    适用于个人仓库（无其他协作者）。使用裸 --force 完全强制覆盖远程历史，不可恢复。
.NOTES
    首次运行：以管理员身份打开 PowerShell，执行此脚本即可。
    脚本会自动把 ExecutionPolicy 设为 CurrentUser 范围的 RemoteSigned。
#>

#==========================================================
# 0. 自提权：若未以管理员身份运行，自动请求提升
#==========================================================
$here = $MyInvocation.MyCommand.Path
if (-not ([Security.Principal.WindowsPrincipal] `
        [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "未以管理员身份运行，正在请求提升..." -ForegroundColor Yellow
    Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoExit -ExecutionPolicy Bypass -File `"$here`""
    exit
}

#==========================================================
# 1. 修复 PowerShell 执行策略（CurrentUser 范围，足够且安全）
#==========================================================
Write-Host "`n=== [1/5] 修复 PowerShell 执行策略 ===" -ForegroundColor Cyan
try {
    $current = Get-ExecutionPolicy -Scope CurrentUser
    Write-Host "当前 CurrentUser 策略: $current"
    if ($current -ne 'RemoteSigned') {
        Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser -Force
        Write-Host "已设置为 RemoteSigned (CurrentUser)" -ForegroundColor Green
    } else {
        Write-Host "策略已正确，无需修改" -ForegroundColor Green
    }
} catch {
    Write-Host "修改执行策略失败: $_" -ForegroundColor Red
    Write-Host "请手动运行: Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser -Force" -ForegroundColor Yellow
    pause; exit 1
}

#==========================================================
# 2. 切换到脚本所在目录（即仓库目录）
#==========================================================
Write-Host "`n=== [2/5] 切换到仓库目录 ===" -ForegroundColor Cyan
$repoDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $repoDir
Write-Host "工作目录: $repoDir"

if (-not (Test-Path ".git")) {
    Write-Host "当前目录不是 git 仓库！请把脚本放到仓库根目录下。" -ForegroundColor Red
    pause; exit 1
}

#==========================================================
# 3. 添加并提交全部改动
#==========================================================
Write-Host "`n=== [3/5] 添加并提交全部改动 ===" -ForegroundColor Cyan

# 检查是否有改动
$status = git status --porcelain
if (-not $status) {
    Write-Host "没有未提交的改动，直接尝试推送..." -ForegroundColor Yellow
} else {
    Write-Host "改动内容：" -ForegroundColor DarkGray
    git status --short
    Write-Host ""

    # 添加所有文件（包括新增、修改、删除）
    git add -A
    if ($LASTEXITCODE -ne 0) {
        Write-Host "git add 失败" -ForegroundColor Red
        pause; exit 1
    }

    # 生成带时间戳的提交信息
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $commitMsg = "覆盖性提交 @ $timestamp"
    git commit -m $commitMsg
    if ($LASTEXITCODE -ne 0) {
        Write-Host "git commit 失败（可能没有改动可提交）" -ForegroundColor Yellow
    } else {
        Write-Host "提交成功: $commitMsg" -ForegroundColor Green
    }
}

#==========================================================
# 4. 确认分支与远程
#==========================================================
Write-Host "`n=== [4/5] 确认分支与远程 ===" -ForegroundColor Cyan

# 获取当前分支名
$branch = git rev-parse --abbrev-ref HEAD
Write-Host "当前分支: $branch"

# 获取远程仓库名（默认 origin）
$remote = git remote
if (-not $remote) {
    Write-Host "未配置任何远程仓库！请先 git remote add origin <url>" -ForegroundColor Red
    pause; exit 1
}
$remoteName = ($remote -split "`n")[0].Trim()
Write-Host "远程仓库: $remoteName"

# 检查远程是否可达
Write-Host "`n即将执行的推送命令：" -ForegroundColor DarkGray
Write-Host "  git push --force $remoteName $branch" -ForegroundColor DarkYellow
Write-Host "`n警告: 这将覆盖远程 $branch 分支的历史，不可恢复！" -ForegroundColor Red

#==========================================================
# 5. 强制推送
#==========================================================
Write-Host "`n=== [5/5] 强制推送 ===" -ForegroundColor Cyan

# 使用裸 --force：完全强制覆盖远程，不检查远程是否有未知更新
git push --force $remoteName $branch

if ($LASTEXITCODE -eq 0) {
    Write-Host "`n==========================================" -ForegroundColor Green
    Write-Host "  推送成功！远程已与本地同步" -ForegroundColor Green
    Write-Host "==========================================" -ForegroundColor Green
} else {
    Write-Host "`n==========================================" -ForegroundColor Red
    Write-Host "  推送失败！" -ForegroundColor Red
    Write-Host "==========================================" -ForegroundColor Red
    Write-Host "`n可能原因：" -ForegroundColor Yellow
    Write-Host "  1. 未配置上游分支 → 先执行: git push -u $remoteName $branch"
    Write-Host "  2. 认证失败 → 检查 GitHub/Gitee 的 token 或 SSH key"
    Write-Host "  3. 网络问题 → 检查网络连接"
}

Write-Host "`n最终状态：" -ForegroundColor Cyan
git log --oneline -5
git status

Write-Host "`n按任意键退出..." -ForegroundColor DarkGray
pause
