@echo off
cd /d "%~dp0"
cd ..
C:\xampp\php\php.exe cli/create_stories.php --email=bprael@hotmail.com --file=cli/stories_5c.json
