@echo off
cd /d "%~dp0"

where php85 >nul 2>&1
if %errorlevel% equ 0 (
    php85 bin/mysql-schema-sync.php %*
    goto :eof
)

where php >nul 2>&1
if %errorlevel% equ 0 (
    php bin/mysql-schema-sync.php %*
    goto :eof
)

echo.
echo [ERROR] PHP not found. Please install PHP 8.5+ and add to PATH.
echo         Or use: php85 bin/mysql-schema-sync.php
echo.
pause
