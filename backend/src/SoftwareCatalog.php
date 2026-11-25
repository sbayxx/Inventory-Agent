<?php
declare(strict_types=1);
// Classification is a presentation layer: raw scans and licence evidence are never rewritten.
function softwareCatalog():array {
 static $catalog;return $catalog??=json_decode(file_get_contents(__DIR__.'/../data/software-catalog.json'),true,64,JSON_THROW_ON_ERROR);
}
function softwareCategories():array{return array_column(softwareCatalog()['categories'],null,'id');}
function softwareProducts():array{return array_column(softwareCatalog()['products'],null,'id');}
function softwareLower(string $v):string{return function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);}
function softwareCut(string $v,int $max):string{return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):implode('',array_slice(preg_split('//u',$v,-1,PREG_SPLIT_NO_EMPTY)?:[],0,$max));}
function softwareText(mixed $v,int $max=255):string{return is_string($v)?softwareCut(trim($v),$max):'';}
function softwareNorm(string $text):string {
 $text=softwareLower(trim($text));$text=str_replace(['®','™','©'],'',$text);
 $text=preg_replace('/\s*\((?:x64|x86|arm64|64-bit|32-bit)(?: edition| [a-z]{2}-[a-z]{2})?\)\s*/u',' ',$text);
 return trim(preg_replace('/\s+/u',' ',$text));
}
function softwareFingerprint(array $raw):string{return hash('sha256',json_encode([is_string($raw['name']??null)?softwareLower(trim($raw['name'])):'',is_string($raw['publisher']??null)?softwareLower(trim($raw['publisher'])):''],JSON_THROW_ON_ERROR));}
function softwareReady(PDO $d):bool {
 try{return $d->query("SELECT value FROM inv_settings WHERE name='software_catalog_schema_version'")->fetchColumn()==='1';}catch(PDOException){return false;}
}
function softwareContext(PDO $d,array $customers=[]):array {
 $metadata=json_decode(file_get_contents(__DIR__.'/../data/software-metadata.json'),true,64,JSON_THROW_ON_ERROR);$overrides=[];
 if(softwareReady($d)){
  foreach($d->query('SELECT product_id,metadata FROM inv_software_metadata') as $r)$metadata[$r['product_id']]=json_decode($r['metadata'],true,16,JSON_THROW_ON_ERROR);
  $customers=array_values(array_unique(array_filter(array_map('intval',$customers),fn($n)=>$n>0)));
  foreach(array_chunk($customers,500) as $chunk){
   $q=$d->prepare('SELECT * FROM inv_software_overrides WHERE customer_id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');$q->execute($chunk);
   foreach($q as $r)$overrides[$r['customer_id'].':'.$r['fingerprint']]=$r;
  }
 }
 return compact('metadata','overrides');
}
function softwareClassify(array $raw,array $context=[],int $customer=0):array {
 $name=softwareText($raw['name']??'');$publisher=softwareText($raw['publisher']??'');$fingerprint=softwareFingerprint($raw);
 $out=['fingerprint'=>$fingerprint,'product_id'=>null,'name'=>$name?:'Ukendt software','publisher'=>$publisher,'category'=>'Unclassified','subcategory'=>'','status'=>'unmatched','confidence'=>'none','description'=>'','homepage'=>'','license'=>'','source_url'=>'','fetched_at'=>null,'raw_name'=>$name,'raw_publisher'=>$publisher,'version'=>softwareText($raw['version']??'',128)];
 $override=$context['overrides'][$customer.':'.$fingerprint]??null;
 if($override)return array_replace($out,['name'=>$override['canonical_name'],'publisher'=>$override['publisher'],'category'=>$override['category'],'subcategory'=>$override['subcategory'],'status'=>'manual','confidence'=>'manual']);
 $fullName=is_string($raw['name']??null)?trim($raw['name']):'';$fullPublisher=is_string($raw['publisher']??null)?trim($raw['publisher']):'';if(strlen($fullName)>255||strlen($fullPublisher)>255)return $out;
 $norm=softwareNorm($name);$pub=softwareNorm($publisher);$candidates=[];
 foreach(softwareCatalog()['products'] as $p){
  $exact=in_array($norm,array_map('softwareNorm',$p['aliases']),true);$publisherMatch=$pub!==''&&in_array($pub,array_map('softwareNorm',$p['publishers']),true);
  // No fuzzy inference: regexes are reviewed catalogue rules, never inventory input.
  $pattern=$publisherMatch&&$p['pattern']!==null&&preg_match('~'.$p['pattern'].'~iu',trim(preg_replace('/\s*\((?:x64|x86|arm64|64-bit|32-bit)(?: edition| [a-z]{2}-[a-z]{2})?\)\s*/iu',' ',$name)))===1;
  if(($exact&&($pub===''||$publisherMatch))||$pattern)$candidates[]=$p;
 }
 if(count($candidates)!==1)return $out;
 $p=$candidates[0];$m=$context['metadata'][$p['id']]??[];
 return array_replace($out,['product_id'=>$p['id'],'name'=>$p['name'],'publisher'=>$p['publisher'],'category'=>$p['category'],'subcategory'=>$p['subcategory'],'status'=>'catalog','confidence'=>$pub===''?'medium':'high','description'=>$m['description']??'','homepage'=>$m['homepage']??'','license'=>$m['license']??'','source_url'=>$p['source_url'],'fetched_at'=>$m['fetched_at']??null]);
}
function softwareCategoryLabel(string $id,string $sub=''):string {
 $c=softwareCategories()[$id]??softwareCategories()['Unclassified'];return $sub!==''?($c['subcategories'][$sub]??$sub):$c['label'];
}
function softwareStatusLabel(array $r):string {
 return match($r['status']){'manual'=>'Manuelt bekræftet','catalog'=>$r['confidence']==='high'?'Katalogmatch':'Katalogmatch · producent mangler',default=>'Kræver klassificering'};
}
function softwareURL(string $url):string {
 if(strlen($url)>512||filter_var($url,FILTER_VALIDATE_URL)===false||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)return '';
 return $url;
}
function softwareMetadata(array $p,array $json,string $raw,?string $at=null):array {
 if(($json[$p['source_type']==='cask'?'token':'name']??null)!==$p['source_id']||!is_string($json['desc']??null)||strlen($json['desc'])>10000)throw new UnexpectedValueException('Invalid source metadata');
 return ['description'=>softwareCut(trim($json['desc']),1500),'homepage'=>softwareURL(softwareText($json['homepage']??'',512)),
  'license'=>$p['source_type']==='formula'?softwareText($json['license']??'',255):'',
  'source_url'=>$p['source_url'],'source_sha256'=>hash('sha256',$raw),'fetched_at'=>$at??gmdate('Y-m-d H:i:s')];
}
function softwareFetchMetadata(array $p):array {
 // Fixed provider + reviewed ID. Neither observed software names nor client URLs leave ITSM.
 if(!in_array($p['source_type'],['cask','formula'],true)||!preg_match('/^[a-z0-9@+._-]+$/D',$p['source_id']))throw new InvalidArgumentException('Unsupported source');
 $url='https://formulae.brew.sh/api/'.$p['source_type'].'/'.$p['source_id'].'.json';
 if($url!==$p['source_url'])throw new LogicException('Untrusted source');
 $raw='';$c=curl_init($url);
 curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'PC-Inventory-SoftwareCatalog/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_WRITEFUNCTION=>static function($ch,string $data)use(&$raw):int{if(strlen($raw)+strlen($data)>524288)return 0;$raw.=$data;return strlen($data);}]);
 try{$ok=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$contentType=curl_getinfo($c,CURLINFO_CONTENT_TYPE);if($ok===false||$code!==200||!str_contains((string)$contentType,'application/json'))throw new RuntimeException('Catalogue source unavailable');}
 finally{unset($c);}
 $json=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($json))throw new UnexpectedValueException();return softwareMetadata($p,$json,$raw);
}
function softwareRefresh(PDO $d,int $actor):array {
 if(!softwareReady($d))throw new RuntimeException('Migration required');
 if((int)$d->query("SELECT GET_LOCK('inventory_software_catalog',0)")->fetchColumn()!==1)throw new RuntimeException('Catalog update busy');
 try{
  $context=softwareContext($d);$stale=[];
  foreach(softwareProducts() as $id=>$p)if($p['source_type']!=='vendor'&&strtotime(($context['metadata'][$id]['fetched_at']??'1970-01-01').' UTC')<time()-7*86400)$stale[$id]=$p;
  $updated=[];$failed=[];
  foreach(array_slice($stale,0,3,true) as $id=>$p){
   try{$m=softwareFetchMetadata($p);
    $d->beginTransaction();$q=$d->prepare('INSERT INTO inv_software_metadata(product_id,metadata,source_url,source_sha256,fetched_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE metadata=VALUES(metadata),source_url=VALUES(source_url),source_sha256=VALUES(source_sha256),fetched_at=VALUES(fetched_at)');$q->execute([$id,json_encode($m,JSON_THROW_ON_ERROR),$p['source_url'],$m['source_sha256'],$m['fetched_at']]);
    $d->prepare("INSERT INTO inv_software_catalog_audit(product_id,actor_id,action,after_value,created_at) VALUES(?,?,'refresh',?,UTC_TIMESTAMP())")->execute([$id,$actor,json_encode(['source_url'=>$p['source_url'],'source_sha256'=>$m['source_sha256']],JSON_THROW_ON_ERROR)]);$d->commit();$updated[]=$id;
   }catch(Throwable $e){if($d->inTransaction())$d->rollBack();$failed[]=$id;error_log('Software metadata refresh failed: '.$id.' '.get_class($e));}
  }
  return ['updated'=>$updated,'failed'=>$failed,'remaining'=>max(0,count($stale)-3)];
 }finally{$d->query("SELECT RELEASE_LOCK('inventory_software_catalog')");}
}
function softwareScopedRow(PDO $d,array $actor,string $agent,int $ordinal):?array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$agent)||$ordinal<0||$ordinal>20000)throw new InvalidArgumentException('Invalid row');
 [$scope,$params]=inventoryScope($actor);$q=$d->prepare('SELECT s.data,a.customer_id,a.id AS agent_id,s.ordinal FROM inv_software s JOIN inv_agents a ON a.id=s.agent_id WHERE ('.$scope.') AND s.agent_id=? AND s.ordinal=?');$q->execute([...$params,$agent,$ordinal]);return $q->fetch()?:null;
}
function softwareSaveOverride(PDO $d,array $actor,array $input):void {
 if(!in_array($actor['role'],['admin','customer_admin'],true)||!is_string($input['csrf_token']??null)||!hash_equals($actor['csrf_token'],$input['csrf_token']))throw new DomainException('Adgang afvist');
 $agent=$input['agent']??null;$ordinal=filter_var($input['ordinal']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>20000]]);
 if(!is_string($agent)||$ordinal===false)throw new InvalidArgumentException('Invalid selection');
 $d->beginTransaction();try{
  // Lock the agent first, as ingestion does, so ordinal cannot move between validation and save.
  [$scope,$params]=inventoryScope($actor);$q=$d->prepare('SELECT a.id FROM inv_agents a WHERE ('.$scope.') AND a.id=? FOR UPDATE');$q->execute([...$params,$agent]);if(!$q->fetch())throw new DomainException('Adgang afvist');
  $row=softwareScopedRow($d,$actor,$agent,$ordinal);if(!$row)throw new DomainException('Adgang afvist');
  $raw=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);$fp=softwareFingerprint($raw);
  if(!is_string($input['fingerprint']??null)||!hash_equals($fp,$input['fingerprint']))throw new DomainException('Inventaret er ændret; genindlæs siden');
  $q=$d->prepare('SELECT * FROM inv_software_overrides WHERE customer_id=? AND fingerprint=? FOR UPDATE');$q->execute([$row['customer_id'],$fp]);$before=$q->fetch()?:null;
  if(($input['action']??'')==='reset'){
   $d->prepare('DELETE FROM inv_software_overrides WHERE customer_id=? AND fingerprint=?')->execute([$row['customer_id'],$fp]);$after=null;
  }else{
   foreach(['name','publisher','category','subcategory'] as $key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('Invalid field');
   $name=trim($input['name']);$pub=trim($input['publisher']);$cat=$input['category'];$sub=$input['subcategory'];$categories=softwareCategories();
   if($name===''||strlen($name)>255||strlen($pub)>255||preg_match('/[\x00-\x1f\x7f]/',$name.$pub)||!isset($categories[$cat])||($sub!==''&&!isset($categories[$cat]['subcategories'][$sub])))throw new InvalidArgumentException('Ugyldig klassificering');
   $d->prepare('INSERT INTO inv_software_overrides(customer_id,fingerprint,canonical_name,publisher,category,subcategory,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE canonical_name=VALUES(canonical_name),publisher=VALUES(publisher),category=VALUES(category),subcategory=VALUES(subcategory),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute([$row['customer_id'],$fp,$name,$pub,$cat,$sub,$actor['id']]);
   $after=['name'=>$name,'publisher'=>$pub,'category'=>$cat,'subcategory'=>$sub];
  }
  $d->prepare('INSERT INTO inv_software_catalog_audit(customer_id,fingerprint,actor_id,action,before_value,after_value,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$row['customer_id'],$fp,$actor['id'],($input['action']??'')==='reset'?'reset':'classify',json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);$d->commit();
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
