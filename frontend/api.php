<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/Inventory.php';require_once dirname(__DIR__).'/backend/inventory/src/DeviceIdentity.php';
webHeaders();
try {
    if(getenv('INV_ENABLED')!=='1')respond(503,['error'=>'inventory_disabled']);
    // TLS termination is configured by the server; never trust arbitrary forwarding headers.
    if(($_SERVER['HTTPS']??'')!=='on')respond(400,['error'=>'https_required']);
    if(!enabled())respond(503,['error'=>'inventory_disabled']);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}
    if(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0])!=='application/json')respond(415,['error'=>'json_required']);
    $a=deviceAuthenticate(db(),'scan',8*1024*1024);$id=$a['id'];
    requestBudget(db(),'scan',$id,30,60);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8*1024*1024)respond(413,['error'=>'payload_too_large']);
    $raw=file_get_contents('php://input',false,null,0,8*1024*1024+1);
    if($raw===false||strlen($raw)>8*1024*1024)respond(413,['error'=>'payload_too_large']);
    $s=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($s))throw new InvalidScan('Object required');validateScan($s);
    persistScan(db(),$id,$s,hash('sha256',$raw));respond(200,['accepted'=>true,'scan_id'=>$s['scan_id']]);
} catch(RequestRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(RateLimited $e){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(DomainException){respond(401,['error'=>'unauthorized']);}
catch(InvalidScan|JsonException $e){respond(422,['error'=>'invalid_scan']);}
catch(Throwable $e){error_log('Inventory API failure: '.get_class($e));respond(503,['error'=>'inventory_unavailable']);}
