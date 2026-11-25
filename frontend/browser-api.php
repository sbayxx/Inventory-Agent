<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/BrowserInventory.php';
webHeaders();
try{
    if(getenv('INV_ENABLED')!=='1'||!enabled()||!browserEnabled(db()))respond(503,['error'=>'browser_inventory_disabled']);
    if(($_SERVER['HTTPS']??'')!=='on')respond(400,['error'=>'https_required']);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}
    if(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0])!=='application/json')respond(415,['error'=>'json_required']);
    $id=$_SERVER['HTTP_X_BROWSER_PROFILE']??'';$auth=$_SERVER['HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m))respond(401,['error'=>'unauthorized']);
    $q=db()->prepare('SELECT p.token_hash,p.enabled,a.enabled AS agent_enabled FROM inv_browser_profiles p JOIN inv_agents a ON a.id=p.agent_id WHERE p.id=?');$q->execute([$id]);$profile=$q->fetch();
    if(!$profile||!(int)$profile['enabled']||!(int)$profile['agent_enabled']||!hash_equals($profile['token_hash'],hash('sha256',$m[1])))respond(401,['error'=>'unauthorized']);
    requestBudget(db(),'browser-profile',$id,10,60);
    $raw=file_get_contents('php://input',false,null,0,1024*1024+1);if($raw===false||strlen($raw)>1024*1024)respond(413,['error'=>'payload_too_large']);
    $input=browserValidate(json_decode($raw,true,32,JSON_THROW_ON_ERROR));browserPersist(db(),$id,$m[1],$input,hash('sha256',$raw));respond(200,['accepted'=>true,'revision'=>$input['revision']]);
}catch(BrowserDenied){respond(401,['error'=>'unauthorized']);}
catch(RequestRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(BrowserRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(InvalidBrowserData|JsonException){respond(422,['error'=>'invalid_browser_data']);}
catch(Throwable $e){error_log('Browser inventory failure: '.get_class($e));respond(503,['error'=>'browser_inventory_unavailable']);}
