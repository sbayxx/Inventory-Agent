<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/BrowserInventory.php';require_once dirname(__DIR__).'/backend/inventory/src/DeviceIdentity.php';
webHeaders();
try{
 if(getenv('INV_ENABLED')!=='1'||!enabled()||!browserEnabled(db()))respond(503,['error'=>'browser_inventory_disabled']);
 if(($_SERVER['HTTPS']??'')!=='on')respond(400,['error'=>'https_required']);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}
 if(($_SERVER['CONTENT_TYPE']??'')!=='application/json')respond(415,['error'=>'json_required']);
 $agent=deviceAuthenticate(db(),'browser',1024*1024);$id=$agent['id'];
 $agentToken=preg_match('/^Bearer ([a-f0-9]{64})$/D',$_SERVER['HTTP_AUTHORIZATION']??'',$legacy)?$legacy[1]:null;
 requestBudget(db(),'browser-agent',$id,20,60);
    $raw=file_get_contents('php://input',false,null,0,1024*1024+1);if($raw===false||strlen($raw)>1024*1024)respond(413,['error'=>'payload_too_large']);
 $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($input)||count($input)!==3||array_diff(array_keys($input),['client_id','browser','scan'])||!is_string($input['client_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$input['client_id'])||!is_string($input['browser']??null)||!in_array($input['browser'],['chrome','edge','firefox','safari'],true))throw new InvalidBrowserData();
 $scan=browserValidate($input['scan']??null);$hash=hash('sha256',json_encode($scan,JSON_THROW_ON_ERROR));
 browserAutoPersist(db(),$id,$agentToken,$input['client_id'],$input['browser'],$scan,$hash);
 respond(200,['accepted'=>true,'revision'=>$scan['revision']]);
}catch(DomainException){respond(401,['error'=>'unauthorized']);}
catch(BrowserDenied){respond(403,['error'=>'browser_access_revoked']);}
catch(RequestRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(BrowserRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(InvalidBrowserData|JsonException){respond(422,['error'=>'invalid_browser_data']);}
catch(Throwable $e){error_log('Automatic browser inventory failure: '.get_class($e));respond(503,['error'=>'browser_inventory_unavailable']);}
