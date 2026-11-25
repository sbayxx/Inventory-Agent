<?php
declare(strict_types=1);
const V2_REPORTS=['computers','software','security','changes','licenses','activations'];
function v2Filters(array $input):array{
    foreach(['q','customer','agent','from','to','state','category','page','report','format','view','sort','direction'] as $k)if(isset($input[$k])&&!is_string($input[$k]))throw new InvalidArgumentException('Ugyldigt filter.');
    $q=trim($input['q']??'');if(strlen($q)>250)throw new InvalidArgumentException('Søgningen er for lang.');
    $customer=$input['customer']??'';if($customer!=='')v2Positive($customer);
    $agent=$input['agent']??'';if($agent!==''&&!preg_match('/^[a-f0-9]{32}$/D',$agent))throw new InvalidArgumentException('Ugyldigt enheds-ID.');
    $from=v2Date($input['from']??gmdate('Y-m-d',time()-29*86400));$to=v2Date($input['to']??gmdate('Y-m-d'));if(!$from||!$to||$from>$to)throw new InvalidArgumentException('Ugyldig periode.');
    $state=$input['state']??'';if(!in_array($state,['','enabled','disabled','unknown','not_supported','attention','current','stale','not_received'],true))throw new InvalidArgumentException('Ugyldig status.');
    $category=$input['category']??'';if(!in_array($category,['','software','computer','hardware','network','network_config','disks','security','collection'],true))throw new InvalidArgumentException('Ugyldig kategori.');
    $page=$input['page']??'1';if(!preg_match('/^[1-9][0-9]{0,5}$/D',$page))throw new InvalidArgumentException('Ugyldig side.');
    $sort=$input['sort']??'';$direction=$input['direction']??'asc';if($sort!==''&&!in_array($sort,array_unique(array_merge(...array_map('v2ReportColumns',V2_REPORTS))),true))throw new InvalidArgumentException('Ugyldig sortering.');if(!in_array($direction,['asc','desc'],true))throw new InvalidArgumentException('Ugyldig sorteringsretning.');
    return compact('q','customer','agent','from','to','state','category','sort','direction')+['page'=>(int)$page];
}
function v2Report(PDO $db,array $actor,string $report,array $f,int $limit=51,int $offset=0):array{
    if(!in_array($report,V2_REPORTS,true))throw new InvalidArgumentException('Ukendt rapport.');
    if(in_array($report,['licenses','activations'],true)&&(!v2Manage($actor)||!v2Enabled($db,'licenses')))throw new DomainException('Licensrapport afvist.');
    if($report==='security'&&!v2Enabled($db,'security'))throw new DomainException('Sikkerhed er deaktiveret.');
    if($report==='changes'&&!v2Enabled($db,'changes'))throw new DomainException('Historik er deaktiveret.');
    [$scope,$params]=$report==='licenses'?v2Tenant($actor,'e.customer_id'):inventoryScope($actor);$where=['('.$scope.')'];
    if($f['customer']!==''){$where[]=($report==='licenses'?'e.customer_id':'a.customer_id').'=?';$params[]=$f['customer'];}
    if($f['agent']!==''&&$report!=='licenses'){$where[]='a.id=?';$params[]=$f['agent'];}
    $sources=[
      'computers'=>'SELECT a.customer_id,c.agent_id,c.hostname,c.os,c.agent_version,c.scanned_at,a.last_seen FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id',
      'software'=>'SELECT a.customer_id,a.id AS agent_id,c.hostname,i.name,i.publisher,IF(OCTET_LENGTH(i.versions)>2048,JSON_ARRAY(\'Versionsliste over rapportgrænsen; se seneste rå scanning\'),i.versions) AS versions,i.observed_at FROM inv_v2_installations i JOIN inv_agents a ON a.id=i.agent_id JOIN inv_computers c ON c.agent_id=a.id',
      'changes'=>'SELECT e.event_key,a.customer_id,a.id AS agent_id,c.hostname,e.category,e.operation,e.label,e.occurred_at FROM inv_v2_events e JOIN inv_agents a ON a.id=e.agent_id JOIN inv_computers c ON c.agent_id=a.id',
      'security'=>"SELECT a.customer_id,a.id AS agent_id,c.hostname,k.check_name,COALESCE(s.state,'unknown') AS state,COALESCE(s.source,'not_received') AS source,COALESCE(s.detail,'Afventer version 2-agent') AS detail,s.observed_at,IF(s.observed_at IS NULL,'not_received',IF(s.observed_at < UTC_TIMESTAMP() - INTERVAL 2 DAY OR s.observed_at < c.scanned_at,'stale','current')) AS freshness FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id CROSS JOIN (SELECT 'firewall' AS check_name UNION ALL SELECT 'disk_encryption' UNION ALL SELECT 'antivirus' UNION ALL SELECT 'updates') k LEFT JOIN inv_v2_security s ON s.agent_id=a.id AND s.check_name=k.check_name",
      'activations'=>'SELECT a.customer_id,a.id AS agent_id,c.hostname,o.product,o.state,o.source,o.observed_at,m.first_observed_at,m.first_observed_licensed_at,m.evaluation_end_at,m.grace_period_remaining_minutes,m.license_status_reason,m.license_family FROM inv_v2_license_observations o LEFT JOIN inv_v2_activation_metadata m ON m.agent_id=o.agent_id AND m.product_id=o.product_id JOIN inv_agents a ON a.id=o.agent_id JOIN inv_computers c ON c.agent_id=a.id',
      'licenses'=>'SELECT e.customer_id,e.id,p.name,e.metric,e.seats,(SELECT COUNT(*) FROM inv_v2_assignments x WHERE x.entitlement_id=e.id AND x.active=1) AS assigned,e.reference,e.starts_on,e.expires_on,e.archived FROM inv_v2_entitlements e JOIN inv_v2_products p ON p.id=e.product_id'
    ];
    if($f['q']!==''){
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['q']).'%';
        $columns=['computers'=>['c.hostname','c.os'],'software'=>['c.hostname','i.name','i.publisher'],'changes'=>['c.hostname','e.label'],'security'=>['c.hostname','k.check_name'],'activations'=>['c.hostname','o.product'],'licenses'=>['p.name','e.reference']][$report];
        $or=[];foreach($columns as $column){$or[]=$column." LIKE ? ESCAPE '!'";$params[]=$pattern;}$where[]='('.implode(' OR ',$or).')';
    }
    if($report==='changes'){$where[]='e.occurred_at>=? AND e.occurred_at<?';$params[]=$f['from'].' 00:00:00';$params[]=(new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00';if($f['category']!==''){$where[]='e.category=?';$params[]=$f['category'];}}
    if($report==='security'&&$f['state']!==''){
        if(in_array($f['state'],['current','stale','not_received'],true)){
            $where[]=$f['state']==='not_received'?'s.observed_at IS NULL':($f['state']==='stale'?"s.observed_at IS NOT NULL AND (s.observed_at < UTC_TIMESTAMP() - INTERVAL 2 DAY OR s.observed_at < c.scanned_at)":"s.observed_at >= UTC_TIMESTAMP() - INTERVAL 2 DAY AND s.observed_at >= c.scanned_at");
        }else{$where[]="COALESCE(s.state,'unknown')=?";$params[]=$f['state'];}
    }
    $order=['computers'=>'c.hostname,c.agent_id','software'=>'c.hostname,i.name,i.product_key','changes'=>'e.occurred_at DESC,e.event_key','security'=>'c.hostname,a.id,k.check_name','activations'=>'c.hostname,o.product_id','licenses'=>'p.name,e.id'][$report];
    if(($f['sort']??'')!==''){$sortables=v2Sortables($report);if(!isset($sortables[$f['sort']]))throw new InvalidArgumentException('Ugyldig rapportkolonne.');$order=$sortables[$f['sort']].' '.($f['direction']==='desc'?'DESC':'ASC').','.$order;}
    $q=$db->prepare($sources[$report].' WHERE '.implode(' AND ',$where).' ORDER BY '.$order.' LIMIT '.max(1,min(10001,$limit)).' OFFSET '.max(0,$offset));$q->execute($params);$rows=$q->fetchAll();
    foreach($rows as &$row)if(isset($row['versions']))$row['versions']=implode(', ',json_decode($row['versions'],true,64,JSON_THROW_ON_ERROR));return $rows;
}
function v2ReportColumns(string $report):array{return [
 'computers'=>['customer_id','agent_id','hostname','os','agent_version','scanned_at','last_seen'],
 'software'=>['customer_id','hostname','agent_id','name','publisher','versions','observed_at'],
 'security'=>['customer_id','hostname','agent_id','check_name','state','source','detail','observed_at','freshness'],
 'changes'=>['customer_id','hostname','agent_id','category','operation','label','occurred_at'],
 'activations'=>['customer_id','hostname','agent_id','product','state','source','observed_at','first_observed_at','first_observed_licensed_at','evaluation_end_at','grace_period_remaining_minutes','license_status_reason','license_family'],
 'licenses'=>['customer_id','id','name','metric','seats','assigned','reference','starts_on','expires_on','archived']
][$report];}
function v2Label(string $key):string{return ['software'=>'Software','hardware'=>'Hardware','computer'=>'Computer','network'=>'Netværk','network_config'=>'Netværkskonfiguration','disks'=>'Lagring','security'=>'Sikkerhed','collection'=>'Indsamling','ram_bytes'=>'RAM (bytes)','cpu_model'=>'Processormodel','first_observed_at'=>'Først observeret (UTC)','first_observed_licensed_at'=>'Først observeret aktiveret (UTC)','evaluation_end_at'=>'Evaluering udløber (UTC)','grace_period_remaining_minutes'=>'Resterende aktiveringsfrist (minutter)','license_status_reason'=>'Statuskode','license_family'=>'Licensfamilie','product'=>'Aktiveringsprodukt','licensed'=>'Produktet er aktiveret','unlicensed'=>'Ikke aktiveret','grace'=>'Aktiveringsfrist','non_genuine'=>'Aktiveringsproblem','notification'=>'Aktiveringsmeddelelse','extended_grace'=>'Udvidet aktiveringsfrist','customer_id'=>'Kunde-ID','agent_id'=>'Enheds-ID','hostname'=>'Computer','os'=>'System','agent_version'=>'Agent','scanned_at'=>'Scannet (UTC)','last_seen'=>'Sidste kontakt (UTC)','name'=>'Produkt','publisher'=>'Producent','versions'=>'Versioner','observed_at'=>'Målt (UTC)','check_name'=>'Kontrol','state'=>'Status','source'=>'Kilde','detail'=>'Detaljer','freshness'=>'Aktualitet','category'=>'Kategori','operation'=>'Ændring','label'=>'Element','occurred_at'=>'Hændelse (UTC)','id'=>'ID','metric'=>'Måleenhed','seats'=>'Pladser','assigned'=>'Tildelt','reference'=>'Indkøbsreference','starts_on'=>'Start','expires_on'=>'Udløb','archived'=>'Arkiveret','enabled'=>'Aktiveret','disabled'=>'Deaktiveret','unknown'=>'Ukendt','not_supported'=>'Ikke understøttet','attention'=>'Kræver opmærksomhed','current'=>'Aktuel','stale'=>'Forældet måling','not_received'=>'Ikke modtaget','installed'=>'Installeret','removed'=>'Fjernet','updated'=>'Version ændret','added'=>'Tilføjet','changed'=>'Ændret','device'=>'Enhed','user'=>'Bruger','firewall'=>'Firewall','disk_encryption'=>'Diskkryptering','antivirus'=>'Antivirus','updates'=>'Opdateringer'][$key]??$key;}

function v2Sortables(string $report):array{
 $common=['customer_id'=>$report==='licenses'?'e.customer_id':'a.customer_id'];if($report!=='licenses')$common['agent_id']='a.id';
 return $common+[
  'computers'=>['hostname'=>'c.hostname','os'=>'c.os','agent_version'=>'c.agent_version','last_seen'=>'a.last_seen','scanned_at'=>'c.scanned_at'],
  'software'=>['hostname'=>'c.hostname','name'=>'i.name','publisher'=>'i.publisher','observed_at'=>'i.observed_at'],
  'security'=>['hostname'=>'c.hostname','check_name'=>'k.check_name','state'=>"COALESCE(s.state,'unknown')",'source'=>'s.source','observed_at'=>'s.observed_at','freshness'=>'freshness'],
  'changes'=>['hostname'=>'c.hostname','category'=>'e.category','operation'=>'e.operation','label'=>'e.label','occurred_at'=>'e.occurred_at'],
  'licenses'=>['id'=>'e.id','name'=>'p.name','metric'=>'e.metric','seats'=>'e.seats','assigned'=>'assigned','reference'=>'e.reference','starts_on'=>'e.starts_on','expires_on'=>'e.expires_on','archived'=>'e.archived'],
  'activations'=>['hostname'=>'c.hostname','product'=>'o.product','state'=>'o.state','observed_at'=>'o.observed_at','source'=>'o.source','first_observed_at'=>'m.first_observed_at','first_observed_licensed_at'=>'m.first_observed_licensed_at','evaluation_end_at'=>'m.evaluation_end_at','grace_period_remaining_minutes'=>'m.grace_period_remaining_minutes','license_status_reason'=>'m.license_status_reason','license_family'=>'m.license_family']
  ][$report];
}
