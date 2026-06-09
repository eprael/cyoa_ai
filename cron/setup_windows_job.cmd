@echo off
cd /d "%~dp0"
schtasks /create /tn "CYOA_AI_Dispatcher" /tr "C:\xampp\php\php.exe \"%cd%\ai_dispatcher.php\"" /sc minute /mo 1