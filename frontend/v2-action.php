<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/InventoryV2.php';require dirname(__DIR__).'/backend/inventory/src/V2Licensing.php';
try{
 $actor=inventoryActor();
 webHeaders();
 if(getenv('INV_ENABLED')!=='1')respond(503,['error'=>'inventory_disabled']);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}
 $actor=inventoryActor();if(!enabled())respond(503,['error'=>'inventory_disabled']);
 if(($_POST['action']??'')!=='settings'&&!v2Enabled(db()))respond(503,['error'=>'v2_disabled']);
 $message=v2Mutate(db(),$actor,$_POST);$view=($_POST['action']??'')==='settings'?'settings':(($_POST['action']??'')==='acknowledge'?'notifications':'licenses');
 header('Location: v2.php?'.http_build_query(['view'=>$view,'saved'=>'1']),true,303);
}catch(DomainException){respond(403,['error'=>'v2_access_denied']);}
catch(InvalidArgumentException $e){respond(422,['error'=>'invalid_input','message'=>$e->getMessage()]);}
catch(Throwable $e){error_log('V2 mutation failure: '.get_class($e));respond(503,['error'=>'v2_unavailable']);}
