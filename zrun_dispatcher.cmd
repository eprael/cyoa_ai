@echo off
:loop
echo Running AI Dispatcher...
C:\xampp\php\php.exe "O:\_school\Grade 12A (2025-2026)\WebTech10\public_html\projects\cyoa_ai\cron\ai_dispatcher.php"
echo Running AI Dispatcher...
timeout /t 20 /nobreak 
goto :loop

