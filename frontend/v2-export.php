<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/inventory/src/bootstrap.php';require dirname(__DIR__).'/backend/inventory/src/itsm-auth.php';require dirname(__DIR__).'/backend/inventory/src/InventoryV2.php';require dirname(__DIR__).'/backend/inventory/src/V2Licensing.php';require dirname(__DIR__).'/backend/inventory/src/V2Reports.php';require dirname(__DIR__).'/backend/inventory/src/V2Export.php';
try{
 $actor=inventoryActor();
 webHeaders();
 if(getenv('INV_ENABLED')!=='1')respond(503,['error'=>'inventory_disabled']);
 if(!enabled()||!v2Enabled(db(),'reports'))respond(503,['error'=>'reports_disabled']);
 if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){header('Allow: GET');respond(405,['error'=>'method_not_allowed']);}
 $f=v2Filters($_GET);$report=$_GET['report']??'computers';$format=$_GET['format']??'xlsx';if(!is_string($report)||!in_array($report,V2_REPORTS,true)||!in_array($format,['csv','xlsx'],true))throw new InvalidArgumentException('Ugyldig eksport.');
 $rows=v2Report(db(),$actor,$report,$f,10001);if(count($rows)>10000)respond(422,['error'=>'too_many_rows','message'=>'Indsnævr filtrene; højst 10.000 rækker pr. eksport.']);
 $columns=v2ReportColumns($report);$bytes=0;foreach($rows as $r)foreach($columns as $column)$bytes+=strlen((string)($r[$column]??''));if($bytes>5*1024*1024)respond(422,['error'=>'report_too_large','message'=>'Indsnævr filtrene; eksportens tekstindhold må højst være 5 MiB.']);$data=$format==='xlsx'?v2Xlsx($columns,$rows):v2Csv($columns,$rows);
 header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=utf-8'));header('Content-Disposition: attachment; filename="pc-inventory-'.$report.'-'.gmdate('Y-m-d').'.'.$format.'"');echo $data;
}catch(DomainException){respond(403,['error'=>'export_access_denied']);}
catch(InvalidArgumentException){respond(400,['error'=>'invalid_export_filters']);}
catch(Throwable $e){error_log('V2 export failure: '.get_class($e));respond(503,['error'=>'export_unavailable']);}
