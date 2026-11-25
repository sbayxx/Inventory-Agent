<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/provisioning.php';
try {
    $actor=inventoryActor();
    webHeaders();
    if(getenv('INV_ENABLED')!=='1')respond(503,['error'=>'inventory_disabled']);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}if(!enabled())respond(503,['error'=>'inventory_disabled']);
    $config=provisionAgent(db(),$actor,$_POST);
    header('Content-Type: application/json');header('Content-Disposition: attachment; filename="agent.json"');
    echo json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch(DomainException){respond(403,['error'=>'provision_denied']);}
catch(InvalidArgumentException){respond(422,['error'=>'invalid_input']);}
catch(Throwable $e){error_log('Inventory provisioning failure: '.get_class($e));respond(503,['error'=>'inventory_unavailable']);}
