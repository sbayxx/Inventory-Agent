<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/InventoryV3.php';
webHeaders();
try{
 if(($_SERVER['HTTPS']??'')!=='on')respond(400,['error'=>'https_required']);
 if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){header('Allow: GET');respond(405,['error'=>'method_not_allowed']);}
 if(!enabled()||!v3Enabled(db(),'integrations'))respond(503,['error'=>'integration_disabled']);
 $auth=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m))respond(401,['error'=>'unauthorized']);
 try{$a=v3IntegrationAuth(db(),$m[1]);}catch(DomainException){respond(401,['error'=>'unauthorized']);}
 $data=v3IntegrationRead(db(),(int)$a['customer_id'],$_GET);v3Audit(db(),(int)$a['customer_id'],null,null,'integration_read',['token_id'=>$a['id'],'resource'=>$_GET['resource']??'computers']);respond(200,$data);
}catch(V3RateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}catch(InvalidArgumentException){respond(400,['error'=>'invalid_request']);}catch(DomainException){respond(404,['error'=>'not_found']);}catch(Throwable $e){error_log('Inventory V3 integration: '.get_class($e));respond(503,['error'=>'integration_unavailable']);}
