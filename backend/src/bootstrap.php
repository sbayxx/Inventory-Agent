<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/RequestSecurity.php';
if(is_file(__DIR__.'/hosting-config.php'))require __DIR__.'/hosting-config.php';
function envRequired(string $key): string { $v=getenv($key); if ($v===false || $v==='') throw new RuntimeException("Missing configuration: $key"); return $v; }
function inventoryPublicUrl(): string {
    $server=envRequired('INV_PUBLIC_URL');
    if(!filter_var($server,FILTER_VALIDATE_URL)||parse_url($server,PHP_URL_SCHEME)!=='https'
        ||parse_url($server,PHP_URL_USER)!==null||parse_url($server,PHP_URL_QUERY)!==null
        ||parse_url($server,PHP_URL_FRAGMENT)!==null)throw new RuntimeException('Invalid public URL');
    return rtrim($server,'/');
}
function db(): PDO {
    static $db; if ($db) return $db;
    $db=new PDO(envRequired('INV_DB_DSN'),envRequired('INV_DB_USER'),envRequired('INV_DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec("SET time_zone = '+00:00'"); return $db;
}
function respond(int $status,array $body): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body,JSON_THROW_ON_ERROR); exit; }
function enabled(): bool { return getenv('INV_ENABLED')==='1' && db()->query("SELECT value FROM inv_settings WHERE name='enabled'")->fetchColumn()==='1'; }
function webHeaders(bool $scripts=false): void {
    if(($_SERVER['HTTPS']??'')!=='on')respond(400,['error'=>'https_required']);
    header('Strict-Transport-Security: max-age=31536000');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; ".($scripts?"script-src 'self'; ":'')."base-uri 'none'; frame-ancestors 'self'; form-action 'self'");
}
