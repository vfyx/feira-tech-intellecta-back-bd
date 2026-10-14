@echo off
cd /d "%~dp0"
docker compose -f compose.yaml stop
echo Banco parado. Os dados foram preservados. Feche tambem as janelas dos servidores PHP.
pause
