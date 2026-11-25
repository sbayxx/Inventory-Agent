<?php
error_reporting(0); ini_set('display_errors', '0');
set_time_limit(300);
require_once dirname(__DIR__) . '/core/Env.php';
// Actually we're in public/backend/inventory/, so ITSM root is 4 levels up
$itsm = dirname(__DIR__, 4);
require_once $itsm . '/core/Env.php';
Env::load($itsm . '/.env');
$host = Env::get('DB_HOST');
$port = (int)(Env::get('DB_PORT') ?: 3306);
$db = Env::get('DB_NAME');
$user = Env::get('DB_USER');
$pass = Env::get('DB_PASS');
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 30,
]);
$migDir = __DIR__ . '/migrations';
$files = glob($migDir . '/*.sql');
sort($files);
$ok = 0; $fail = 0; $errors = [];
foreach ($files as $f) {
    $sql = file_get_contents($f);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try { $pdo->exec($stmt); $ok++; }
        catch (Throwable $e) { $fail++; if (count($errors) < 5) $errors[] = basename($f) . ": " . $e->getMessage(); }
    }
}
echo "Migrations: ok=$ok fail=$fail\n";
foreach ($errors as $e) echo "  $e\n";
$tables = $pdo->query("SHOW TABLES LIKE 'inv_%'")->fetchAll(PDO::FETCH_COLUMN);
echo "inv_ tables: " . count($tables) . "\n";
