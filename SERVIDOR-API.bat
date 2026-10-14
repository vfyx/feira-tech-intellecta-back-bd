@echo off
cd /d "%~dp0api"
"%~dp0runtime\php\php.exe" -c "%~dp0runtime\php\php.ini" -d "extension_dir=%~dp0runtime\php\ext" -S 127.0.0.1:8000 -t public
