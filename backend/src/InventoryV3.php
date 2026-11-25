<?php
declare(strict_types=1);
require_once __DIR__.'/InventoryV2.php';
const V3_FEATURES=['updates','commands','integrations','remote'];
function v3Enabled(PDO $d,?string $feature=null):bool{
 if($feature!==null&&!in_array($feature,V3_FEATURES,true))return false;
 $q=$d->prepare("SELECT name,value FROM inv_settings WHERE name IN ('v3_enabled',?)");$q->execute(['v3_'.($feature??'enabled')]);$f=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 return ($f['v3_enabled']??'0')==='1'&&($feature===null||($f['v3_'.$feature]??'0')==='1');
}
function v3Audit(PDO $d,int $customer,?int $actor,?string $agent,string $action,array $details=[]):void{
 $d->prepare('INSERT INTO inv_v3_audit(customer_id,actor_id,agent_id,action,details,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')->execute([$customer,$actor,$agent,$action,v2Json($details)]);
}
function v3Device(PDO $d,array $actor,string $id):array{
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Ugyldig enhed.');
 [$scope,$p]=inventoryScope($actor);$q=$d->prepare('SELECT a.id,a.customer_id,a.enabled,c.hostname FROM inv_agents a LEFT JOIN inv_computers c ON c.agent_id=a.id WHERE '.$scope.' AND a.id=?');$q->execute([...$p,$id]);$r=$q->fetch();if(!$r)throw new DomainException('Enheden er ikke tilgængelig.');return $r;
}
function v3RemoteTarget(string $provider,string $value,int $port=5900):string{
 if($provider==='teamviewer'){
  $value=str_replace(' ','',$value);if(!preg_match('/^[0-9]{6,12}$/D',$value))throw new InvalidArgumentException('Ugyldigt TeamViewer-ID.');
  return 'https://start.teamviewer.com/'.$value;
 }
 if($provider!=='vnc'||$port<1||$port>65535||strlen($value)>253)throw new InvalidArgumentException('Ugyldig VNC-adresse.');
 if(filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))$value='['.$value.']';
 elseif(!filter_var($value,FILTER_VALIDATE_IP)&&!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD',$value))throw new InvalidArgumentException('Angiv kun værtsnavn eller IP uden adgangskode.');
 return 'vnc://'.$value.':'.$port;
}
function v3Mutate(PDO $d,array $actor,array $i):?string{
 if(!hash_equals($actor['csrf_token'],is_string($i['csrf_token']??null)?$i['csrf_token']:''))throw new DomainException('Formularen er udløbet.');
 if(!v2Manage($actor))throw new DomainException('Administrator kræves.');
 $action=$i['action']??'';
 if($action==='settings'){
  if(!$actor['admin'])throw new DomainException('Global administrator kræves.');
  $d->beginTransaction();try{foreach(['enabled',...V3_FEATURES] as $f)$d->prepare('UPDATE inv_settings SET value=? WHERE name=?')->execute([($i[$f]??'')==='1'?'1':'0','v3_'.$f]);v3Audit($d,0,$actor['id'],null,'settings');$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}return null;
 }
 if(!v3Enabled($d))throw new DomainException('Version 3 er deaktiveret.');
 if($action==='token'){
  if(!v3Enabled($d,'integrations'))throw new DomainException('Integration er deaktiveret.');
  $customer=filter_var($i['customer_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$label=trim((string)($i['label']??''));
  if(!$customer||!v2CustomerAllowed($actor,$customer)||$label===''||strlen($label)>120)throw new DomainException('Kunde eller navn afvist.');
  $count=$d->prepare('SELECT COUNT(*) FROM inv_v3_tokens WHERE customer_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');$count->execute([$customer]);if((int)$count->fetchColumn()>=20)throw new DomainException('Højst 20 aktive API-nøgler pr. kunde.');
  $token=bin2hex(random_bytes(32));$d->beginTransaction();try{$d->prepare('INSERT INTO inv_v3_tokens(id,customer_id,label,token_hash,created_by,created_at,expires_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 90 DAY)')->execute([bin2hex(random_bytes(16)),$customer,$label,hash('sha256',$token),$actor['id']]);v3Audit($d,$customer,$actor['id'],null,'token_created',['label'=>$label]);$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}return $token;
 }
 if($action==='revoke-token'){
  if(!v3Enabled($d,'integrations'))throw new DomainException('Integration er deaktiveret.');
  $q=$d->prepare('SELECT customer_id FROM inv_v3_tokens WHERE id=?');$q->execute([(string)($i['id']??'')]);$customer=$q->fetchColumn();if(!$customer||!v2CustomerAllowed($actor,(int)$customer))throw new DomainException('Adgang afvist.');
  $d->beginTransaction();try{$d->prepare('UPDATE inv_v3_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=?')->execute([$i['id']]);v3Audit($d,(int)$customer,$actor['id'],null,'token_revoked',['id'=>$i['id']]);$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}return null;
 }
 if($action==='release'){
  if(!$actor['admin']||!v3Enabled($d,'updates'))throw new DomainException('Global administrator kræves.');
  $id=(string)($i['id']??'');$on=($i['enabled']??'')==='1'?1:0;
  $d->beginTransaction();try{$d->prepare('UPDATE inv_v3_releases SET enabled=? WHERE id=?')->execute([$on,$id]);v3Audit($d,0,$actor['id'],null,'release',['id'=>$id,'enabled'=>$on]);$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}return null;
 }
 $device=v3Device($d,$actor,(string)($i['agent_id']??''));$id=$device['id'];$customer=(int)$device['customer_id'];
 $d->beginTransaction();try{
  [$scope,$params]=inventoryScope($actor);$q=$d->prepare('SELECT a.customer_id,a.enabled FROM inv_agents a WHERE '.$scope.' AND a.id=? FOR UPDATE');$q->execute([...$params,$id]);$locked=$q->fetch();if(!$locked)throw new DomainException('Enhedens kundeadgang er ændret.');$device['enabled']=$locked['enabled'];$customer=(int)$locked['customer_id'];
  $d->prepare('INSERT IGNORE INTO inv_v3_devices(agent_id) VALUES(?)')->execute([$id]);
  if($action==='device'){
   if(!v3Enabled($d,'updates')&&!v3Enabled($d,'commands'))throw new DomainException('Enhedsstyring er deaktiveret.');
   $d->prepare('UPDATE inv_v3_devices SET updates_enabled=?,commands_enabled=? WHERE agent_id=?')->execute([($i['updates']??'')==='1'?1:0,($i['commands']??'')==='1'?1:0,$id]);
  }elseif($action==='remote'){
   if(!v3Enabled($d,'remote'))throw new DomainException('Fjernsupport er deaktiveret.');
   $tv=str_replace(' ','',trim((string)($i['teamviewer_id']??'')));$host=trim((string)($i['vnc_host']??''));$port=filter_var($i['vnc_port']??5900,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if(!$port)throw new InvalidArgumentException('Ugyldig port.');
   if($tv!=='')v3RemoteTarget('teamviewer',$tv);if($host!=='')v3RemoteTarget('vnc',$host,$port);
   $d->prepare('UPDATE inv_v3_devices SET teamviewer_id=?,vnc_host=?,vnc_port=? WHERE agent_id=?')->execute([$tv,$host,$port,$id]);
  }elseif($action==='job'){
   if(!v3Enabled($d,'commands')||!(int)$device['enabled'])throw new DomainException('Kommandoer er deaktiveret.');
   $q=$d->prepare('SELECT commands_enabled FROM inv_v3_devices WHERE agent_id=?');$q->execute([$id]);if(!(int)$q->fetchColumn())throw new DomainException('Kommandoer er deaktiveret på enheden.');
   $kind=$i['kind']??'';$key=$i['request_key']??'';if(!in_array($kind,['scan_now','flush_queue'],true)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Ugyldig opgave.');
   $q=$d->prepare('SELECT agent_id,kind FROM inv_v3_jobs WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();if($old){if($old['agent_id']!==$id||$old['kind']!==$kind)throw new DomainException('Konfliktende opgave.');$d->commit();return null;}
   $q=$d->prepare('SELECT COUNT(*) FROM inv_v3_jobs WHERE agent_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR');$q->execute([$id]);if((int)$q->fetchColumn()>=10)throw new DomainException('Højst 10 opgaver pr. enhed i timen.');
   $d->prepare("INSERT INTO inv_v3_jobs(id,agent_id,request_key,kind,state,created_by,created_at,expires_at) VALUES(?,?,?,?,'pending',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 1 DAY)")->execute([bin2hex(random_bytes(16)),$id,$key,$kind,$actor['id']]);
  }elseif($action==='cancel'){
   $d->prepare("UPDATE inv_v3_jobs SET state='cancelled' WHERE id=? AND agent_id=? AND state='pending'")->execute([(string)($i['id']??''),$id]);
  }elseif($action==='launch'){
   if(!v3Enabled($d,'remote'))throw new DomainException('Fjernsupport er deaktiveret.');
   $q=$d->prepare('SELECT * FROM inv_v3_devices WHERE agent_id=?');$q->execute([$id]);$r=$q->fetch();$provider=$i['provider']??'';
   $target=v3RemoteTarget($provider,$provider==='teamviewer'?$r['teamviewer_id']:$r['vnc_host'],(int)$r['vnc_port']);
   v3Audit($d,$customer,$actor['id'],$id,'remote_launch_requested',['provider'=>$provider]);$d->commit();return $target;
  }else throw new InvalidArgumentException('Ukendt handling.');
  v3Audit($d,$customer,$actor['id'],$id,$action);$d->commit();return null;
 }catch(Throwable $e){$d->rollBack();throw $e;}
}
function v3Claim(PDO $d,string $agent):?array{
 if(!v3Enabled($d,'commands'))return null;
 $d->beginTransaction();try{
  $q=$d->prepare('SELECT id FROM inv_agents WHERE id=? AND enabled=1 FOR UPDATE');$q->execute([$agent]);if(!$q->fetchColumn())throw new DomainException('Agent disabled');
  $q=$d->prepare('SELECT commands_enabled FROM inv_v3_devices WHERE agent_id=?');$q->execute([$agent]);if(($flag=$q->fetchColumn())!==false&&(int)$flag===0){$d->commit();return null;}
  $d->prepare("UPDATE inv_v3_jobs SET state='expired' WHERE agent_id=? AND state IN ('pending','leased') AND expires_at<=UTC_TIMESTAMP()")->execute([$agent]);
  $d->prepare("UPDATE inv_v3_jobs SET state='failed' WHERE agent_id=? AND state='leased' AND lease_until<UTC_TIMESTAMP() AND attempts>=3")->execute([$agent]);
  $q=$d->prepare("SELECT id,kind FROM inv_v3_jobs WHERE agent_id=? AND expires_at>UTC_TIMESTAMP() AND attempts<3 AND (state='pending' OR (state='leased' AND lease_until<UTC_TIMESTAMP())) ORDER BY created_at,id LIMIT 1 FOR UPDATE");$q->execute([$agent]);$r=$q->fetch();if(!$r){$d->commit();return null;}
  $lease=bin2hex(random_bytes(32));$d->prepare("UPDATE inv_v3_jobs SET state='leased',attempts=attempts+1,lease_hash=?,lease_until=UTC_TIMESTAMP()+INTERVAL 10 MINUTE WHERE id=?")->execute([hash('sha256',$lease),$r['id']]);$d->commit();return $r+['lease'=>$lease];
 }catch(Throwable $e){$d->rollBack();throw $e;}
}
function v3Complete(PDO $d,string $agent,array $i):void{
 if(!v3Enabled($d,'commands'))throw new DomainException('Commands disabled');
 $id=$i['id']??'';$lease=$i['lease']??'';$state=$i['state']??'';$result=$i['result']??[];
 if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_string($lease)||!preg_match('/^[a-f0-9]{64}$/D',$lease)||!in_array($state,['succeeded','failed'],true)||!is_array($result)||strlen(v2Json($result))>2048||array_diff(array_keys($result),['scan_id','message']))throw new InvalidArgumentException('Invalid completion');
 foreach($result as $v)if(!is_string($v)||strlen($v)>255)throw new InvalidArgumentException('Invalid result');
 $d->beginTransaction();try{$q=$d->prepare('SELECT j.*,a.customer_id FROM inv_v3_jobs j JOIN inv_agents a ON a.id=j.agent_id WHERE j.id=? AND j.agent_id=? FOR UPDATE');$q->execute([$id,$agent]);$r=$q->fetch();if(!$r||!hash_equals($r['lease_hash']??'',hash('sha256',$lease)))throw new DomainException('Invalid lease');
 if(in_array($r['state'],['succeeded','failed'],true)){$d->commit();return;}
 if($r['state']!=='leased'||strtotime($r['expires_at'].' UTC')<=time()||strtotime($r['lease_until'].' UTC')<=time())throw new DomainException('Expired lease');
 $d->prepare('UPDATE inv_v3_jobs SET state=?,result=?,completed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$state,v2Json($result),$id]);v3Audit($d,(int)$r['customer_id'],null,$agent,'job_'.$state,['id'=>$id,'kind'=>$r['kind']]);$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}
}
function v3Agent(PDO $d):array{
 require_once __DIR__.'/DeviceIdentity.php';return deviceAuthenticate($d,'control',16384);
}
function v3Update(PDO $d,string $agent,string $platform,string $arch):?array{
 if(!v3Enabled($d,'updates'))return null;
 if(!in_array($platform,['windows','darwin','linux'],true)||!in_array($arch,['amd64','arm64'],true))throw new InvalidArgumentException('Invalid platform');
 $q=$d->prepare('SELECT updates_enabled FROM inv_v3_devices WHERE agent_id=?');$q->execute([$agent]);if((int)$q->fetchColumn()!==1)return null;
 $q=$d->prepare('SELECT manifest,signature FROM inv_v3_releases WHERE enabled=1 AND platform=? AND architecture=? ORDER BY CAST(SUBSTRING_INDEX(version,\'.\',1) AS UNSIGNED) DESC,CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(version,\'.\',2),\'.\',-1) AS UNSIGNED) DESC,CAST(SUBSTRING_INDEX(version,\'.\',-1) AS UNSIGNED) DESC,created_at DESC,id DESC LIMIT 1');$q->execute([$platform,$arch]);$r=$q->fetch();return $r?['manifest'=>base64_encode($r['manifest']),'signature'=>$r['signature']]:null;
}
final class V3RateLimited extends RuntimeException {}
function v3IntegrationAuth(PDO $d,string $token):array{
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DomainException('Invalid token');
 $d->beginTransaction();try{
 $q=$d->prepare('SELECT id,customer_id FROM inv_v3_tokens WHERE token_hash=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');$q->execute([hash('sha256',$token)]);$r=$q->fetch();if(!$r)throw new DomainException('Invalid token');
 $d->prepare('UPDATE inv_v3_tokens SET last_used_at=UTC_TIMESTAMP(),rate_count=IF(rate_window IS NULL OR rate_window<UTC_TIMESTAMP()-INTERVAL 1 MINUTE,1,rate_count+1),rate_window=IF(rate_window IS NULL OR rate_window<UTC_TIMESTAMP()-INTERVAL 1 MINUTE,UTC_TIMESTAMP(),rate_window) WHERE id=?')->execute([$r['id']]);
 $q=$d->prepare('SELECT rate_count FROM inv_v3_tokens WHERE id=?');$q->execute([$r['id']]);$count=(int)$q->fetchColumn();$d->commit();if($count>120)throw new V3RateLimited('Integration rate limit');return $r;
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
function v3IntegrationRead(PDO $d,int $customer,array $i):array{
 $resource=$i['resource']??'computers';$cursor=$i['cursor']??'';$limit=$i['limit']??'100';
 if(!is_string($cursor)||($cursor!==''&&!preg_match('/^[a-f0-9]{32}$/D',$cursor))||!is_string($limit)||!preg_match('/^[0-9]{1,3}$/D',$limit)||(int)$limit<1||(int)$limit>100)throw new InvalidArgumentException('Invalid pagination');
 if($resource==='computers'){
  $q=$d->prepare('SELECT c.agent_id,c.hostname,c.os,c.agent_version,c.scanned_at,a.last_seen FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE a.customer_id=? AND c.agent_id>? ORDER BY c.agent_id LIMIT '.((int)$limit+1));$q->execute([$customer,$cursor]);$rows=$q->fetchAll();$more=count($rows)>(int)$limit;$rows=array_slice($rows,0,(int)$limit);return ['schema'=>1,'data'=>$rows,'next_cursor'=>$more?end($rows)['agent_id']:null];
 }
 if($resource!=='computer'||!is_string($i['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$i['id']))throw new InvalidArgumentException('Invalid resource');
 $q=$d->prepare('SELECT c.agent_id,c.scanned_at,c.snapshot FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE a.customer_id=? AND c.agent_id=?');$q->execute([$customer,$i['id']]);$r=$q->fetch();if(!$r)throw new DomainException('Device unavailable');$s=json_decode($r['snapshot'],true,64,JSON_THROW_ON_ERROR);
 return ['schema'=>1,'agent_id'=>$r['agent_id'],'scanned_at'=>$r['scanned_at'],'data'=>array_intersect_key($s,array_flip(['computer','hardware','disks','network','network_config','software','security','collection']))];
}

function v3ReleaseDir():string{return dirname(__DIR__).'/releases';}
