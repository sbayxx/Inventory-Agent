<?php
declare(strict_types=1);
require_once __DIR__.'/InventoryV3.php';
// CLI only. Signature key is public; signing is always performed offline.
function v3ImportRelease(PDO $d,string $envelopePath,string $binaryPath,string $publicKey):string{
 if(PHP_SAPI!=='cli')throw new DomainException('CLI only');
 if(!function_exists('sodium_crypto_sign_verify_detached'))throw new RuntimeException('Sodium verification required on release publisher');
 $e=json_decode(file_get_contents($envelopePath),true,16,JSON_THROW_ON_ERROR);$raw=base64_decode($e['manifest']??'',true);$sig=base64_decode($e['signature']??'',true);$key=base64_decode(trim($publicKey),true);
 if($raw===false||strlen($raw)>4096||$sig===false||strlen($sig)!==64||$key===false||strlen($key)!==32||!sodium_crypto_sign_verify_detached($sig,$raw,$key))throw new InvalidArgumentException('Invalid release signature');
 $m=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
 if(!preg_match('/^[a-f0-9]{32}$/D',$m['id']??'')||!preg_match('/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$/D',$m['version']??'')||!in_array($m['platform']??'', ['windows','darwin','linux'],true)||!in_array($m['architecture']??'', ['amd64','arm64'],true)||!is_int($m['size']??null)||$m['size']<1||$m['size']>64*1024*1024||strtotime($m['expires_at']??'')<=time()||!preg_match('/^[a-f0-9]{64}$/D',$m['sha256']??''))throw new InvalidArgumentException('Invalid release manifest');
 if(filesize($binaryPath)!==$m['size']||!hash_equals($m['sha256'],hash_file('sha256',$binaryPath)))throw new InvalidArgumentException('Invalid release payload');
 $artifact=$m['id'].'.bin';
 $q=$d->prepare('SELECT manifest,signature FROM inv_v3_releases WHERE id=? OR (version=? AND platform=? AND architecture=?)');$q->execute([$m['id'],$m['version'],$m['platform'],$m['architecture']]);$old=$q->fetch();if($old){if($old['manifest']!==$raw||$old['signature']!==$e['signature'])throw new DomainException('Release is immutable');return $m['id'];}
 $d->beginTransaction();try{$d->prepare('INSERT INTO inv_v3_releases(id,version,platform,architecture,manifest,signature,artifact,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$m['id'],$m['version'],$m['platform'],$m['architecture'],$raw,$e['signature'],$artifact]);v3Audit($d,0,null,null,'release_imported',['id'=>$m['id'],'version'=>$m['version'],'platform'=>$m['platform'],'architecture'=>$m['architecture']]);$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}return $m['id'];
}
