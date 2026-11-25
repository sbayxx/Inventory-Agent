<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/BrowserInventory.php';
try{
    $actor=inventoryActor();
    webHeaders();
    if(getenv('INV_ENABLED')!=='1')respond(503,['error'=>'inventory_disabled']);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}if(!enabled()||!browserEnabled(db()))respond(503,['error'=>'browser_inventory_disabled']);
    $config=browserProvision(db(),$actor,$_POST);
    if(!$config){header('Location: ./?id='.rawurlencode($_POST['agent_id']).'#web',true,303);exit;}
    header('Content-Type: application/json');header('Content-Disposition: attachment; filename="browser.json"');echo json_encode($config,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
}catch(BrowserDenied){respond(403,['error'=>'browser_provision_denied']);}
catch(InvalidBrowserData){respond(422,['error'=>'invalid_input']);}
catch(Throwable $e){error_log('Browser provision failure: '.get_class($e));respond(503,['error'=>'browser_inventory_unavailable']);}
