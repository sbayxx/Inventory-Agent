<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/nav.php';require dirname(__DIR__).'/backend/inventory/src/SoftwareView.php';
try{
 $actor=inventoryActor(true);
 webHeaders(true);if(!enabled())respond(503,['error'=>'inventory_disabled']);$ready=softwareReady(db());
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  if(!$ready)respond(503,['error'=>'software_migration_required']);
  if(!is_string($_POST['csrf_token']??null)||!hash_equals($actor['csrf_token'],$_POST['csrf_token']))respond(403,['error'=>'access_denied']);
  if(($_POST['action']??'')==='refresh'){
   if(!$actor['admin']||$actor['role']!=='admin')respond(403,['error'=>'access_denied']);
   requestBudget(db(),'software-refresh',(string)$actor['id'],30,60);respond(200,softwareRefresh(db(),$actor['id']));
  }
  if(!in_array($_POST['action']??'', ['classify','reset'],true))respond(400,['error'=>'invalid_action']);
  softwareSaveOverride(db(),$actor,$_POST);header('Location: software.php?edit='.rawurlencode($_POST['agent']).'&ordinal='.(int)$_POST['ordinal'].'&saved=1',true,303);exit;
 }
 if(($_SERVER['REQUEST_METHOD']??'')!=='GET')respond(405,['error'=>'method_not_allowed']);
 foreach(['edit','ordinal','customer','product','saved'] as $k)if(isset($_GET[$k])&&!is_string($_GET[$k]))throw new InvalidArgumentException();
 $edit=$_GET['edit']??'';$product=$_GET['product']??'';$customer=$_GET['customer']??'';
 if($product!==''&&!preg_match('/^[a-f0-9]{64}$/D',$product))throw new InvalidArgumentException();
 if($customer!==''&&!preg_match('/^[1-9][0-9]{0,17}$/D',$customer))throw new InvalidArgumentException();
 $canEdit=$ready&&in_array($actor['role'],['admin','customer_admin'],true);
 $customers=inventoryCustomers($actor);$editRow=null;$truncated=false;$rows=[];$groups=[];
 if($edit!==''){
  $ordinal=filter_var($_GET['ordinal']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>20000]]);if($ordinal===false)throw new InvalidArgumentException();
  $editRow=softwareScopedRow(db(),$actor,$edit,$ordinal);if(!$editRow)respond(404,['error'=>'not_found']);
  $raw=json_decode($editRow['data'],true,32,JSON_THROW_ON_ERROR);$context=softwareContext(db(),[(int)$editRow['customer_id']]);$classified=softwareClassify($raw,$context,(int)$editRow['customer_id']);
 }else{
  [$scope,$params]=inventoryScope($actor);if($customer!==''){$scope.=' AND a.customer_id=?';$params[]=$customer;}
  $q=db()->prepare('SELECT s.agent_id,s.ordinal,s.data,a.customer_id,c.hostname FROM inv_software s JOIN inv_agents a ON a.id=s.agent_id JOIN inv_computers c ON c.agent_id=a.id WHERE ('.$scope.') ORDER BY s.agent_id,s.ordinal LIMIT 20001');$q->execute($params);$observations=$q->fetchAll();
  $truncated=count($observations)>20000;if($truncated)array_pop($observations);$context=softwareContext(db(),array_column($observations,'customer_id'));
  foreach($observations as $o){
   $r=softwareClassify(json_decode($o['data'],true,32,JSON_THROW_ON_ERROR),$context,(int)$o['customer_id']);$r+=['agent_id'=>$o['agent_id'],'ordinal'=>(int)$o['ordinal'],'hostname'=>$o['hostname'],'can_edit'=>$canEdit];$key=softwareProductKey($r);
   if($product!==''&&$product!==$key)continue;
   $rows[]=$r;
   if(!isset($groups[$key]))$groups[$key]=$r+['installations'=>0,'versions'=>[],'raw_names'=>[]];
   $groups[$key]['installations']++;$groups[$key]['versions'][$r['version']?:'Ukendt']=true;$groups[$key]['raw_names'][$r['raw_name']]=true;
  }
  foreach($groups as &$g){$g['versions']=array_keys($g['versions']);natcasesort($g['versions']);}unset($g);
 }
}catch(RequestRateLimited){header('Retry-After: 60');respond(429,['error'=>'rate_limited']);}
catch(DomainException){respond(403,['error'=>'access_denied_or_inventory_changed']);}
catch(InvalidArgumentException){respond(400,['error'=>'invalid_input']);}
catch(Throwable $e){error_log('Software catalogue: '.get_class($e));respond(503,['error'=>'software_catalog_unavailable']);}
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Software · ITSM</title><link rel="stylesheet" href="inventory.css?v=<?=softwareH(substr(hash_file('sha256',__DIR__.'/inventory.css'),0,12))?>"><script defer src="software.js?v=<?=softwareH(substr(hash_file('sha256',__DIR__.'/software.js'),0,12))?>"></script></head><body><main>
<?php invNav($actor, isset($customers) ? $customers : [], isset($filters['customer']) ? (int)$filters['customer'] : 0, 'software'); ?><nav><a href="./">Computere</a><a href="software.php">Softwareoversigt</a></nav><h1>Software</h1>
<p>Normaliserede produkter med kategorier, producenter og kilder. Oprindelige navne og installerede versioner bevares.</p>
<?php if(!$ready):?><p class="notice">Kataloget kan læses. Databaseopdatering 008 kræves for manuelle rettelser og opdatering fra internettet.</p><?php endif ?>
<?php if($editRow): ?>
<section class="panel"><h2>Ret klassificering</h2><p>Registreret navn: <strong><?=softwareH($classified['raw_name'])?></strong> · Producent: <?=softwareH($classified['raw_publisher']?:'Ukendt')?> · Version: <?=softwareH($classified['version']?:'Ukendt')?></p><p>Rettelsen gælder samme registrerede navn og producent hos denne kunde. Versioner og andre kunders klassificering ændres ikke.</p>
<?php if(($_GET['saved']??'')==='1'):?><p role="status">Klassificeringen er gemt.</p><?php endif ?>
<?php if($canEdit):?><form method="post"><input type="hidden" name="csrf_token" value="<?=softwareH($actor['csrf_token'])?>"><input type="hidden" name="agent" value="<?=softwareH($edit)?>"><input type="hidden" name="ordinal" value="<?=(int)$ordinal?>"><input type="hidden" name="fingerprint" value="<?=softwareH($classified['fingerprint'])?>">
<label>Standardnavn<input name="name" maxlength="255" required value="<?=softwareH($classified['name'])?>"></label><label>Producent<input name="publisher" maxlength="255" value="<?=softwareH($classified['publisher'])?>"></label>
<label>Kategori<select name="category" data-software-edit-category><?php foreach(softwareCategories() as $cat):?><option value="<?=softwareH($cat['id'])?>" <?=$cat['id']===$classified['category']?'selected':''?>><?=softwareH($cat['label'])?></option><?php endforeach ?></select></label>
<label>Underkategori<select name="subcategory" data-software-edit-subcategory><option value="">Ingen underkategori</option><?php foreach(softwareCategories() as $cat)foreach($cat['subcategories'] as $sub=>$label):?><option data-category="<?=softwareH($cat['id'])?>" value="<?=softwareH($sub)?>" <?=$cat['id']===$classified['category']&&$sub===$classified['subcategory']?'selected':''?>><?=softwareH($cat['label'].' → '.$label)?></option><?php endforeach ?></select></label>
<button name="action" value="classify">Gem klassificering</button><button name="action" value="reset" formnovalidate>Brug automatisk katalog igen</button></form><?php else:?><p>Kun administratorer kan rette klassificeringer.</p><?php endif ?></section>
<?php else: ?>
<form method="get" class="list-controls"><label>Kunde<select name="customer"><option value="">Alle tilgængelige kunder</option><?php foreach($customers as $c):?><option value="<?=(int)$c['id']?>" <?=(string)$c['id']===$customer?'selected':''?>><?=softwareH($c['name'])?></option><?php endforeach ?></select></label><button>Vis software</button></form>
<?php if($truncated):?><p class="notice" role="status">Oversigten er begrænset til de første 20.000 installationer. Afgræns efter kunde, eller åbn en bestemt computer for at se dens fulde softwareliste. Antal og kategorier her gælder det viste udsnit.</p><?php endif ?>
<p><?=count($groups)?> produkter · <?=count($rows)?> installationer i det viste udvalg. Flere versioner af et produkt på samme PC tæller hver for sig.</p>
<?php if($product!==''):?><h2>Installationer</h2><?php softwareRender($rows);else:softwareRender(array_values($groups),true);endif ?>
<?php endif ?>
<details class="panel"><summary>Om kategorier, kilder og opdatering</summary><p>Kategorier bygger på <a href="https://specifications.freedesktop.org/menu/latest/category-registry.html" target="_blank" rel="noopener noreferrer">freedesktop Desktop Menu Specification</a>, med lokale X-underkategorier til runtime, drivere og enhedsadministration. ITIL bruges som ramme for aktivstyring; dette er ikke en officiel ITIL-kategoriliste eller en SWID-certificering.</p><p>Produktmatch og kategori følger kontrollerede katalogregler. Beskrivelser og produktwebsteder hentes fra Homebrews offentlige JSON-API. Det er en kilde til produktmetadata på tværs af platforme; dets versionsnumre bruges ikke til at bedømme Windows- eller Linux-opdateringer. Der sendes ingen computernavne, kundenavne eller softwarelister til kilden. Ukendt eller tvetydig software kræver klassificering. Licensmetadata dokumenterer ikke købte brugsrettigheder.</p>
<?php if($ready&&$actor['admin']&&$actor['role']==='admin'):?><form method="post" action="software.php" data-software-refresh><input type="hidden" name="csrf_token" value="<?=softwareH($actor['csrf_token'])?>"><input type="hidden" name="action" value="refresh"><button>Opdatér katalog fra internettet</button></form><p data-software-refresh-status role="status">Metadata genhentes tidligst efter 7 dage. Manuelle klassificeringer bevares.</p><?php endif ?></details>
<footer>Adgang følger ITSM-kunder og roller. Kilder er vejledende produktmetadata, ikke dokumentation for sikkerhed eller licensrettigheder.</footer></div></main></body></html>
