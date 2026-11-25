<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$itsm = dirname(__DIR__, 4);
echo "itsm root: $itsm\n";
echo "core/Env.php exists: " . (file_exists($itsm . '/core/Env.php') ? 'yes' : 'no') . "\n";
require_once $itsm . '/core/Env.php';
Env::load($itsm . '/.env');
echo "Env loaded, DB_HOST: " . Env::get('DB_HOST') . "\n";
