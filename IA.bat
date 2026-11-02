@echo off
cd /d "%~dp0api"
"%~dp0runtime\php\php.exe" -c "%~dp0runtime\php\php.ini" -d "extension_dir=%~dp0runtime\php\ext" artisan %*
