<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/InventoryV3.php';
webHeaders();
try{
 if(($_SERVER['HTTPS']??'')!=='on')respond(400,['error'=>'https_required']);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');respond(405,['error'=>'method_not_allowed']);}
 if(!enabled()||!v3Enabled(db()))respond(503,['error'=>'v3_disabled']);$a=v3Agent(db());requestBudget(db(),'control',$a['id'],60,60);
 if(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0])!=='application/json')respond(415,['error'=>'json_required']);
 $raw=file_get_contents('php://input',false,null,0,16385);if($raw===false||strlen($raw)>16384)respond(413,['error'=>'too_large']);$i=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($i))throw new InvalidArgumentException();
 $op=$i['op']??'';
 if($op==='claim'){
  $version=$i['version']??'';if(!is_string($version)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-dev)?$/D',$version))throw new InvalidArgumentException();
  db()->prepare('INSERT INTO inv_v3_devices(agent_id,last_control_at,reported_version) VALUES(?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE last_control_at=UTC_TIMESTAMP(),reported_version=VALUES(reported_version)')->execute([$a['id'],$version]);respond(200,['job'=>v3Claim(db(),$a['id'])]);
 }
 if($op==='complete'){v3Complete(db(),$a['id'],$i);respond(200,['accepted'=>true]);}
 if($op==='update')respond(200,['release'=>v3Update(db(),$a['id'],(string)($i['platform']??''),(string)($i['architecture']??''))]);
 if($op==='artifact'){
  requestBudget(db(),'artifact',$a['id'],4,3600);
  if(!v3Enabled(db(),'updates'))respond(503,['error'=>'updates_disabled']);
  $q=db()->prepare('SELECT updates_enabled FROM inv_v3_devices WHERE agent_id=?');$q->execute([$a['id']]);if((int)$q->fetchColumn()!==1)respond(403,['error'=>'updates_disabled']);
  $id=$i['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException();
  $q=db()->prepare('SELECT artifact FROM inv_v3_releases WHERE id=? AND enabled=1');$q->execute([$id]);$file=$q->fetchColumn();if(!is_string($file)||!preg_match('/^[a-f0-9]{32}\.bin$/D',$file))respond(404,['error'=>'not_found']);
  $path=v3ReleaseDir().'/'.$file;if(!is_file($path)||is_link($path))respond(404,['error'=>'not_found']);header('Content-Type: application/octet-stream');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
 throw new InvalidArgumentException();
}catch(RequestRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}catch(InvalidArgumentException|JsonException){respond(400,['error'=>'invalid_request']);}catch(DomainException){respond(403,['error'=>'access_denied']);}catch(Throwable $e){error_log('Inventory V3 agent: '.get_class($e));respond(503,['error'=>'v3_unavailable']);}
