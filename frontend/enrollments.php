<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/nav.php';require dirname(__DIR__).'/backend/inventory/src/DeviceIdentity.php';
try{
 $actor=inventoryActor(true);
 webHeaders();
 $error='';
 if(!$actor['admin']||$actor['role']!=='admin')respond(403,['error'=>'admin_required']);
 if(!enabled())respond(503,['error'=>'inventory_disabled']);
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  try{deviceEnrollmentMutate(db(),$actor,$_POST);header('Location: enrollments.php?saved=1',true,303);exit;}
  catch(InvalidArgumentException|DomainException $e){http_response_code(400);$error=$e->getMessage();}
 }elseif(($_SERVER['REQUEST_METHOD']??'')!=='GET')respond(405,['error'=>'method_not_allowed']);
 $customers=inventoryCustomers($actor);$on=deviceEnrollmentEnabled(db());
 $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);if(!$page)throw new InvalidArgumentException();$offset=($page-1)*50;
 $rows=db()->query("SELECT k.*,a.customer_id,a.enabled FROM inv_device_keys k LEFT JOIN inv_agents a ON a.id=k.agent_id ORDER BY (k.state='pending') DESC,k.created_at DESC,k.agent_id LIMIT 51 OFFSET ".$offset)->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
 $audit=db()->query('SELECT * FROM inv_enrollment_audit ORDER BY id DESC LIMIT 30')->fetchAll();
}catch(InvalidArgumentException){respond(400,['error'=>'invalid_request']);}
catch(Throwable $e){error_log('Enrollment management: '.get_class($e));respond(503,['error'=>'enrollment_unavailable']);}
function h(mixed $s):string{return htmlspecialchars((string)($s??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function fields(string $action,string $id=''):void{global $actor;?><input type="hidden" name="csrf_token" value="<?=h($actor['csrf_token'])?>"><input type="hidden" name="action" value="<?=h($action)?>"><input type="hidden" name="agent_id" value="<?=h($id)?>"><?php }
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agentregistrering · PC Inventory</title><link rel="stylesheet" href="inventory.css"></head><body><main><?php invNav($actor, isset($customers) ? $customers : [], isset($filters['customer']) ? (int)$filters['customer'] : 0, 'enrollments'); ?><h1>Agentregistrering</h1>
<?php if($error):?><p role="alert"><?=h($error)?></p><?php endif ?><?php if(($_GET['saved']??'')==='1'):?><p role="status">Ændringen er gemt.</p><?php endif ?>
<p>Installer agenten uden nøgle eller kode. Nye enheder afventer godkendelse her og kan endnu ikke sende inventar eller hente opgaver. Computernavn og platform er oplyst af enheden og er ikke identitetsbevis. Kontrollér enheds-ID fra PC’ens beskyttede status.json via en betroet kanal, før du godkender.</p>
<section class="panel"><form method="post"><?php fields('enrollment-setting');?><label class="enrollment-check"><input type="checkbox" name="enabled" value="1" <?=$on?'checked':''?>>Tillad registrering af nye agenter</label><button>Gem</button></form><p>Ventende registreringer udløber efter 7 dage. Deaktivering stopper registrering; allerede godkendte agenter fortsætter.</p></section>
<?php foreach($rows as $r):$expired=$r['state']==='pending'&&strtotime($r['expires_at'].' UTC')<=time();?><section class="panel"><h2><?=h($r['hostname'])?></h2><p><?=h($r['platform'])?> · <?=h($expired?'Udløbet':['pending'=>'Afventer godkendelse','approved'=>'Godkendt','revoked'=>'Afvist/spærret'][$r['state']])?><?php if($r['state']==='approved'&&!(int)$r['enabled']):?> · Agenten er spærret<?php endif ?></p><p>Enheds-ID: <code><?=h($r['agent_id'])?></code></p><p>Oprettet <?=h($r['created_at'])?> UTC · kunde <?=h($r['customer_id']??'ikke tildelt')?></p>
<?php if($r['state']==='pending'):?>
<?php if(!$expired):?><form method="post"><?php fields('approve',$r['agent_id']);?><label>Kunde<select name="customer_id" required><option value="">Vælg kunde</option><?php foreach($customers as $c):?><option value="<?=h($c['id'])?>"><?=h($c['name'])?></option><?php endforeach ?></select></label><label class="enrollment-check"><input type="checkbox" name="verified" value="1" required>Jeg har kontrolleret enheds-ID på PC’en via en betroet kanal</label><button>Godkend enhed</button></form><?php endif ?>
<form method="post"><?php fields('reject',$r['agent_id']);?><button>Afvis registrering</button></form>
<?php elseif($r['state']==='approved'):?><form method="post"><?php fields('revoke',$r['agent_id']);?><button>Spær enhed</button></form><?php endif ?></section><?php endforeach ?>
<?php if(!$rows):?><p>Ingen agentregistreringer.</p><?php endif ?><nav><?php if($page>1):?><a href="?page=<?=h($page-1)?>">← Forrige</a><?php endif ?> <span>Side <?=h($page)?></span> <?php if($more):?><a href="?page=<?=h($page+1)?>">Næste →</a><?php endif ?></nav>
<details class="panel"><summary>Seneste godkendelser og spærringer</summary><?php foreach($audit as $a):?><p><?=h($a['created_at'])?> UTC · <?=h($a['action'])?> · <?=h($a['agent_id'])?> · administrator <?=h($a['actor_id'])?> · kunde <?=h($a['customer_id'])?></p><?php endforeach ?></details></div></main></body></html>
