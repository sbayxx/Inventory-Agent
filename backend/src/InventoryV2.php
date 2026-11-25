<?php
declare(strict_types=1);
const V2_FEATURES=['changes','licenses','security','reports','notifications'];
function v2Enabled(PDO $db,?string $feature=null):bool{
    if($feature!==null&&!in_array($feature,V2_FEATURES,true))return false;
    $q=$db->prepare("SELECT name,value FROM inv_settings WHERE name IN ('v2_enabled',?)");$q->execute(['v2_'.($feature??'enabled')]);$flags=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    return ($flags['v2_enabled']??'0')==='1'&&($feature===null||($flags['v2_'.$feature]??'0')==='1');
}
function v2Manage(array $actor):bool{return in_array($actor['role']??'',['admin','customer_admin'],true);}
function v2Text(string $value,int $limit):string{
    $value=substr($value,0,$limit);while($value!==''&&preg_match('//u',$value)!==1)$value=substr($value,0,-1);return $value;
}
function v2Norm(string $value):string{return strtolower(trim(preg_replace('/\s+/u',' ',$value)??$value));}
function v2Json(mixed $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
function v2Canonical(mixed $v):mixed{if(!is_array($v))return $v;if(array_is_list($v)){$v=array_map('v2Canonical',$v);usort($v,fn($a,$b)=>strcmp(v2Json($a),v2Json($b)));}else{ksort($v);foreach($v as &$x)$x=v2Canonical($x);}return $v;}
function v2ValidateObservations(array $s):void{
    if(array_key_exists('security',$s)){
        if(!is_array($s['security'])||count($s['security'])>4)throw new InvalidArgumentException('Invalid security');
        foreach($s['security'] as $key=>$check){
            if(!in_array($key,['firewall','disk_encryption','antivirus','updates'],true)||!is_array($check)||count($check)!==3||array_diff(array_keys($check),['state','source','detail'])||!in_array($check['state']??null,['enabled','disabled','unknown','not_supported','attention'],true))throw new InvalidArgumentException('Invalid security check');
            foreach(['source'=>80,'detail'=>255] as $field=>$max)if(!is_string($check[$field]??null)||strlen($check[$field])>$max)throw new InvalidArgumentException('Invalid security text');
        }
    }
    if(array_key_exists('license_observations',$s)){
        if(!is_array($s['license_observations'])||!array_is_list($s['license_observations'])||count($s['license_observations'])>100)throw new InvalidArgumentException('Invalid observations');
        foreach($s['license_observations'] as $row){
            if(!is_array($row)||array_diff(array_keys($row),['product_id','product','state','source','evaluation_end_at','grace_period_remaining_minutes','license_status_reason','license_family'])||!in_array($row['state']??null,['licensed','unlicensed','grace','non_genuine','notification','extended_grace','unknown'],true))throw new InvalidArgumentException('Invalid activation status');
            foreach(['product_id'=>64,'product'=>255,'source'=>80] as $key=>$max)if(!is_string($row[$key]??null)||$row[$key]===''||strlen($row[$key])>$max)throw new InvalidArgumentException('Invalid activation product');
            if(isset($row['license_family'])&&(!is_string($row['license_family'])||strlen($row['license_family'])>255))throw new InvalidArgumentException('Invalid license family');
            foreach(['grace_period_remaining_minutes','license_status_reason'] as $key)if(array_key_exists($key,$row)&&(!is_int($row[$key])||$row[$key]<0||$row[$key]>4294967295))throw new InvalidArgumentException('Invalid license number');
            if(array_key_exists('evaluation_end_at',$row)){
                $v=$row['evaluation_end_at'];$date=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC')):false;
                if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$v||$date->format('Y')<'2000')throw new InvalidArgumentException('Invalid evaluation date');
            }

        }
    }
}
function v2Software(array $rows):array{
    $result=[];
    foreach($rows as $r){if(!is_string($r['name']??null)||trim($r['name'])==='')continue;
        $name=v2Text(trim($r['name']),255);$publisher=is_string($r['publisher']??null)?v2Text(trim($r['publisher']),255):'';
        $key=hash('sha256',v2Norm($name)."\0".v2Norm($publisher));
        $result[$key]??=['name'=>$name,'publisher'=>$publisher,'versions'=>[]];$version=is_scalar($r['version']??null)?v2Text((string)$r['version'],128):'';$result[$key]['versions'][]=$version;
    }
    foreach($result as &$r){$r['versions']=array_values(array_unique($r['versions']));sort($r['versions'],SORT_STRING);}return $result;
}
function v2Diff(?array $before,array $after):array{
    if(!$before)return []; // first successful snapshot is a baseline, not thousands of installations.
    $events=[];
    $add=function(string $category,string $operation,string $key,string $label,mixed $old,mixed $new)use(&$events){$events[]=['category'=>$category,'operation'=>$operation,'item_key'=>hash('sha256',$key),'label'=>v2Text($label,255),'before'=>$old,'after'=>$new];};
    foreach(['computer','hardware','network_config','security'] as $category){
        if($category==='security'&&!isset($after['security']))continue;
        foreach($after[$category]??[] as $key=>$value){
            if($value===null||$value===''||!array_key_exists($key,$before[$category]??[]))continue;
            if(v2Canonical($before[$category][$key])!==v2Canonical($value))$add($category,'changed',(string)$key,(string)$key,$before[$category][$key],$value);
        }
    }
    if(($after['collection']['software']??'')==='ok'){
        $old=v2Software($before['software']??[]);$new=v2Software($after['software']??[]);
        foreach(array_unique([...array_keys($old),...array_keys($new)]) as $key){
            if(!isset($old[$key]))$add('software','installed',$key,$new[$key]['name'],null,$new[$key]);
            elseif(!isset($new[$key]))$add('software','removed',$key,$old[$key]['name'],$old[$key],null);
            elseif($old[$key]['versions']!==$new[$key]['versions'])$add('software','updated',$key,$new[$key]['name'],$old[$key],$new[$key]);
        }
    }
    foreach(['disks','network'] as $category){
        if(($after['collection'][$category]??'')!=='ok')continue;
        $index=function(array $rows)use($category){$map=[];foreach($rows as $r){$key=$category==='disks'?($r['device']??'').'|'.($r['mount']??''):(!empty($r['mac'])?$r['mac']:($r['name']??''));if($key==='')continue;if($category==='disks')unset($r['free_bytes']);$map[$key]=$r;}return $map;};
        $old=$index($before[$category]??[]);$new=$index($after[$category]??[]);
        foreach(array_unique([...array_keys($old),...array_keys($new)]) as $key)if(v2Canonical($old[$key]??null)!==v2Canonical($new[$key]??null))$add($category,!isset($old[$key])?'added':(!isset($new[$key])?'removed':'changed'),(string)$key,(string)$key,$old[$key]??null,$new[$key]??null);
    }
    if(count($events)>10000){$total=count($events);$events=array_slice($events,0,10000);$add('collection','truncated','event_limit','Historikgrænse nået',null,['omitted'=>$total-10000]);}
    return $events;
}
function v2ProcessScan(PDO $db,string $agent,array $s,?array $previous,string $at):void{
    if(!v2Enabled($db))return;
    if(v2Enabled($db,'changes')){
        $insert=$db->prepare('INSERT IGNORE INTO inv_v2_events(event_key,agent_id,scan_id,category,operation,item_key,label,before_value,after_value,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach(v2Diff($previous,$s) as $event)$insert->execute([hash('sha256',$agent.$s['scan_id'].$event['category'].$event['item_key']),$agent,$s['scan_id'],$event['category'],$event['operation'],$event['item_key'],$event['label'],v2Json($event['before']),v2Json($event['after']),$at]);
    }
    if((v2Enabled($db,'licenses')||v2Enabled($db,'reports'))&&($s['collection']['software']??'')==='ok'){
        $db->prepare('DELETE FROM inv_v2_installations WHERE agent_id=?')->execute([$agent]);
        $q=$db->prepare('INSERT INTO inv_v2_installations(agent_id,product_key,name,publisher,name_hash,publisher_hash,versions,observed_at) VALUES(?,?,?,?,?,?,?,?)');
        foreach(v2Software($s['software']) as $key=>$r)$q->execute([$agent,$key,$r['name'],$r['publisher'],hash('sha256',v2Norm($r['name'])),hash('sha256',v2Norm($r['publisher'])),v2Json($r['versions']),$at]);
    }
    if(v2Enabled($db,'security')){
        $q=$db->prepare('INSERT INTO inv_v2_security(agent_id,check_name,state,source,detail,observed_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),source=VALUES(source),detail=VALUES(detail),observed_at=VALUES(observed_at)');
        foreach($s['security']??[] as $key=>$r)$q->execute([$agent,$key,$r['state'],$r['source'],$r['detail'],$at]);
    }
    if(v2Enabled($db,'licenses')&&isset($s['license_observations'])&&($s['collection']['licenses']??'')==='ok'){
        $db->prepare('DELETE FROM inv_v2_license_observations WHERE agent_id=?')->execute([$agent]);$q=$db->prepare('INSERT INTO inv_v2_license_observations(agent_id,product_id,product,state,source,observed_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE product=VALUES(product),state=VALUES(state),source=VALUES(source),observed_at=VALUES(observed_at)');
        $metadata=$db->prepare('INSERT INTO inv_v2_activation_metadata(agent_id,product_id,first_observed_at,first_observed_licensed_at,evaluation_end_at,grace_period_remaining_minutes,license_status_reason,license_family,observed_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE first_observed_at=LEAST(first_observed_at,VALUES(first_observed_at)),first_observed_licensed_at=CASE WHEN VALUES(first_observed_licensed_at) IS NULL THEN first_observed_licensed_at WHEN first_observed_licensed_at IS NULL THEN VALUES(first_observed_licensed_at) ELSE LEAST(first_observed_licensed_at,VALUES(first_observed_licensed_at)) END,evaluation_end_at=IF(VALUES(observed_at)>=observed_at,VALUES(evaluation_end_at),evaluation_end_at),grace_period_remaining_minutes=IF(VALUES(observed_at)>=observed_at,VALUES(grace_period_remaining_minutes),grace_period_remaining_minutes),license_status_reason=IF(VALUES(observed_at)>=observed_at,VALUES(license_status_reason),license_status_reason),license_family=IF(VALUES(observed_at)>=observed_at,VALUES(license_family),license_family),observed_at=GREATEST(observed_at,VALUES(observed_at))');
        foreach($s['license_observations'] as $r){
            $q->execute([$agent,$r['product_id'],$r['product'],$r['state'],$r['source'],$at]);
            $metadata->execute([$agent,$r['product_id'],$at,$r['state']==='licensed'?$at:null,isset($r['evaluation_end_at'])?str_replace(['T','Z'],[' ',''],$r['evaluation_end_at']):null,$r['grace_period_remaining_minutes']??null,$r['license_status_reason']??null,$r['license_family']??null,$at]);
        }
    }
    if(v2Enabled($db,'notifications'))v2EvaluateAgent($db,$agent);
}
function v2Notice(PDO $db,int $customer,string $key,?string $agent,string $kind,string $severity,string $title,string $message,bool $active):void{
    $key=hash('sha256',$key);
    if(!$active){$db->prepare('UPDATE inv_v2_notices SET active=0 WHERE customer_id=? AND notice_key=?')->execute([$customer,$key]);return;}
    $q=$db->prepare('INSERT INTO inv_v2_notices(customer_id,notice_key,agent_id,kind,severity,title,message,first_seen,last_seen) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title=VALUES(title),message=VALUES(message),severity=VALUES(severity),last_seen=VALUES(last_seen),acknowledged_by=IF(active=0,NULL,acknowledged_by),acknowledged_at=IF(active=0,NULL,acknowledged_at),active=1');
    $q->execute([$customer,$key,$agent,$kind,$severity,v2Text($title,255),$message]);
}
function v2EvaluateAgent(PDO $db,string $agent):void{
    $q=$db->prepare('SELECT a.customer_id,a.enabled,a.last_seen,c.hostname,c.snapshot FROM inv_agents a JOIN inv_computers c ON c.agent_id=a.id WHERE a.id=?');$q->execute([$agent]);$computer=$q->fetch();if(!$computer||!(int)$computer['enabled'])return;
    $customer=(int)$computer['customer_id'];$s=json_decode($computer['snapshot'],true,64,JSON_THROW_ON_ERROR);$name=$computer['hostname'];
    v2Notice($db,$customer,'stale:'.$agent,$agent,'agent_stale','warning',$name.' · manglende kontakt','Ingen modtagelse fra computeragenten i mere end 48 timer.',!$computer['last_seen']||strtotime($computer['last_seen'].' UTC')<time()-48*3600);
    $diskKeys=[];if(($s['collection']['disks']??'')==='ok')foreach($s['disks']??[] as $disk){$capacity=$disk['capacity_bytes']??0;$free=$disk['free_bytes']??null;if(!is_numeric($capacity)||$capacity<=0||!is_numeric($free))continue;$low=$free/$capacity<0.1;
        $diskKeys[]=hash('sha256','disk:'.$agent.($disk['device']??'').($disk['mount']??''));
        v2Notice($db,$customer,'disk:'.$agent.($disk['device']??'').($disk['mount']??''),$agent,'disk_space','warning',$name.' · lav diskplads','Under 10 % ledig plads på '.($disk['mount']??$disk['device']??'disk').'.',$low);
    }
    if(($s['collection']['disks']??'')==='ok'){
      $q=$db->prepare("SELECT id,notice_key FROM inv_v2_notices WHERE agent_id=? AND kind='disk_space' AND active=1");$q->execute([$agent]);foreach($q->fetchAll() as $notice)if(!in_array($notice['notice_key'],$diskKeys,true))$db->prepare('UPDATE inv_v2_notices SET active=0 WHERE id=?')->execute([$notice['id']]);
    }
    $q=$db->prepare('SELECT check_name,state,observed_at FROM inv_v2_security WHERE agent_id=?');$q->execute([$agent]);foreach($q->fetchAll() as $r){
        $fresh=v2Enabled($db,'security')&&strtotime($r['observed_at'].' UTC')>=time()-48*3600;
        v2Notice($db,$customer,'security:'.$agent.$r['check_name'],$agent,'security','warning',$name.' · '.(['firewall'=>'Firewall','disk_encryption'=>'Diskkryptering','antivirus'=>'Antivirus','updates'=>'Opdateringer'][$r['check_name']]??$r['check_name']),'Seneste måling viser deaktiveret beskyttelse eller behov for opmærksomhed. Kilde og måletid fremgår af sikkerhedsrapporten.',$fresh&&in_array($r['state'],['disabled','attention'],true));
    }
    $q=$db->prepare('SELECT COUNT(*) FROM inv_v2_events WHERE agent_id=? AND occurred_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY');$q->execute([$agent]);$count=(int)$q->fetchColumn();
    v2Notice($db,$customer,'changes:'.$agent,$agent,'inventory_change','info',$name.' · inventarændringer',$count.' detaljerede ændringer registreret i de seneste 24 timer.',v2Enabled($db,'changes')&&$count>0);
}
function v2Evaluate(PDO $db):void{
    if(!v2Enabled($db,'notifications'))return;
    $db->exec("UPDATE inv_v2_notices n JOIN inv_agents a ON a.id=n.agent_id SET n.active=0 WHERE a.enabled=0 AND n.active=1");
    foreach($db->query('SELECT a.id FROM inv_agents a JOIN inv_computers c ON c.agent_id=a.id WHERE a.enabled=1')->fetchAll(PDO::FETCH_COLUMN) as $agent)v2EvaluateAgent($db,$agent);
    if(!v2Enabled($db,'licenses')){$db->exec("UPDATE inv_v2_notices SET active=0 WHERE kind IN ('license_expiry','license_capacity')");return;}
    foreach($db->query('SELECT e.*,p.name,(SELECT COUNT(*) FROM inv_v2_assignments x WHERE x.entitlement_id=e.id AND x.active=1) AS allocated FROM inv_v2_entitlements e JOIN inv_v2_products p ON p.id=e.product_id')->fetchAll() as $e){
        $active=!(int)$e['archived'];$expires=$e['expires_on'];$upcoming=$expires!==null&&$expires<=gmdate('Y-m-d',time()+30*86400);
        v2Notice($db,(int)$e['customer_id'],'expiry:'.$e['id'],null,'license_expiry','warning',$e['name'].' · licensudløb','Registreret licensret udløber/udløb '.$expires.'.',$active&&$upcoming);
        v2Notice($db,(int)$e['customer_id'],'allocation:'.$e['id'],null,'license_capacity','warning',$e['name'].' · flere tildelinger end pladser','Registrerede tildelinger: '.$e['allocated'].'; indkøbte pladser: '.$e['seats'].'.',$active&&(int)$e['allocated']>(int)$e['seats']);
    }
}
function v2EvaluateThrottled(PDO $db):void{
    if(!v2Enabled($db,'notifications'))return;
    $q=$db->prepare("UPDATE inv_settings SET value=? WHERE name='v2_rules_last_run' AND CAST(value AS UNSIGNED)<?");$q->execute([(string)time(),time()-300]);if($q->rowCount()===0)return;
    $db->beginTransaction();try{v2Evaluate($db);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('V2 notification evaluation failure: '.get_class($e));}
}
function v2Backfill(PDO $db):int{
    if(!v2Enabled($db))throw new RuntimeException('Enable V2 first');$count=0;
    $agents=$db->query('SELECT agent_id FROM inv_computers ORDER BY agent_id')->fetchAll(PDO::FETCH_COLUMN);
    foreach($agents as $agent){$db->beginTransaction();try{
        $lock=$db->prepare('SELECT id FROM inv_agents WHERE id=? FOR UPDATE');$lock->execute([$agent]);
        $q=$db->prepare('SELECT snapshot,scanned_at FROM inv_scan_history WHERE agent_id=? ORDER BY scanned_at,id');$q->execute([$agent]);$previous=null;$last=null;
        $insert=$db->prepare('INSERT IGNORE INTO inv_v2_events(event_key,agent_id,scan_id,category,operation,item_key,label,before_value,after_value,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        while($row=$q->fetch()){$s=json_decode($row['snapshot'],true,64,JSON_THROW_ON_ERROR);if($last!==null&&$row['scanned_at']<=$last)continue;
            if(v2Enabled($db,'changes'))foreach(v2Diff($previous,$s) as $e)$insert->execute([hash('sha256',$agent.$s['scan_id'].$e['category'].$e['item_key']),$agent,$s['scan_id'],$e['category'],$e['operation'],$e['item_key'],$e['label'],v2Json($e['before']),v2Json($e['after']),$row['scanned_at']]);
            if($previous)foreach(['software','disks','network'] as $category)if(($s['collection'][$category]??'')!=='ok')$s[$category]=$previous[$category]??[];
            $previous=$s;$last=$row['scanned_at'];
        }
        $q=$db->prepare('SELECT snapshot,scanned_at FROM inv_computers WHERE agent_id=?');$q->execute([$agent]);$current=$q->fetch();$s=json_decode($current['snapshot'],true,64,JSON_THROW_ON_ERROR);
        // Last-known software is safe to index even when the latest raw collector failed.
        if(isset($s['software']))$s['collection']['software']='ok';v2ProcessScan($db,$agent,$s,null,$current['scanned_at']);$db->commit();$count++;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    return $count;
}
