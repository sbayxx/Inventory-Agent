<?php
declare(strict_types=1);
function provisionAgent(PDO $db,array $actor,array $input): array {
    if(!in_array($actor['role']??'', ['admin','customer_admin'],true))throw new DomainException('forbidden');
    $csrf=$input['csrf_token']??null;
    if(!is_string($csrf)||!hash_equals($actor['csrf_token'],$csrf))throw new DomainException('csrf');
    $customer=filter_var($input['customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $label=$input['label']??null;$platform=$input['platform']??null;
    if(!$customer||!is_string($label)||trim($label)===''||strlen($label)>255||!is_string($platform)||!in_array($platform,['darwin','windows','linux'],true))throw new InvalidArgumentException('invalid_input');
    $allowed=false;foreach(inventoryCustomers($actor) as $row)if((int)$row['id']===$customer)$allowed=true;
    if(!$allowed)throw new DomainException('customer_forbidden');
    $server=inventoryPublicUrl();
    $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
    $q=$db->prepare('INSERT INTO inv_agents(id,token_hash,customer_id,label,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))');
    $q->execute([$id,hash('sha256',$token),$customer,trim($label)]);
    $states=['darwin'=>'/Library/Application Support/FlyingFishInventory/state','windows'=>'C:\\ProgramData\\FlyingFishInventory\\state','linux'=>'/var/lib/flyingfish-inventory'];
    return ['server'=>$server,'agent_id'=>$id,'token'=>$token,'interval_seconds'=>3600,'state_dir'=>$states[$platform],'max_queued'=>100];
}
