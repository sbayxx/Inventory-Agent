<?php
declare(strict_types=1);

// Installed as private public/backend/inventory/src/hosting-config.php on one.com.
$itsm = rtrim(getenv('INV_ITSM_ROOT') ?: dirname(__DIR__, 4), '/');
require_once $itsm.'/core/Env.php';
$host = Env::get('DB_HOST');
$database = Env::get('DB_NAME');
$user = Env::get('DB_USER');
$password = Env::get('DB_PASS');
if (!$host || !$database || !$user || !$password) {
    throw new RuntimeException('Missing ITSM database configuration');
}
$port = (int) (Env::get('DB_PORT') ?: 3306);
$charset = Env::get('DB_CHARSET') ?: 'utf8mb4';

putenv("INV_DB_DSN=mysql:host=$host;port=$port;dbname=$database;charset=$charset");
putenv("INV_DB_USER=$user");
putenv("INV_DB_PASSWORD=$password");
putenv("INV_ITSM_ROOT=$itsm");
putenv('INV_PUBLIC_URL=https://itsm.governit.dk/inventory');
$flag = dirname(__DIR__).'/enabled.flag';
putenv('INV_ENABLED='.(is_file($flag) && trim(file_get_contents($flag)) === '1' ? '1' : '0'));
