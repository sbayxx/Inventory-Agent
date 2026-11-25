<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/nav.php';require dirname(__DIR__).'/backend/inventory/src/InventoryView.php';require dirname(__DIR__).'/backend/inventory/src/BrowserInventory.php';require dirname(__DIR__).'/backend/inventory/src/InventoryV2.php';require dirname(__DIR__).'/backend/inventory/src/SoftwareView.php';
try {
    $actor=inventoryActor(true);
    webHeaders(true);
    if(getenv('INV_ENABLED')!=='1')respond(503,['error'=>'inventory_disabled']);if(!enabled())respond(503,['error'=>'inventory_disabled']);
    [$where,$params]=inventoryScope($actor);
    $canProvision=in_array($actor['role'],['admin','customer_admin'],true);
    $customers=inventoryCustomers($actor);
    $customerNames=[];foreach($customers as $customer)$customerNames[(int)$customer['id']]=$customer['name'];
    $id=$_GET['id']??'';if(!is_string($id)||($id!==''&&!preg_match('/^[a-f0-9]{32}$/D',$id)))respond(400,['error'=>'invalid_id']);
    if($id!==''){
        $q=db()->prepare('SELECT c.*,a.last_seen,a.customer_id FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE '.$where.' AND c.agent_id=?');$q->execute([...$params,$id]);$computer=$q->fetch();if(!$computer)respond(404,['error'=>'not_found']);
        $scan=json_decode($computer['snapshot'],true,64,JSON_THROW_ON_ERROR);
        $softwareContext=softwareContext(db(),[(int)$computer['customer_id']]);$softwareRows=[];$softwareCanEdit=softwareReady(db())&&in_array($actor['role'],['admin','customer_admin'],true);
        foreach(($scan['software']??[]) as $ordinal=>$raw)$softwareRows[]=softwareClassify($raw,$softwareContext,(int)$computer['customer_id'])+['agent_id'=>$id,'ordinal'=>$ordinal,'can_edit'=>$softwareCanEdit];
        $q=db()->prepare('SELECT category,created_at,before_value,after_value FROM inv_changes WHERE agent_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);$changes=$q->fetchAll();
        $webAvailable=false;$webProfiles=[];$webRows=[];
        // Browser inventory can be disabled or unavailable without breaking computer details.
        if($canProvision){try{
            $webAvailable=browserEnabled(db());
            if($webAvailable){
                $q=db()->prepare('SELECT id,label,enabled,last_seen FROM inv_browser_profiles WHERE agent_id=? ORDER BY created_at DESC LIMIT 100');$q->execute([$id]);$webProfiles=$q->fetchAll();
                $q=db()->prepare('SELECT d.day,d.domain,d.category,SUM(d.visits) AS visits FROM inv_browser_daily d JOIN inv_browser_profiles p ON p.id=d.profile_id WHERE p.agent_id=? AND d.day >= UTC_DATE() - INTERVAL 29 DAY GROUP BY d.day,d.domain,d.category ORDER BY d.day DESC,d.domain LIMIT 5000');$q->execute([$id]);$webRows=$q->fetchAll();
                $webLabels=array_column(json_decode(file_get_contents(dirname(__DIR__).'/backend/inventory/src/browser-taxonomy.json'),true,64,JSON_THROW_ON_ERROR)['categories'],'label','id');
                foreach($webRows as &$webRow)$webRow['category']=$webLabels[$webRow['category']]??$webRow['category'];unset($webRow);
            }
        }catch(Throwable $e){$webAvailable=false;error_log('Browser dashboard unavailable: '.get_class($e));}}

    } else {
        $filters=inventoryListFilters($_GET);
        [$listWhere,$listParams]=inventoryListConditions($filters,$where,$params);
        $q=db()->prepare('SELECT COUNT(*) FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE '.$listWhere);$q->execute($listParams);$matched=(int)$q->fetchColumn();
        $perPage=50;$pages=max(1,(int)ceil($matched/$perPage));$filters['page']=min($filters['page'],$pages);$offset=($filters['page']-1)*$perPage;
        $q=db()->prepare('SELECT c.agent_id,c.hostname,c.os,c.agent_version,c.scanned_at,a.last_seen,a.customer_id FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE '.$listWhere.' ORDER BY '.inventoryOrder($filters,$customerNames).' LIMIT '.$perPage.' OFFSET '.$offset);$q->execute($listParams);$computers=$q->fetchAll();
        $q=db()->prepare("SELECT COUNT(*) AS total,SUM(a.last_seen >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS recent,SUM(c.os='windows') AS windows,SUM(c.os='darwin') AS darwin,SUM(c.os='linux') AS linux FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE ".$where);$q->execute($params);$stats=$q->fetch();$total=(int)$stats['total'];

    }
} catch(InvalidArgumentException){respond(400,['error'=>'invalid_filters']);}
catch(Throwable $e){error_log('Inventory dashboard failure: '.get_class($e));respond(503,['error'=>'inventory_unavailable']);}
function h(mixed $v):string{return htmlspecialchars(is_scalar($v)?(string)$v:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function recent(?string $at):bool{return $at!==null&&(new DateTimeImmutable($at,new DateTimeZone('UTC')))->getTimestamp()>time()-86400;}
function fields(array $values):void{echo '<dl>';foreach($values as $k=>$v)echo '<dt>'.h(inventoryLabel((string)$k)).'</dt><dd>'.h(inventoryDisplay($v)).'</dd>';echo '</dl>';}
function listUrl(array $changes=[]):string{global $filters;return '?'.http_build_query(array_filter(array_replace($filters,$changes),fn($v)=>$v!==''));}
function sortHeading(string $key,string $label):void{global $filters;$active=$filters['sort']===$key;$direction=$active&&$filters['direction']==='asc'?'desc':'asc';echo '<th scope="col" aria-sort="'.($active?($filters['direction']==='asc'?'ascending':'descending'):'none').'"><a href="'.h(listUrl(['sort'=>$key,'direction'=>$direction,'page'=>1])).'">'.h($label).($active?($filters['direction']==='asc'?' ↑':' ↓'):' ↕').'</a></th>';}
function rows(array $rows,string $category):void{
    if(!$rows){echo '<p>Ingen registrerede oplysninger.</p>';return;}
    $columns=[];foreach($rows as $row)foreach(array_keys($row) as $key)if(!in_array($key,$columns,true))$columns[]=$key;
    echo '<div class="data-list" data-data-list><div class="list-controls"><label>Søg i '.h(strtolower(inventoryLabel($category))).'<input type="search" data-list-search placeholder="Skriv for at søge…"></label>';
    if(in_array($category,['software','web'],true)){
        $filterKey=$category==='web'?'category':'publisher';$filterLabel=$category==='web'?'Kategori':'Producent';
        $publishers=[];foreach($rows as $row){$publisher=inventoryDisplay($row[$filterKey]??null);$publishers[$publisher]=true;}uksort($publishers,'strnatcasecmp');
        echo '<label>'.h($filterLabel).'<select data-list-filter data-filter-key="'.h($filterKey).'"><option value="">Alle</option>';foreach(array_keys($publishers) as $publisher)echo '<option value="'.h($publisher).'">'.h($publisher).'</option>';echo '</select></label>';
    }
    echo '<label>Sortér efter<select data-list-sort>';foreach($columns as $key)echo '<option value="'.h($key).'">'.h(inventoryLabel($key)).'</option>';echo '</select></label><label>Retning<select data-list-direction><option value="asc">Stigende</option><option value="desc">Faldende</option></select></label><button type="button" data-list-reset>Nulstil</button></div>';
    echo '<p class="result-count" data-list-count aria-live="polite">'.count($rows).' poster</p><div class="table-scroll"><table><thead><tr>';
    foreach($columns as $key)echo '<th scope="col" aria-sort="none"><button type="button" data-sort-column="'.h($key).'">'.h(inventoryLabel($key)).' ↕</button></th>';
    echo '</tr></thead><tbody>';foreach($rows as $row){echo '<tr data-list-row>';foreach($columns as $key)echo '<td data-key="'.h($key).'" data-value="'.h(inventoryDisplay($row[$key]??null)).'">'.h(inventoryDisplay($row[$key]??null)).'</td>';echo '</tr>';}
    echo '</tbody></table></div><p data-list-empty hidden>Ingen poster matcher din søgning og dine filtre.</p></div>';
}
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PC Inventory Agent · Inventory</title><link rel="stylesheet" href="inventory.css?v=<?=h(substr(hash_file('sha256',__DIR__.'/inventory.css'),0,12))?>"><script defer src="software.js?v=<?=h(substr(hash_file('sha256',__DIR__.'/software.js'),0,12))?>"></script><script defer src="dashboard.js?v=<?=h(substr(hash_file('sha256',__DIR__.'/dashboard.js'),0,12))?>"></script></head><body><main>
<?php invNav($actor, isset($customers) ? $customers : [], isset($filters['customer']) ? (int)$filters['customer'] : 0, 'dashboard'); ?>
<?php if($id===''): ?>
<?php require_once dirname(__DIR__).'/backend/inventory/src/InventoryV3.php';if(v3Enabled(db())):?><nav><a href="v3.php">Version 3 · opdateringer, integration og fjernsupport →</a></nav><?php elseif($actor['admin']):?><nav><a href="v3.php?view=settings">Indstillinger for version 3</a></nav><?php endif ?>
<?php if(v2Enabled(db())):?><nav><a href="v2.php">Version 2 · historik, licenser, sikkerhed, rapporter og notifikationer →</a></nav><?php elseif($actor['admin']):?><nav><a href="v2.php?view=settings">Indstillinger for version 2</a></nav><?php endif ?>
<?php if($actor['admin']):?><nav><a href="enrollments.php">Agentregistrering · godkend nye computere →</a></nav><?php endif ?><nav><a href="software.php">Software · kategorier og normalisering →</a></nav><h1>Inventory</h1><p>Hardware, software og netværk samlet ét sted.</p>
<?php if($canProvision): ?><details class="panel"><summary>Manuel konfiguration til ældre agenter</summary>
<p>Opret én konfiguration pr. computer. Den hentede JSON indeholder et hemmeligt token, som skal overføres sikkert til agenten.</p>
<form method="post" action="provision.php">
<input type="hidden" name="csrf_token" value="<?=h($actor['csrf_token'])?>">
<label>Kunde <select name="customer_id" required><option value="">Vælg kunde</option><?php foreach($customers as $customer):?><option value="<?=h($customer['id'])?>"><?=h($customer['name'])?></option><?php endforeach ?></select></label>
<label>Computernavn / betegnelse <input name="label" required maxlength="255" placeholder="OFFICE-PC-014"></label>
<label>Platform <select name="platform"><option value="darwin">macOS</option><option value="windows">Windows</option><option value="linux">Linux</option></select></label>
<button type="submit">Opret og hent agent.json</button>
</form></details><?php endif ?>
<?php if(count($customers) > 1): ?>
<form method="get" class="customer-bar">
  <label>Kunde: <select name="customer" onchange="this.form.submit()">
    <option value="">Alle kunder</option>
    <?php foreach($customers as $c): ?>
    <option value="<?=h($c['id'])?>" <?=(string)$c['id']===(string)($filters['customer']??'')?'selected':''?>><?=h($c['name'])?></option>
    <?php endforeach ?>
  </select></label>
  <?php foreach($_GET as $k=>$v): if($k==='customer')continue; ?>
  <input type="hidden" name="<?=h($k)?>" value="<?=h((string)$v)?>">
  <?php endforeach ?>
</form>
<?php endif ?>
<section class="stats"><article><strong><?= h($total) ?></strong><span>Computere i alt</span></article><article><strong><?= h((int)$stats['recent']) ?></strong><span>Kontakt seneste 24 timer</span></article><article><strong><?= h((int)$stats['windows']) ?></strong><span>Windows</span></article><article><strong><?= h((int)$stats['darwin']) ?></strong><span>macOS</span></article><article><strong><?= h((int)$stats['linux']) ?></strong><span>Linux</span></article></section>
<p>Tallene omfatter alle computere, du har adgang til. Seneste kontakt er ikke en direkte online-status. Tider vises i UTC.</p>
<section class="panel"><h2>Computere</h2>
<form method="get" class="list-controls computer-filters">
<label>Søg<input type="search" name="q" value="<?=h($filters['q'])?>" placeholder="Computernavn, serienummer, IP eller software"></label>
<label>System<select name="os"><?php foreach([''=>'Alle systemer','windows'=>'Windows','darwin'=>'macOS','linux'=>'Linux'] as $value=>$label):?><option value="<?=h($value)?>" <?=$filters['os']===$value?'selected':''?>><?=h($label)?></option><?php endforeach ?></select></label>
<label>Kontakt<select name="contact"><?php foreach([''=>'Alle','recent'=>'Kontakt seneste 24 timer','older'=>'Ingen kontakt i 24 timer'] as $value=>$label):?><option value="<?=h($value)?>" <?=$filters['contact']===$value?'selected':''?>><?=h($label)?></option><?php endforeach ?></select></label>
<label>Kunde<select name="customer"><option value="">Alle tilgængelige kunder</option><?php foreach($customers as $customer):?><option value="<?=h($customer['id'])?>" <?=(string)$customer['id']===$filters['customer']?'selected':''?>><?=h($customer['name'])?></option><?php endforeach ?></select></label>
<input type="hidden" name="sort" value="<?=h($filters['sort'])?>"><input type="hidden" name="direction" value="<?=h($filters['direction'])?>">
<button type="submit">Søg og filtrér</button><a class="reset-link" href="./">Nulstil</a>
</form>
<p class="result-count" aria-live="polite"><?=h($matched)?> af <?=h($total)?> computere matcher. Klik på en kolonneoverskrift for at sortere.</p>
<div class="table-scroll"><table><thead><tr><?php foreach(['hostname'=>'Computer','os'=>'System','customer_id'=>'Kunde','last_seen'=>'Seneste kontakt','agent_version'=>'Agent'] as $key=>$label)sortHeading($key,$label);?></tr></thead><tbody>
<?php foreach($computers as $c): ?><tr><td><a href="?id=<?=h($c['agent_id'])?>"><?=h($c['hostname'])?></a></td><td><?=h(['darwin'=>'macOS','windows'=>'Windows','linux'=>'Linux'][$c['os']]??$c['os'])?></td><td><?=h($customerNames[(int)$c['customer_id']]??$c['customer_id'])?></td><td><span class="<?=recent($c['last_seen'])?'ok':'muted'?>"><?=h($c['last_seen']??'Aldrig')?></span></td><td><?=h($c['agent_version'])?></td></tr><?php endforeach ?>
<?php if(!$computers): ?><tr><td colspan="5"><?=$total===0?'Ingen computere endnu. Klargør en agent og importer dens konfiguration for at modtage første scanning.':'Ingen computere matcher de valgte filtre.'?></td></tr><?php endif ?></tbody></table></div>
<nav class="pagination" aria-label="Sider i computerlisten"><?php if($filters['page']>1):?><a href="<?=h(listUrl(['page'=>$filters['page']-1]))?>">← Forrige</a><?php endif ?><span>Side <?=h($filters['page'])?> af <?=h($pages)?></span><?php if($filters['page']<$pages):?><a href="<?=h(listUrl(['page'=>$filters['page']+1]))?>">Næste →</a><?php endif ?></nav>
</section>
<?php else: ?>
<a href="./">← Alle computere</a><h1><?=h($computer['hostname'])?></h1><p>Seneste scanning: <?=h($computer['scanned_at'])?> UTC · Kontakt: <?=h($computer['last_seen'])?> UTC</p>
<?php if(v2Enabled(db(),'changes')):?><p><a href="v2.php?view=changes&amp;agent=<?=h($id)?>">Se detaljerede ændringer i version 2 →</a></p><?php endif ?>
<div class="group-actions"><button type="button" data-expand-groups>Fold alle ud</button><button type="button" data-collapse-groups>Fold alle sammen</button></div>
<nav aria-label="Informationsgrupper"><?php foreach(['computer'=>'Computer','hardware'=>'Hardware','disks'=>'Lagring','software'=>'Software','network'=>'Netværk','collection'=>'Indsamlingsstatus','changes'=>'Ændringer',...($canProvision?['web'=>'Webtrafik']:[])] as $k=>$label):?><a href="#<?=h($k)?>"><?=h($label)?></a><?php endforeach ?></nav>
<?php foreach(['computer','hardware','disks','software','network','collection'] as $k):?><details class="panel info-group" data-info-group id="<?=h($k)?>" <?=$k==='computer'?'open':''?>><summary><span><?=h(inventoryLabel($k))?></span><?php if(in_array($k,['disks','software','network'],true)):?><span class="group-count"><?=count($scan[$k]??[])?></span><?php endif ?></summary><div class="group-content"><?php if($k==='software')softwareRender($softwareRows);elseif(in_array($k,['disks','network'],true))rows($scan[$k]??[],$k);else fields($scan[$k]??[]);if($k==='network')fields($scan['network_config']??[]);?></div></details><?php endforeach ?>
<?php if($canProvision)require dirname(__DIR__).'/backend/inventory/src/browser-panel.php'; ?>
<details class="panel info-group" data-info-group id="licenses"><summary>Licenser og sikkerhed</summary><div class="group-content"><p>Installeret software og aktivering dokumenterer ikke virksomhedens licensrettigheder. Ukendte forhold vises ikke som sikre eller licenserede.<?php if(v2Enabled(db(),'security')):?> <a href="v2.php?view=security&amp;agent=<?=h($id)?>">Se sikkerhedsstatus for denne computer →</a><?php endif ?><?php if(v2Manage($actor)&&v2Enabled(db(),'licenses')):?> <a href="v2.php?view=licenses">Åbn licensadministration →</a><?php endif ?></p></div></details>
<details class="panel info-group" data-info-group id="changes"><summary><span>Seneste ændringer</span><span class="group-count"><?=count($changes)?></span></summary><div class="group-content"><?php foreach($changes as $change):?><details class="change-item"><summary><?=h($change['created_at'])?> UTC · <?=h(inventoryLabel($change['category']))?></summary><h3>Før</h3><pre><?=h(json_decode($change['before_value'],true))?></pre><h3>Efter</h3><pre><?=h(json_decode($change['after_value'],true))?></pre></details><?php endforeach ?><?php if(!$changes):?><p>Ingen ændringer registreret endnu.</p><?php endif ?></div></details>
<?php endif ?><footer>PC Inventory Agent · Data fra automatiske scanninger · Adgang følger ITSM-kunder og roller.</footer></div></main></body></html>
