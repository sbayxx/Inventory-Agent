<?php
declare(strict_types=1);
function v2Csv(array $columns,array $rows):string{
    $escape=function(mixed $value):string{$s=(string)($value??'');if(preg_match('/^[=+\-@\t\r\n]/',$s))$s="'".$s;return '"'.str_replace('"','""',$s).'"';};
    $lines=[implode(';',array_map($escape,array_map('v2Label',$columns)))];foreach($rows as $r)$lines[]=implode(';',array_map(fn($k)=>$escape($r[$k]??''),$columns));return "\xEF\xBB\xBF".implode("\r\n",$lines)."\r\n";
}
function v2Zip(array $files):string{
    $data='';$central='';$count=0;
    foreach($files as $name=>$body){$offset=strlen($data);$size=strlen($body);$crc=crc32($body);$n=strlen($name);
        $data.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$n,0).$name.$body;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$n,0,0,0,0,0,$offset).$name;$count++;
    }
    return $data.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($data),0);
}
function v2Xlsx(array $columns,array $rows):string{
    $xml=fn($s)=>htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',v2Text((string)($s??''),32000))??'',ENT_XML1|ENT_QUOTES,'UTF-8');
    $letter=function(int $n):string{$s='';do{$s=chr(65+$n%26).$s;$n=intdiv($n,26)-1;}while($n>=0);return $s;};
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.count($columns).'" width="28" customWidth="1"/></cols><sheetData>';
    $all=[array_combine($columns,array_map('v2Label',$columns)),...$rows];foreach($all as $index=>$row){$n=$index+1;$sheet.='<row r="'.$n.'">';foreach($columns as $i=>$column){$value=$row[$column]??'';$ref=$letter($i).$n;if($index>0&&in_array($column,['seats','assigned','archived'],true)&&filter_var($value,FILTER_VALIDATE_INT)!==false)$sheet.='<c r="'.$ref.'" t="n"><v>'.(int)$value.'</v></c>';else $sheet.='<c r="'.$ref.'" t="inlineStr" s="'.($index===0?'1':'0').'"><is><t xml:space="preserve">'.$xml($value).'</t></is></c>';}$sheet.='</row>';}
    $sheet.='</sheetData><autoFilter ref="A1:'.$letter(count($columns)-1).count($all).'"/></worksheet>';
    return v2Zip([
      '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
      '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventory" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
      'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
      'xl/worksheets/sheet1.xml'=>$sheet
    ]);
}
