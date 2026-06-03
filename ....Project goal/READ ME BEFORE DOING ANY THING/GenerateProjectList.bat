@echo off
setlocal enabledelayedexpansion

set "TARGET=C:\xampp\htdocs\wealthdash"
set "OUTPUT=C:\xampp\htdocs\wealthdash\zzProjectList.txt"

if not exist "%TARGET%" (
    echo ERROR: Folder nahi mila: %TARGET%
    pause
    exit /b
)

echo Generating project list...

(
echo WealthDash - Project File List
echo Generated: %date% %time%
echo Location: %TARGET%
echo =====================================
echo.
dir "%TARGET%" /s /b /a:-h
) > "%OUTPUT%"

echo.
echo DONE! File saved at:
echo %OUTPUT%
timeout /t 3 >nul
