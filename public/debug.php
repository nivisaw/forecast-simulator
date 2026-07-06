<?php
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";
echo "dirname(SCRIPT_NAME): " . dirname($_SERVER['SCRIPT_NAME']) . "\n";
echo "route: " . str_replace(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'), '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) . "\n";
