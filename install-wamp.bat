@echo off
REM One-click setup for WAMP. Place this folder at <wamp>\www\e-waste (e.g. E:\Wamp\www\e-waste),
REM make sure WAMP is running (green icon), then double-click this file.
setlocal EnableDelayedExpansion
cd /d "%~dp0"
set "WAMP=%~dp0..\..\"

set "PHP="
for /d %%D in ("%WAMP%bin\php\php*") do if exist "%%D\php.exe" set "PHP=%%D\php.exe"
if not defined PHP ( echo Could not find PHP under %WAMP%bin\php & pause & exit /b 1 )

set "MYSQL="
for /d %%D in ("%WAMP%bin\mysql\mysql*") do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
for /d %%D in ("%WAMP%bin\mariadb\mariadb*") do if exist "%%D\bin\mysql.exe" set "MYSQL=%%D\bin\mysql.exe"
if not defined MYSQL ( echo Could not find mysql.exe under %WAMP%bin. Create the database "swm" in phpMyAdmin instead, then re-run. & pause & exit /b 1 )

echo Using PHP:   %PHP%
echo Using MySQL: %MYSQL%
"%PHP%" -r "exit(version_compare(PHP_VERSION,'8.1.0','>=')?0:1);"
if errorlevel 1 ( echo This app needs PHP 8.1 or newer. In WAMP: tray icon - PHP - Version. Then re-run. & pause & exit /b 1 )

if not exist "src\config.local.php" (
  > "src\config.local.php" echo ^<?php
  >> "src\config.local.php" echo return ['db_host'=^>'127.0.0.1','db_name'=^>'swm','db_user'=^>'root','db_pass'=^>'']; 
)

"%MYSQL%" -uroot -e "CREATE DATABASE IF NOT EXISTS swm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 ( echo Could not connect to MySQL as root. Is WAMP running ^(green icon^)? & pause & exit /b 1 )

set /p ADMIN_PASSWORD=Choose a password for the State Admin (min 8 characters): 
"%PHP%" database\install.php
if errorlevel 1 ( echo Setup failed - see the message above. & pause & exit /b 1 )

echo.
echo Done. Open http://localhost/e-waste/public/  and log in with mobile 9999999999 and the password you just typed.
pause
