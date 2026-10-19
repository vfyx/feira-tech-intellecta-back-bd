@echo off
cd /d "%~dp0"
start "Intellecta - API" cmd /k call "%~dp0SERVIDOR-API.bat"
start "Intellecta - Frontend" cmd /k call "%~dp0SERVIDOR-FRONTEND.bat"
echo Abra http://localhost:8080 quando os dois servidores iniciarem.
pause
