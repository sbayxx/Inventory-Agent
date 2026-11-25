<?php
declare(strict_types=1);
require_once __DIR__.'/InventoryV2.php';
final class InvalidScan extends RuntimeException {}
final class RateLimited extends RuntimeException {}
function validateScan(array $s): void {
    try{v2ValidateObservations($s);}catch(InvalidArgumentException $e){throw new InvalidScan($e->getMessage());}
    if (($s['schema']??null)!==1 || !is_string($s['scan_id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$s['scan_id'])) throw new InvalidScan('Invalid schema or scan_id');
    if (!is_string($s['scanned_at']??null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D',$s['scanned_at'])) throw new InvalidScan('Invalid UTC timestamp');
    try {$t=new DateTimeImmutable($s['scanned_at']);} catch(Throwable){throw new InvalidScan('Invalid timestamp');}
    $dateErrors=DateTimeImmutable::getLastErrors();if($dateErrors!==false && ($dateErrors['warning_count']||$dateErrors['error_count']))throw new InvalidScan('Invalid date');
    if ($t->getTimestamp()<0)throw new InvalidScan('Date before epoch');
    if(isset($s['network_config'])&&!is_array($s['network_config']))throw new InvalidScan('Invalid network_config');
    if ($t->getTimestamp()>time()+300) throw new InvalidScan('Clock ahead');
    foreach(['computer','hardware','collection'] as $k) if(!is_array($s[$k]??null)) throw new InvalidScan('Missing '.$k);
    foreach(['disks','software','network'] as $k) {
        if(!is_array($s[$k]??null)||!array_is_list($s[$k])||count($s[$k])>20000) throw new InvalidScan('Invalid '.$k);
        foreach($s[$k] as $r) if(!is_array($r)) throw new InvalidScan('Invalid row');
    }
    foreach(['hostname','os','architecture'] as $k) if(!is_string($s['computer'][$k]??null)||strlen($s['computer'][$k])>255||$s['computer'][$k]==='') throw new InvalidScan('Invalid computer');
    if(!in_array($s['computer']['os'],['windows','darwin','linux'],true)) throw new InvalidScan('Invalid OS');
    if(!is_string($s['agent_version']??null)||strlen($s['agent_version'])>64) throw new InvalidScan('Invalid agent version');
}
function canonical(mixed $v): mixed {
    if(!is_array($v)) return $v;
    if(array_is_list($v)){ $v=array_map('canonical',$v); usort($v,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));return $v; }
    ksort($v);foreach($v as &$x)$x=canonical($x);return $v;
}
function persistScan(PDO $db,string $agent,array $s,string $hash): void {
    $json=fn($v)=>json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $at=(new DateTimeImmutable($s['scanned_at']))->format('Y-m-d H:i:s.u');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT enabled FROM inv_agents WHERE id=? FOR UPDATE');$q->execute([$agent]);if((int)$q->fetchColumn()!==1)throw new InvalidScan('Agent disabled');
        $q=$db->prepare('SELECT payload_hash FROM inv_scan_history WHERE agent_id=? AND scan_id=?');$q->execute([$agent,$s['scan_id']]);$oldHash=$q->fetchColumn();
        if($oldHash!==false){if(!hash_equals($oldHash,$hash))throw new InvalidScan('Conflicting scan_id');$db->commit();return;}
        $db->prepare('UPDATE inv_agents SET rate_count=IF(rate_window IS NULL OR rate_window < UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE,1,rate_count+1), rate_window=IF(rate_window IS NULL OR rate_window < UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE,UTC_TIMESTAMP(6),rate_window) WHERE id=?')->execute([$agent]);
        $q=$db->prepare('SELECT rate_count FROM inv_agents WHERE id=?');$q->execute([$agent]);if((int)$q->fetchColumn()>120)throw new RateLimited('Too many scans');
        $q=$db->prepare('SELECT snapshot,scanned_at FROM inv_computers WHERE agent_id=?');$q->execute([$agent]);$old=$q->fetch();
        $db->prepare('INSERT INTO inv_scan_history(agent_id,scan_id,scanned_at,received_at,payload_hash,snapshot) VALUES(?,?,?,UTC_TIMESTAMP(6),?,?)')->execute([$agent,$s['scan_id'],$at,$hash,$json($s)]);
        $db->prepare('UPDATE inv_agents SET last_seen=UTC_TIMESTAMP(6) WHERE id=?')->execute([$agent]);
        if(!$old || $at>$old['scanned_at']) {
            $previous=$old?json_decode($old['snapshot'],true,64,JSON_THROW_ON_ERROR):null;
            if($previous)foreach(['computer','hardware','disks','software','network','network_config'] as $k){
                if(in_array($k,['software','disks','network'],true)&&($s['collection'][$k]??'')!=='ok') continue;
                if(canonical($previous[$k]??null)!==canonical($s[$k]??null))$db->prepare('INSERT INTO inv_changes(agent_id,scan_id,category,before_value,after_value,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$agent,$s['scan_id'],$k,$json($previous[$k]??null),$json($s[$k]??null)]);
            }
            // Preserve last known inventory on a collector failure, while keeping raw history.
            $current=$s;
            foreach(['software','disks','network'] as $k)if($previous&&($s['collection'][$k]??'')!=='ok')$current[$k]=$previous[$k];
            $db->prepare('INSERT INTO inv_computers(agent_id,hostname,os,agent_version,scanned_at,snapshot) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hostname=VALUES(hostname),os=VALUES(os),agent_version=VALUES(agent_version),scanned_at=VALUES(scanned_at),snapshot=VALUES(snapshot)')->execute([$agent,$s['computer']['hostname'],$s['computer']['os'],$s['agent_version'],$at,$json($current)]);
            $db->prepare('INSERT INTO inv_hardware(agent_id,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)')->execute([$agent,$json($s['hardware'])]);
            foreach(['disks','software','network'] as $k){
                if(($s['collection'][$k]??'')!=='ok')continue;
                $db->prepare('DELETE FROM inv_'.$k.' WHERE agent_id=?')->execute([$agent]);
                $insert=$db->prepare('INSERT INTO inv_'.$k.'(agent_id,ordinal,data) VALUES(?,?,?)');foreach($s[$k] as $i=>$r)$insert->execute([$agent,$i,$json($r)]);
            }
            $db->exec('SAVEPOINT inventory_v2');
            try{v2ProcessScan($db,$agent,$s,$previous,$at);$db->exec('RELEASE SAVEPOINT inventory_v2');}
            catch(Throwable $e){$db->exec('ROLLBACK TO SAVEPOINT inventory_v2');$db->exec('RELEASE SAVEPOINT inventory_v2');error_log('Inventory V2 enrichment failure: '.get_class($e));}
        }
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
