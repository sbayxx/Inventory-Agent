<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';webHeaders();
try {if(getenv('INV_ENABLED')!=='1')respond(503,['enabled'=>false]);inventoryActor();respond(200,['enabled'=>enabled()]);}
catch(Throwable){respond(503,['enabled'=>false]);}
