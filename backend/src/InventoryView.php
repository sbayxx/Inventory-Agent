<?php
declare(strict_types=1);
function inventoryListFilters(array $input): array {
    foreach(['q','os','contact','customer','sort','direction','page'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new InvalidArgumentException('Invalid filter');
    $q=trim($input['q']??'');if(strlen($q)>250)throw new InvalidArgumentException('Search too long');
    $os=$input['os']??'';$contact=$input['contact']??'';$sort=$input['sort']??'hostname';$direction=$input['direction']??'asc';
    if(!in_array($os,['','windows','darwin','linux'],true)||!in_array($contact,['','recent','older'],true))throw new InvalidArgumentException('Invalid filter');
    if(!in_array($sort,['hostname','os','customer_id','last_seen','agent_version','scanned_at'],true)||!in_array($direction,['asc','desc'],true))throw new InvalidArgumentException('Invalid sorting');
    $customer=$input['customer']??'';if($customer!==''&&!preg_match('/^[1-9][0-9]{0,17}$/D',$customer))throw new InvalidArgumentException('Invalid customer');
    $page=$input['page']??'1';if(!preg_match('/^[1-9][0-9]{0,8}$/D',$page))throw new InvalidArgumentException('Invalid page');
    return compact('q','os','contact','sort','direction','customer')+['page'=>(int)$page];
}
function inventoryListConditions(array $filters,string $scope,array $params): array {
    $parts=['('.$scope.')'];
    if($filters['q']!==''){
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['q']).'%';
        $parts[]="(c.hostname LIKE ? ESCAPE '!' OR c.os LIKE ? ESCAPE '!' OR c.agent_version LIKE ? ESCAPE '!' OR a.label LIKE ? ESCAPE '!' OR JSON_SEARCH(c.snapshot,'one',?,'!') IS NOT NULL)";
        array_push($params,$pattern,$pattern,$pattern,$pattern,$pattern);
    }
    if($filters['os']!==''){$parts[]='c.os=?';$params[]=$filters['os'];}
    if($filters['customer']!==''){$parts[]='a.customer_id=?';$params[]=$filters['customer'];}
    if($filters['contact']==='recent')$parts[]='a.last_seen >= UTC_TIMESTAMP() - INTERVAL 1 DAY';
    if($filters['contact']==='older')$parts[]='(a.last_seen IS NULL OR a.last_seen < UTC_TIMESTAMP() - INTERVAL 1 DAY)';
    return [implode(' AND ',$parts),$params];
}
function inventoryOrder(array $filters,array $customerNames=[]): string {
    $columns=['hostname'=>'c.hostname','os'=>'c.os','customer_id'=>'a.customer_id','last_seen'=>'a.last_seen','agent_version'=>'c.agent_version','scanned_at'=>'c.scanned_at'];
    if(!isset($columns[$filters['sort']])||!in_array($filters['direction'],['asc','desc'],true))throw new InvalidArgumentException('Invalid sorting');
    $column=$columns[$filters['sort']];
    if($filters['sort']==='customer_id'&&$customerNames){
        asort($customerNames,SORT_NATURAL|SORT_FLAG_CASE);$column='CASE a.customer_id';$rank=0;
        foreach($customerNames as $id=>$name)$column.=' WHEN '.(int)$id.' THEN '.$rank++;
        $column.=' ELSE '.$rank.' END';
    }
    return $column.' '.strtoupper($filters['direction']).', c.agent_id ASC';
}
function inventoryLabel(string $key): string {
    return ['web'=>'Webtrafik','day'=>'Dato (UTC)','domain'=>'Domæne','category'=>'Kategori','visits'=>'Sideindlæsninger','name'=>'Navn','version'=>'Version','publisher'=>'Producent','hostname'=>'Computernavn','manufacturer'=>'Producent','model'=>'Model','serial_number'=>'Serienummer','os'=>'System','os_name'=>'Systemnavn','os_version'=>'Systemversion','build'=>'Build','architecture'=>'Arkitektur','cpu_model'=>'Processor','cpu_vendor'=>'CPU-producent','cpu_cores'=>'CPU-kerner','cpu_logical'=>'Logiske processorer','ram_bytes'=>'RAM (bytes)','device'=>'Enhed','mount'=>'Monteringspunkt','filesystem'=>'Filsystem','capacity_bytes'=>'Kapacitet (bytes)','free_bytes'=>'Ledig plads (bytes)','type'=>'Type','mac'=>'MAC-adresse','addresses'=>'IP-adresser','dns_servers'=>'DNS-servere','gateway'=>'Gateway','gateways'=>'Gateways','default_routes'=>'Standardruter','status'=>'Status','interfaces'=>'Interfaces','collection'=>'Indsamlingsstatus','computer'=>'Computer','hardware'=>'Hardware','disks'=>'Lagring','software'=>'Software','network'=>'Netværk','network_config'=>'Netværkskonfiguration','licenses'=>'Licenser','security'=>'Sikkerhed'][$key]??$key;
}
function inventoryDisplay(mixed $value): string {
    if($value===null)return 'Ukendt';if(is_bool($value))return $value?'Ja':'Nej';
    if(is_array($value)&&array_is_list($value)&&!array_filter($value,fn($v)=>!is_scalar($v)))return implode(', ',array_map('strval',$value));
    return is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
