<?php
declare(strict_types=1);

function deviceEnrollmentEnabled(PDO $d):bool {
 return $d->query("SELECT value FROM inv_settings WHERE name='enrollment_enabled'")->fetchColumn()==='1';
}
function devicePublicIdentity(string $pem):string {
 if(strlen($pem)>512)throw new InvalidArgumentException('Invalid key');
 $key=openssl_pkey_get_public($pem);if(!$key)throw new InvalidArgumentException('Invalid key');
 $details=openssl_pkey_get_details($key);
 if(!$details||$details['type']!==OPENSSL_KEYTYPE_EC||($details['ec']['curve_name']??'')!=='prime256v1')throw new InvalidArgumentException('P-256 required');
 $der=base64_decode(preg_replace('/-----[^-]+-----|\s/','',$details['key']),true);
 if($der===false)throw new InvalidArgumentException('Invalid key');
 return substr(hash('sha256',$der),0,32);
}
function deviceVerifySignature(string $id,string $public,string $operation,string $raw,array $headers,?int $now=null):string {
 $timestamp=$headers['HTTP_X_DEVICE_TIME']??'';$nonce=$headers['HTTP_X_DEVICE_NONCE']??'';$signature=$headers['HTTP_X_DEVICE_SIGNATURE']??'';
 if(!is_string($timestamp)||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs(($now??time())-(int)$timestamp)>300||!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce)||!is_string($signature)||strlen($signature)>128)throw new DomainException('Invalid signature');
 $sig=base64_decode($signature,true);if($sig===false)throw new DomainException('Invalid signature');
 $message="inventory-device-v1\n".$id."\n".$operation."\n".$timestamp."\n".$nonce."\n".hash('sha256',$raw);
 if(openssl_verify($message,$sig,$public,OPENSSL_ALGO_SHA256)!==1)throw new DomainException('Invalid signature');
 return $nonce;
}
function deviceConsumeNonce(PDO $d,string $id,string $nonce):void {
 // Retain nonces longer than the entire permitted clock-skew window.
 $d->exec('DELETE FROM inv_device_nonces WHERE expires_at<UNIX_TIMESTAMP() LIMIT 100');
 try{$d->prepare('INSERT INTO inv_device_nonces(agent_id,nonce,expires_at) VALUES(?,?,?)')->execute([$id,$nonce,time()+660]);}
 catch(PDOException $e){if($e->getCode()==='23000')throw new DomainException('Replayed request');throw $e;}
}
function deviceAuthenticate(PDO $d,string $operation,int $maximum):array {
 $id=$_SERVER['HTTP_X_AGENT_ID']??'';$auth=$_SERVER['HTTP_AUTHORIZATION']??'';
 if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Unauthorized');
 $q=$d->prepare('SELECT id,customer_id,token_hash,enabled FROM inv_agents WHERE id=?');$q->execute([$id]);$agent=$q->fetch();
 if(!$agent||!(int)$agent['enabled'])throw new DomainException('Unauthorized');
 if($auth!==''){
  if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)||!hash_equals($agent['token_hash'],hash('sha256',$m[1])))throw new DomainException('Unauthorized');
 }else{
  $q=$d->prepare("SELECT public_key FROM inv_device_keys WHERE agent_id=? AND state='approved'");$q->execute([$id]);$public=$q->fetchColumn();if(!$public)throw new DomainException('Unauthorized');
  requestBudget($d,'signature',$id,120,60);
  $raw=file_get_contents('php://input',false,null,0,$maximum+1);if($raw===false||strlen($raw)>$maximum)respond(413,['error'=>'payload_too_large']);
  $nonce=deviceVerifySignature($id,$public,$operation,$raw,$_SERVER);deviceConsumeNonce($d,$id,$nonce);
 }
 return $agent;
}
function deviceEnroll(PDO $d,array $input,string $raw,array $headers):array {
 $id=$headers['HTTP_X_AGENT_ID']??'';$public=$input['public_key']??null;$hostname=$input['hostname']??null;$platform=$input['platform']??null;
 if(!is_string($public)||!is_string($hostname)||strlen($hostname)<1||strlen($hostname)>255||preg_match('/[\x00-\x1f\x7f]/',$hostname)||!is_string($platform)||!in_array($platform,['windows','darwin','linux'],true)||!is_string($id)||devicePublicIdentity($public)!==$id)throw new InvalidArgumentException('Invalid registration');
 $nonce=deviceVerifySignature($id,$public,'enroll',$raw,$headers);deviceConsumeNonce($d,$id,$nonce);
 $d->beginTransaction();try{
  // Serialize new admissions and enforce a global pending limit under concurrency.
  $q=$d->query("SELECT value FROM inv_settings WHERE name='enrollment_enabled' FOR UPDATE");$enrollmentOn=$q->fetchColumn()==='1';
  $q=$d->prepare('SELECT * FROM inv_device_keys WHERE agent_id=? FOR UPDATE');$q->execute([$id]);$existing=$q->fetch();
  if(!$existing){
   if(!$enrollmentOn)throw new DomainException('Enrollment disabled');
   if((int)$d->query("SELECT COUNT(*) FROM inv_device_keys WHERE state='pending'")->fetchColumn()>=1000)throw new RequestRateLimited();
   $d->prepare("INSERT INTO inv_device_keys(agent_id,public_key,hostname,platform,state,created_at,expires_at) VALUES(?,?,?,?,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 7 DAY)")->execute([$id,$public,$hostname,$platform]);$state='pending';
  }else{
   if($existing['public_key']!==$public)throw new DomainException('Identity mismatch');$state=$existing['state'];
   if($state==='pending'&&strtotime($existing['expires_at'].' UTC')<=time())$state='expired';
   if($state==='approved'){$q=$d->prepare('SELECT enabled FROM inv_agents WHERE id=?');$q->execute([$id]);if((int)$q->fetchColumn()!==1)$state='revoked';}
  }
  $d->commit();return ['agent_id'=>$id,'state'=>$state];
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
function deviceEnrollmentMutate(PDO $d,array $actor,array $i):void {
 // Unassigned requests are visible only to global administrators.
 if(!($actor['admin']??false)||($actor['role']??'')!=='admin'||!is_string($i['csrf_token']??null)||!hash_equals($actor['csrf_token'],$i['csrf_token']))throw new DomainException('Adgang afvist.');
 $action=$i['action']??'';
 if($action==='enrollment-setting'){
  $d->prepare("UPDATE inv_settings SET value=? WHERE name='enrollment_enabled'")->execute([($i['enabled']??'')==='1'?'1':'0']);return;
 }
 $id=$i['agent_id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($action,['approve','reject','revoke'],true))throw new InvalidArgumentException('Ugyldig handling.');
 $customer=null;
 if($action==='approve'){
  if(($i['verified']??'')!=='1')throw new DomainException('Kontrollér enheds-ID på PC’en via en betroet kanal før godkendelse.');
  $customer=filter_var($i['customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$allowed=false;
  foreach(inventoryCustomers($actor) as $c)if((int)$c['id']===$customer)$allowed=true;
  if(!$customer||!$allowed)throw new DomainException('Ugyldig kunde.');
 }
 $d->beginTransaction();try{
  $q=$d->prepare('SELECT * FROM inv_device_keys WHERE agent_id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new DomainException('Registreringen findes ikke.');
  if($action==='approve'){
   if($r['state']!=='pending'||strtotime($r['expires_at'].' UTC')<=time())throw new DomainException('Registreringen kan ikke godkendes.');
   // Random, discarded token: signed devices never receive or use a bearer credential.
   $d->prepare('INSERT INTO inv_agents(id,token_hash,customer_id,label,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))')->execute([$id,hash('sha256',random_bytes(32)),$customer,$r['hostname']]);
   $d->prepare("UPDATE inv_device_keys SET state='approved',approved_by=?,approved_at=UTC_TIMESTAMP() WHERE agent_id=?")->execute([$actor['id'],$id]);
  }else{
   if($action==='reject'&&$r['state']!=='pending')throw new DomainException('Kun ventende registreringer kan afvises.');
   $q=$d->prepare('SELECT customer_id FROM inv_agents WHERE id=?');$q->execute([$id]);$customer=$q->fetchColumn()?:null;
   $d->prepare("UPDATE inv_device_keys SET state='revoked',revoked_at=UTC_TIMESTAMP() WHERE agent_id=?")->execute([$id]);
   $d->prepare('UPDATE inv_agents SET enabled=0 WHERE id=?')->execute([$id]);
  }
  $d->prepare('INSERT INTO inv_enrollment_audit(agent_id,actor_id,customer_id,action,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$id,$actor['id'],$customer,$action]);$d->commit();
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
