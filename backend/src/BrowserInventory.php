<?php
declare(strict_types=1);
final class InvalidBrowserData extends RuntimeException {}
final class BrowserDenied extends RuntimeException {}
final class BrowserRateLimited extends RuntimeException {}
function browserEnabled(PDO $db):bool{return $db->query("SELECT value FROM inv_settings WHERE name='web_enabled'")->fetchColumn()==='1';}
function browserCanManage(array $actor):bool{return in_array($actor['role']??'', ['admin','customer_admin'],true);}
function browserComputer(PDO $db,array $actor,string $agent):bool{
    [$where,$params]=inventoryScope($actor);
    $q=$db->prepare('SELECT a.id FROM inv_agents a JOIN inv_computers c ON c.agent_id=a.id WHERE '.$where.' AND a.id=?');$q->execute([...$params,$agent]);return (bool)$q->fetchColumn();
}
function browserProvision(PDO $db,array $actor,array $input):array{
    if(!browserCanManage($actor)||!is_string($input['csrf_token']??null)||!hash_equals($actor['csrf_token'],$input['csrf_token']))throw new BrowserDenied();
    $agent=$input['agent_id']??'';$label=$input['label']??'';
    if(!is_string($agent)||!preg_match('/^[a-f0-9]{32}$/D',$agent)||!is_string($label)||trim($label)===''||strlen($label)>120)throw new InvalidBrowserData();
    if(!browserComputer($db,$actor,$agent))throw new BrowserDenied();
    $db->beginTransaction();try{
    [$scope,$params]=inventoryScope($actor);$q=$db->prepare('SELECT a.id FROM inv_agents a WHERE '.$scope.' AND a.id=? FOR UPDATE');$q->execute([...$params,$agent]);if(!$q->fetchColumn())throw new BrowserDenied();
    if(($input['action']??'')==='revoke'){
        $profile=$input['profile_id']??'';if(!is_string($profile)||!preg_match('/^[a-f0-9]{32}$/D',$profile))throw new InvalidBrowserData();
        $q=$db->prepare('UPDATE inv_browser_profiles SET enabled=0 WHERE id=? AND agent_id=?');$q->execute([$profile,$agent]);$db->commit();return [];
    }
    $server=inventoryPublicUrl().'/browser-api.php';
    $q=$db->prepare('SELECT COUNT(*) FROM inv_browser_profiles WHERE agent_id=?');$q->execute([$agent]);if((int)$q->fetchColumn()>=100)throw new InvalidBrowserData();
    $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
    $q=$db->prepare('INSERT INTO inv_browser_profiles(id,agent_id,token_hash,label,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$id,$agent,hash('sha256',$token),trim($label)]);
    $db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['schema'=>1,'server'=>$server,'profile_id'=>$id,'token'=>$token];
}
function browserValidate(mixed $input,?DateTimeImmutable $now=null):array{
    if(!is_array($input)||array_diff(array_keys($input),['schema','revision','records'])||($input['schema']??null)!==1||!is_int($input['revision']??null)||$input['revision']<1||$input['revision']>9007199254740991||!is_array($input['records']??null)||!array_is_list($input['records'])||count($input['records'])>5000)throw new InvalidBrowserData();
    $now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));$today=$now->format('Y-m-d');$cutoff=$now->modify('-29 days')->format('Y-m-d');
    $catalog=json_decode(file_get_contents(__DIR__.'/browser-taxonomy.json'),true,64,JSON_THROW_ON_ERROR);$categories=array_column($catalog['categories'],'id');$seen=[];
    foreach($input['records'] as $r){
        if(!is_array($r)||count($r)!==4||array_diff(array_keys($r),['day','domain','category','count']))throw new InvalidBrowserData();
        $domain=$r['domain']??null;$day=$r['day']??null;
        if(!is_string($domain)||strlen($domain)>253||!str_contains($domain,'.')||preg_match('/^[0-9.]+$/D',$domain)||!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D',$domain)||preg_match('/\.(local|localhost|internal|lan|home|test|invalid)$/D',$domain))throw new InvalidBrowserData();
        if(!is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)||$day<$cutoff||$day>$today||DateTimeImmutable::createFromFormat('!Y-m-d',$day)?->format('Y-m-d')!==$day||!is_string($r['category']??null)||!in_array($r['category'],$categories,true)||!is_int($r['count']??null)||$r['count']<1||$r['count']>1000000)throw new InvalidBrowserData();
        $key=$day.'|'.$domain;if(isset($seen[$key]))throw new InvalidBrowserData();$seen[$key]=true;
    }
    return $input;
}
function browserPersist(PDO $db,string $id,string $token,array $input,string $hash):void{
    $ownsTransaction=!$db->inTransaction();if($ownsTransaction)$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT p.*,a.enabled AS agent_enabled FROM inv_browser_profiles p JOIN inv_agents a ON a.id=p.agent_id WHERE p.id=? FOR UPDATE');$q->execute([$id]);$profile=$q->fetch();
        if(!$profile||!(int)$profile['enabled']||!(int)$profile['agent_enabled']||!hash_equals($profile['token_hash'],hash('sha256',$token)))throw new BrowserDenied();
        $recent=$profile['rate_window']!==null&&strtotime($profile['rate_window'].' UTC')>time()-60;
        if($recent&&(int)$profile['rate_count']>=10)throw new BrowserRateLimited();
        $q=$db->prepare('UPDATE inv_browser_profiles SET rate_window=UTC_TIMESTAMP(6),rate_count=? WHERE id=?');$q->execute([$recent?(int)$profile['rate_count']+1:1,$id]);
        if($input['revision']<(int)$profile['last_revision']||($input['revision']===(int)$profile['last_revision']&&!hash_equals($profile['payload_hash']??'',$hash)))throw new InvalidBrowserData();
        if($input['revision']>(int)$profile['last_revision']){
            $db->prepare('DELETE FROM inv_browser_daily WHERE profile_id=?')->execute([$id]);
            $q=$db->prepare('INSERT INTO inv_browser_daily(profile_id,day,domain,category,visits) VALUES(?,?,?,?,?)');foreach($input['records'] as $r)$q->execute([$id,$r['day'],$r['domain'],$r['category'],$r['count']]);
            $q=$db->prepare('UPDATE inv_browser_profiles SET last_revision=?,payload_hash=?,last_seen=UTC_TIMESTAMP(6) WHERE id=?');$q->execute([$input['revision'],$hash,$id]);
        }
        if($ownsTransaction)$db->commit();
    }catch(Throwable $e){if($ownsTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
    // Bounded housekeeping of this separate feature; never touches ITSM/inventory snapshots.
    if($ownsTransaction)$db->exec('DELETE FROM inv_browser_daily WHERE day < UTC_DATE() - INTERVAL 29 DAY LIMIT 10000');
}

// Called only after the normal agent credential has been verified. No serial-number claims are trusted.
function browserAutoPersist(PDO $db,string $agentId,?string $agentToken,string $clientId,string $browser,array $scan,string $hash):void{
    $db->beginTransaction();try{
    $lock=$db->prepare('SELECT token_hash,enabled FROM inv_agents WHERE id=? FOR UPDATE');$lock->execute([$agentId]);$parent=$lock->fetch();
    if(!$parent||!(int)$parent['enabled']||($agentToken!==null&&!hash_equals($parent['token_hash'],hash('sha256',$agentToken))))throw new BrowserDenied();
    $profileId=substr(hash('sha256',$agentId.':'.$clientId),0,32);
    $token=$agentToken!==null?hash_hmac('sha256','browser:'.$clientId,$agentToken):bin2hex(random_bytes(32));
    $q=$db->prepare('SELECT enabled FROM inv_browser_profiles WHERE id=? AND agent_id=?');$q->execute([$profileId,$agentId]);$existing=$q->fetch();
    if($existing&&!(int)$existing['enabled'])throw new BrowserDenied();
    if(!$existing){
        $q=$db->prepare('SELECT COUNT(*) FROM inv_browser_profiles WHERE agent_id=?');$q->execute([$agentId]);if((int)$q->fetchColumn()>=100)throw new InvalidBrowserData();
        $q=$db->prepare('INSERT IGNORE INTO inv_browser_profiles(id,agent_id,token_hash,label,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$profileId,$agentId,hash('sha256',$token),ucfirst($browser).' · automatisk '.substr($clientId,0,6)]);
    }
    // Supports rotation of the parent agent's credential without undoing revocation.
    $q=$db->prepare('UPDATE inv_browser_profiles SET token_hash=? WHERE id=? AND agent_id=? AND enabled=1');$q->execute([hash('sha256',$token),$profileId,$agentId]);
    browserPersist($db,$profileId,$token,$scan,$hash);
    $db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $db->exec('DELETE FROM inv_browser_daily WHERE day < UTC_DATE() - INTERVAL 29 DAY LIMIT 10000');
}
