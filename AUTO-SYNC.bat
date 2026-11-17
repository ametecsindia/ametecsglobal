@echo off
REM AmetecsGlobal - AUTOMATIC sync. Leave this window open (minimise it). Close it to stop.
REM Works only inside this folder and the AmetecsGlobal GitHub repository.
title AmetecsGlobal AUTO-SYNC
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0autosync.ps1"
pause
