@echo off
REM ================================================================================
REM IPCR SYSTEM - EASY INSTALLATION SCRIPT
REM ================================================================================
REM This script will help you set up the IPCR system quickly
REM ================================================================================

color 0A
title IPCR System - Installation Wizard

echo.
echo ================================================================================
echo                   IPCR SYSTEM - INSTALLATION WIZARD
echo ================================================================================
echo.
echo This wizard will help you set up the IPCR Management System
echo.
pause

REM Check if XAMPP is installed
echo.
echo [1/4] Checking XAMPP installation...
if not exist "C:\xampp\mysql\bin\mysql.exe" (
    color 0C
    echo.
    echo ERROR: XAMPP not found!
    echo.
    echo Please install XAMPP first from: https://www.apachefriends.org/
    echo After installation, run this script again.
    echo.
    pause
    exit
)
echo     XAMPP found! [OK]

REM Check if MySQL is running
echo.
echo [2/4] Checking MySQL service...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo     MySQL is running! [OK]
) else (
    color 0E
    echo     MySQL is NOT running!
    echo.
    echo     Please start MySQL from XAMPP Control Panel, then run this script again.
    echo.
    pause
    exit
)

REM Check if database file exists
echo.
echo [3/4] Checking database file...
if not exist "database\COMPLETE_DATABASE.sql" (
    color 0C
    echo.
    echo ERROR: Database file not found!
    echo Expected location: database\COMPLETE_DATABASE.sql
    echo.
    pause
    exit
)
echo     Database file found! [OK]

REM Import database
echo.
echo [4/4] Importing database...
echo.
echo     This may take a few seconds...
echo.

cd database
"C:\xampp\mysql\bin\mysql.exe" -u root < COMPLETE_DATABASE.sql 2>error.log

if %ERRORLEVEL% EQU 0 (
    color 0A
    echo     Database imported successfully! [OK]
    if exist error.log del error.log
) else (
    color 0C
    echo.
    echo ERROR: Database import failed!
    echo.
    if exist error.log (
        echo Error details:
        type error.log
        echo.
    )
    echo.
    echo Try importing manually using phpMyAdmin:
    echo 1. Open: http://localhost/phpmyadmin
    echo 2. Click Import
    echo 3. Choose: database\COMPLETE_DATABASE.sql
    echo 4. Click Go
    echo.
    pause
    exit
)

cd ..

REM Success!
cls
color 0A
echo.
echo ================================================================================
echo                          INSTALLATION COMPLETE!
echo ================================================================================
echo.
echo  Your IPCR system is now ready to use!
echo.
echo  Access the system:
echo  URL: http://localhost/ipcr-system
echo.
echo  Login credentials:
echo  Username: ADMIN001
echo  Password: Admin@2024
echo.
echo  What's included:
echo  - 1 Admin account
echo  - 1 Dean account  
echo  - 10 Faculty accounts
echo  - 9 Degree programs
echo  - Complete database
echo.
echo  IMPORTANT: Change all default passwords after first login!
echo.
echo ================================================================================
echo.
echo  Press any key to open the system in your browser...
echo.
pause >nul

REM Open browser
start http://localhost/ipcr-system

echo.
echo  Browser opened! You can close this window now.
echo.
timeout /t 3 >nul

exit
