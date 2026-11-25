<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/DeviceIdentity.php';
webHeaders();
try{
 if(!enabled())respond(503,['error'=>'enrollment_disabled']);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}
 // Only the socket peer is trusted. Forwarding headers cannot change the budget identity.
 db()->exec('DELETE FROM inv_request_limits WHERE expires_at<UNIX_TIMESTAMP() LIMIT 100');
 requestBudget(db(),'enrollment-global','all',3000,60);
 requestBudget(db(),'enrollment-ip',(string)($_SERVER['REMOTE_ADDR']??'unknown'),300,60);
 if(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0])!=='application/json')respond(415,['error'=>'json_required']);
 $raw=file_get_contents('php://input',false,null,0,4097);if($raw===false||strlen($raw)>4096)respond(413,['error'=>'payload_too_large']);
 $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException();
 // Bound replay storage, including requests made with disposable self-generated keys.
 db()->exec('DELETE FROM inv_device_nonces WHERE expires_at<UNIX_TIMESTAMP() LIMIT 1000');
 respond(200,deviceEnroll(db(),$input,$raw,$_SERVER));
}catch(RequestRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(DomainException){respond(403,['error'=>'registration_denied']);}
catch(InvalidArgumentException|JsonException){respond(400,['error'=>'invalid_registration']);}
catch(Throwable $e){error_log('Device registration: '.get_class($e));respond(503,['error'=>'registration_unavailable']);}
