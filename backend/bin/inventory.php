<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
$cmd=$argv[1]??'';
if($cmd==='software-migrate') {
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/008_software_catalog.sql'));echo "Software catalogue migration applied.\n";
} elseif($cmd==='software-refresh') {
    require dirname(__DIR__).'/src/SoftwareCatalog.php';
    do{$result=softwareRefresh(db(),0);echo json_encode($result,JSON_THROW_ON_ERROR)."\n";if($result['failed'])exit(1);}while($result['remaining']>0);
} elseif($cmd==='migrate') {
    if(db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'inv\_%'")->fetchColumn()>0)throw new RuntimeException('Inventory tables already exist; refusing to rerun migration');
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/001_inventory.sql'));echo "Migration applied; module disabled.\n";
} elseif(in_array($cmd,['enable','disable'],true)) {
    db()->prepare("UPDATE inv_settings SET value=? WHERE name='enabled'")->execute([$cmd==='enable'?'1':'0']);
} elseif($cmd==='v2-migrate') {
    if(db()->query("SELECT value FROM inv_settings WHERE name='schema_version'")->fetchColumn()!=='1')throw new RuntimeException('Inventory v1 required');
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/003_inventory_v2.sql'));db()->exec(file_get_contents(dirname(__DIR__).'/migrations/004_activation_metadata.sql'));echo "V2 migration applied; independently disabled by default.\n";
} elseif(in_array($cmd,['v2-enable','v2-disable','v2-evaluate','v2-backfill'],true)) {
    require_once dirname(__DIR__).'/src/InventoryV2.php';
    if(db()->query("SELECT value FROM inv_settings WHERE name='v2_schema_version'")->fetchColumn()!=='1')throw new RuntimeException('V2 migration required');
    if(in_array($cmd,['v2-enable','v2-disable'],true))db()->prepare("UPDATE inv_settings SET value=? WHERE name='v2_enabled'")->execute([$cmd==='v2-enable'?'1':'0']);
    elseif($cmd==='v2-backfill')echo "Indexed computers: ".v2Backfill(db())."\n";
    else {db()->beginTransaction();try{v2Evaluate(db());db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}
} elseif($cmd==='enrollment-migrate') {
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/006_request_security.sql'));
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/007_device_enrollment.sql'));echo "Device enrollment migration applied; enrollment disabled by default.\n";
} elseif($cmd==='security-migrate') {
    if(db()->query("SELECT value FROM inv_settings WHERE name='schema_version'")->fetchColumn()!=='1')throw new RuntimeException('Inventory v1 required');
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/006_request_security.sql'));echo "Request-security migration applied.\n";
} elseif($cmd==='v3-migrate') {
    if(db()->query("SELECT value FROM inv_settings WHERE name='schema_version'")->fetchColumn()!=='1')throw new RuntimeException('Inventory v1 required');
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/005_inventory_v3.sql'));echo "V3 migration applied; disabled by default.\n";
} elseif(in_array($cmd,['v3-enable','v3-disable'],true)) {
    if(db()->query("SELECT value FROM inv_settings WHERE name='v3_schema_version'")->fetchColumn()!=='1')throw new RuntimeException('V3 migration required');
    db()->prepare("UPDATE inv_settings SET value=? WHERE name='v3_enabled'")->execute([$cmd==='v3-enable'?'1':'0']);
} elseif($cmd==='v3-import-release') {
    require dirname(__DIR__).'/src/V3Releases.php';
    if(count($argv)!==5)throw new InvalidArgumentException('v3-import-release ENVELOPE BINARY PUBLIC_KEY_FILE');
    echo v3ImportRelease(db(),$argv[2],$argv[3],file_get_contents($argv[4]))."\n";
} elseif($cmd==='web-migrate') {
    if(db()->query("SELECT value FROM inv_settings WHERE name='schema_version'")->fetchColumn()!=='1')throw new RuntimeException('Inventory v1 required');
    db()->exec(file_get_contents(dirname(__DIR__).'/migrations/002_browser_inventory.sql'));echo "Browser migration applied; independently disabled by default.\n";
} elseif(in_array($cmd,['web-enable','web-disable'],true)) {
    db()->prepare("UPDATE inv_settings SET value=? WHERE name='web_enabled'")->execute([$cmd==='web-enable'?'1':'0']);
} elseif($cmd==='web-purge') {
    $count=db()->exec('DELETE FROM inv_browser_daily WHERE day < UTC_DATE() - INTERVAL 29 DAY');echo "Expired browser aggregates purged: ".$count."\n";
} elseif($cmd==='provision') {
    $customer=filter_var($argv[2]??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$server=$argv[3]??'';
    if(!$customer||!filter_var($server,FILTER_VALIDATE_URL)||parse_url($server,PHP_URL_SCHEME)!=='https')throw new RuntimeException('Usage: provision CUSTOMER_ID HTTPS_BASE_URL > protected-agent.json');
    $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO inv_agents(id,token_hash,customer_id,label,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))')->execute([$id,hash('sha256',$token),$customer,$argv[4]??$id]);
    echo json_encode(['server'=>$server,'agent_id'=>$id,'token'=>$token,'interval_seconds'=>3600,'state_dir'=>'/var/lib/flyingfish-inventory','max_queued'=>100],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} elseif($cmd==='revoke') {
    $id=$argv[2]??'';if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid agent ID');db()->prepare('UPDATE inv_agents SET enabled=0 WHERE id=?')->execute([$id]);
} else {fwrite(STDERR,"Commands: software-migrate | software-refresh | enrollment-migrate | security-migrate | v3-migrate | v3-enable | v3-disable | v3-import-release ENVELOPE BINARY PUBLIC_KEY_FILE | migrate | enable | disable | v2-migrate | v2-enable | v2-disable | v2-evaluate | v2-backfill | web-migrate | web-enable | web-disable | web-purge | provision CUSTOMER_ID HTTPS_BASE_URL [LABEL] | revoke AGENT_ID\n");exit(1);}
