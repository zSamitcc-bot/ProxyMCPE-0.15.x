@echo off
title Kuoto Proxy

set "PHP_BIN=%~dp0bin\php\php.exe"
set "MINTTY=%~dp0bin\mintty.exe"
set "ICON=%~dp0bin\pocketmine.ico"
set "SERVER_FILE=%~dp0server.php"

if not exist "%PHP_BIN%" (
    echo [ERROR] PHP not found at: %PHP_BIN%
    pause
    exit /b 1
)

if exist "%MINTTY%" (
    start "Kuoto Proxy" "%MINTTY%" --hold error -o Columns=100 -o Rows=32 -o Font="DejaVu Sans Mono" -o FontHeight=10 -o CursorType=0 -t "Kuoto Proxy" -i "%ICON%" "%PHP_BIN%" "%SERVER_FILE%" --enable-ansi %*
) else (
    "%PHP_BIN%" "%SERVER_FILE%" --enable-ansi %*
    pause
)
