<?php
declare(strict_types=1);
final class RequestRateLimited extends RuntimeException {}
// Counts are committed before parsing/processing the body. Failed work does not refund the budget.
function requestBudget(PDO $d,string $scope,string $identity,int $maximum,int $seconds):void{
 if($d->inTransaction())throw new LogicException('Budget must be consumed outside a data transaction');
 $key=hash('sha256',$scope.':'.$identity);$window=intdiv(time(),$seconds)*$seconds;
 $d->beginTransaction();try{
  $d->prepare('INSERT INTO inv_request_limits(bucket_key,window_start,request_count,expires_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE request_count=IF(window_start=VALUES(window_start),request_count+1,1),window_start=VALUES(window_start),expires_at=VALUES(expires_at)')->execute([$key,$window,$window+$seconds+86400]);
  $q=$d->prepare('SELECT request_count FROM inv_request_limits WHERE bucket_key=? FOR UPDATE');$q->execute([$key]);$count=(int)$q->fetchColumn();$d->commit();
  if($count>$maximum)throw new RequestRateLimited();
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
