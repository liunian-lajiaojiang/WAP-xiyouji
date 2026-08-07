@echo off
chcp 65001 >nul
echo ========================================
echo 启动西游记游戏服务器
echo ========================================
echo.

echo [1/2] 启动 PHP-CGI...
start "" "C:\BtSoft\php\85\php-cgi.exe" -b 127.0.0.1:20085 -T 1000
timeout /t 2 /nobreak >nul
echo PHP-CGI 已启动 (端口 20085)

echo.
echo [2/2] 检查 Nginx 状态...
sc query nginx | findstr "RUNNING" >nul
if %errorlevel% equ 0 (
    echo Nginx 已在运行
) else (
    echo 启动 Nginx...
    net start nginx
    if %errorlevel% neq 0 (
        echo 服务启动失败，尝试直接启动...
        start "" "C:\BtSoft\nginx\nginx.exe"
    )
)

echo.
echo ========================================
echo 服务器启动完成！
echo ========================================
echo.
echo 访问地址：
echo - 本地访问: http://127.0.0.1
echo - 局域网访问: http://192.168.1.57
echo.
echo 按任意键退出...
pause >nul
