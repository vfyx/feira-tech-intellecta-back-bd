@echo off
cd /d "%~dp0frontend"
set "INTELLECTA_API_URL=http://127.0.0.1:8000/api"
"%~dp0runtime\php\php.exe" -c "%~dp0runtime\php\php.ini" -d "extension_dir=%~dp0runtime\php\ext" -S 127.0.0.1:8080 -t public router.php
