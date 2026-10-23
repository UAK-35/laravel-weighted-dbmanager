@ECHO OFF
@ECHO disable the anti-virus first
@REM PHP comes from .agents\machine.local.json, the single place an absolute tool path
@REM lives, so this script names no drive and another machine is one edit to that file.
for /f "usebackq delims=" %%P in (`python "%~dp0..\.agents\render_local.py" --value __PHP_DIR__`) do set "PHP_DIR=%%P"
if not exist "%PHP_DIR%\php.exe" (
    echo ERROR: __PHP_DIR__ was not resolved from site\.agents\machine.local.json.
    echo        Bootstrap it with: python site\.agents\render_local.py --init
    exit /b 1
)
SET PATH=%PHP_DIR%;%PATH%
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === 'c8b085408188070d5f52bcfe4ecfbee5f727afa458b2573b8eaaf77b3419b0bf2768dc67c86944da1544f06fa544fd47') { echo 'Installer verified'.PHP_EOL; } else { echo 'Installer corrupt'.PHP_EOL; unlink('composer-setup.php'); exit(1); }"
php composer-setup.php
php -r "unlink('composer-setup.php');"
