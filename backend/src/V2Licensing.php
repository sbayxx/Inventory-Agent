<?php
declare(strict_types=1);
function v2Tenant(array $actor,string $column):array{
    if($actor['admin'])return ['1=1',[]];
    $ids=array_values(array_filter(array_map('intval',$actor['customers']),fn($id)=>$id>0));
    return $ids?[$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids]:['1=0',[]];
}
function v2Date(mixed $value):?string{
    if($value===null||$value==='')return null;
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new InvalidArgumentException('Ugyldig dato.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Ugyldig dato.');return $value;
}
function v2Positive(mixed $value):int{$n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]);if($n===false)throw new InvalidArgumentException('Ugyldigt nummer.');return $n;}
function v2CustomerAllowed(array $actor,int $customer):bool{if($actor['admin'])return true;return in_array($customer,array_map('intval',$actor['customers']),true);}
function v2Audit(PDO $db,array $actor,int $customer,string $action,int $object):void{$db->prepare('INSERT INTO inv_v2_audit(customer_id,actor_id,action,object_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))')->execute([$customer,$actor['id'],$action,$object]);}
function v2Mutate(PDO $db,array $actor,array $input):string{
    if(!v2Manage($actor)||!is_string($input['csrf_token']??null)||!hash_equals($actor['csrf_token'],$input['csrf_token']))throw new DomainException('Adgang afvist.');
    $action=$input['action']??'';
    if($action==='settings'){
        if(!$actor['admin'])throw new DomainException('Kun global administrator.');
        $db->beginTransaction();try{foreach(['enabled',...V2_FEATURES] as $feature){$value=($input[$feature]??'')==='1'?'1':'0';$db->prepare('UPDATE inv_settings SET value=? WHERE name=?')->execute([$value,'v2_'.$feature]);}
        $db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}return 'Indstillinger gemt.';
    }
    if($action==='acknowledge'){
        if(!v2Enabled($db,'notifications'))throw new DomainException('Notifikationer er deaktiveret.');
        [$where,$params]=v2Tenant($actor,'customer_id');$id=v2Positive($input['id']??null);
        $q=$db->prepare('UPDATE inv_v2_notices SET acknowledged_by=?,acknowledged_at=UTC_TIMESTAMP(6) WHERE '.$where.' AND id=? AND active=1');$q->execute([$actor['id'],...$params,$id]);return 'Notifikation kvitteret.';
    }
    if(!v2Enabled($db,'licenses'))throw new DomainException('Licensmodulet er deaktiveret.');
    $db->beginTransaction();
    try{
        if($action==='product'){
            $customer=v2Positive($input['customer_id']??null);if(!v2CustomerAllowed($actor,$customer))throw new DomainException('Ingen adgang til kunden.');
            $valid=false;foreach(inventoryCustomers($actor) as $c)if((int)$c['id']===$customer)$valid=true;if(!$valid)throw new DomainException('Kunden er ikke tilgængelig.');
            $name=$input['name']??'';$publisher=$input['publisher']??'';if(!is_string($name)||trim($name)===''||strlen($name)>255||!is_string($publisher)||strlen($publisher)>255)throw new InvalidArgumentException('Ugyldigt produktnavn.');
            $publisher=trim($publisher);
            $q=$db->prepare('INSERT INTO inv_v2_products(customer_id,name,publisher,name_hash,publisher_hash,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');$q->execute([$customer,trim($name),trim($publisher),hash('sha256',v2Norm($name)),$publisher===''?'':hash('sha256',v2Norm($publisher))]);$object=(int)$db->lastInsertId();
        }elseif(in_array($action,['entitlement','edit-entitlement','archive','assign','release'],true)){
            if($action==='entitlement'){
                $product=v2Positive($input['product_id']??null);$q=$db->prepare('SELECT * FROM inv_v2_products WHERE id=? FOR UPDATE');$q->execute([$product]);$p=$q->fetch();if(!$p||!v2CustomerAllowed($actor,(int)$p['customer_id']))throw new DomainException('Produktadgang afvist.');$customer=(int)$p['customer_id'];
            }else{
                $id=v2Positive($input['id']??null);
                if($action==='release'){$q=$db->prepare('SELECT entitlement_id FROM inv_v2_assignments WHERE id=?');$q->execute([$id]);$entitlement=$q->fetchColumn();if(!$entitlement)throw new DomainException('Ukendt tildeling.');}
                $q=$db->prepare('SELECT * FROM inv_v2_entitlements WHERE id=? FOR UPDATE');$q->execute([$action==='release'?$entitlement:$id]);$e=$q->fetch();if(!$e||!v2CustomerAllowed($actor,(int)$e['customer_id']))throw new DomainException('Licensadgang afvist.');$customer=(int)$e['customer_id'];$object=(int)$e['id'];
            }
            if(in_array($action,['entitlement','edit-entitlement'],true)){
                $metric=$input['metric']??'';$seats=v2Positive($input['seats']??null);$reference=$input['reference']??'';$start=v2Date($input['starts_on']??'');$end=v2Date($input['expires_on']??'');
                if(!in_array($metric,['device','user'],true)||!is_string($reference)||strlen($reference)>255||($start&&$end&&$start>$end))throw new InvalidArgumentException('Ugyldige licensoplysninger.');
                if($action==='entitlement'){$q=$db->prepare('INSERT INTO inv_v2_entitlements(customer_id,product_id,metric,seats,reference,starts_on,expires_on,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');$q->execute([$customer,$product,$metric,$seats,trim($reference),$start,$end]);$object=(int)$db->lastInsertId();}
                else{
                    if((int)$e['archived'])throw new DomainException('Arkiveret licens kan ikke redigeres.');
                    $q=$db->prepare('SELECT COUNT(*) FROM inv_v2_assignments WHERE entitlement_id=? AND active=1');$q->execute([$id]);if($e['metric']!==$metric&&(int)$q->fetchColumn()>0)throw new InvalidArgumentException('Frigiv tildelinger før ændring af licensmåleenhed.');
                    $db->prepare('UPDATE inv_v2_entitlements SET metric=?,seats=?,reference=?,starts_on=?,expires_on=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$metric,$seats,trim($reference),$start,$end,$id]);
                }
            }elseif($action==='archive'){$db->prepare('UPDATE inv_v2_entitlements SET archived=1,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$id]);$db->prepare('UPDATE inv_v2_assignments SET active=0 WHERE entitlement_id=?')->execute([$id]);}
            elseif($action==='release'){$db->prepare('UPDATE inv_v2_assignments SET active=0 WHERE id=? AND customer_id=?')->execute([$id,$customer]);}
            elseif($action==='assign'){
                if((int)$e['archived']||($e['expires_on']&&$e['expires_on']<gmdate('Y-m-d'))||($e['starts_on']&&$e['starts_on']>gmdate('Y-m-d')))throw new InvalidArgumentException('Licensen er ikke aktiv på dags dato.');
                $agent=null;
                if($e['metric']==='device'){
                    $agent=$input['agent_id']??'';if(!is_string($agent)||!preg_match('/^[a-f0-9]{32}$/D',$agent))throw new InvalidArgumentException('Vælg computer.');
                    $q=$db->prepare('SELECT c.hostname FROM inv_computers c JOIN inv_agents a ON a.id=c.agent_id WHERE a.id=? AND a.customer_id=? AND a.enabled=1');$q->execute([$agent,$customer]);$subject=$q->fetchColumn();if(!$subject)throw new DomainException('Computeren tilhører ikke licenskunden.');$key=hash('sha256','device:'.$agent);
                }else{$subject=$input['subject']??'';if(!is_string($subject)||trim($subject)===''||strlen($subject)>255)throw new InvalidArgumentException('Angiv brugerreference.');$subject=trim($subject);$key=hash('sha256','user:'.v2Norm($subject));}
                $q=$db->prepare('INSERT INTO inv_v2_assignments(customer_id,entitlement_id,agent_id,subject,subject_key,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE active=1,subject=VALUES(subject)');$q->execute([$customer,$id,$agent,$subject,$key]);
            }
        }else{throw new InvalidArgumentException('Ukendt handling.');}
        v2Audit($db,$actor,$customer,$action,$object);$db->commit();return 'Ændringen er gemt.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
