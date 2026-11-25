<?php
declare(strict_types=1);
require_once __DIR__.'/SoftwareCatalog.php';
function softwareH(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function softwareProductKey(array $r):string{return hash('sha256',json_encode([$r['product_id']??$r['name'],$r['publisher'],$r['category'],$r['subcategory']],JSON_THROW_ON_ERROR));}
function softwareRender(array $rows,bool $aggregate=false):void {
 if(!$rows){echo '<p>Ingen software matcher. Software vises efter en vellykket scanning.</p>';return;}
 $categories=[];$publishers=[];foreach($rows as $r){$categories[$r['category']]=softwareCategoryLabel($r['category']);$publishers[$r['publisher']]=true;}asort($categories,SORT_NATURAL|SORT_FLAG_CASE);uksort($publishers,'strnatcasecmp');
 usort($rows,fn($a,$b)=>strnatcasecmp(softwareCategoryLabel($a['category']),softwareCategoryLabel($b['category']))?:strnatcasecmp($a['name'],$b['name']));
 echo '<section data-software-list><div class="list-controls"><label>Søg i software<input type="search" data-software-search placeholder="Produkt, oprindeligt navn, version eller producent"></label><label>Kategori<select data-software-filter="category"><option value="">Alle kategorier</option>';
 foreach($categories as $id=>$label)echo '<option value="'.softwareH($id).'">'.softwareH($label).'</option>';
 echo '</select></label><label>Producent<select data-software-filter="publisher"><option value="">Alle producenter</option>';foreach(array_keys($publishers) as $pub)echo '<option value="'.softwareH($pub).'">'.softwareH($pub?:'Ukendt').'</option>';
 echo '</select></label><label>Gruppering<select data-software-group><option value="category">Efter kategori</option><option value="none">Samlet liste</option></select></label><button type="button" data-software-reset>Nulstil</button></div><p data-software-count aria-live="polite">'.count($rows).' poster</p><div class="table-scroll"><table class="software-table"><thead><tr><th scope="col">Produkt og kilde</th><th scope="col">Producent</th><th scope="col">'.($aggregate?'Versioner observeret':'Installeret version').'</th><th scope="col">Kategori</th><th scope="col">'.($aggregate?'Installationer':'Registreret på PC’en').'</th></tr></thead><tbody>';
 $last=null;
 foreach($rows as $r){
  if($last!==$r['category']){$last=$r['category'];echo '<tr data-software-heading="'.softwareH($last).'" class="software-category"><th colspan="5" scope="rowgroup">'.softwareH(softwareCategoryLabel($last)).'</th></tr>';}
  echo '<tr data-software-row data-category="'.softwareH($r['category']).'" data-publisher="'.softwareH($r['publisher']).'"><td><strong>'.softwareH($r['name']).'</strong><small>'.softwareH(softwareStatusLabel($r)).'</small>';
  if($r['description']!=='')echo '<small>'.softwareH($r['description']).'</small>';
  if(softwareURL($r['homepage'])!=='')echo '<a target="_blank" rel="noopener noreferrer" href="'.softwareH($r['homepage']).'">Produktwebsted</a> ';
  if($r['source_url']!=='')echo '<a target="_blank" rel="noopener noreferrer" href="'.softwareH($r['source_url']).'">Kilde</a><small>'.($r['fetched_at']?'Metadata hentet '.softwareH($r['fetched_at']).' UTC':'Katalogregel · ingen hentet metadata').'</small>';
  if($r['license']!=='')echo '<small>Udgiverlicens: '.softwareH($r['license']).'</small>';
  echo '</td><td>'.softwareH($r['publisher']?:'Ukendt').'</td><td>'.softwareH($aggregate?implode(', ',$r['versions']):($r['version']?:'Ukendt')).'</td><td>'.softwareH(softwareCategoryLabel($r['category']));
  if($r['subcategory']!=='')echo '<small>'.softwareH(softwareCategoryLabel($r['category'],$r['subcategory'])).'</small>';
  echo '</td><td>';
  if($aggregate){echo '<a href="?product='.softwareH(softwareProductKey($r)).'">'.(int)$r['installations'].' installationer →</a><small>Observerede navne: '.softwareH(implode(', ',array_keys($r['raw_names']))).'</small>';}
  else{
   echo softwareH($r['raw_name']).'<small>Registreret producent: '.softwareH($r['raw_publisher']?:'Ukendt').'</small>';
   if(isset($r['hostname']))echo '<a href="./?id='.softwareH($r['agent_id']).'#software">'.softwareH($r['hostname']).'</a>';
   if($r['can_edit']??false)echo '<small><a href="software.php?edit='.softwareH($r['agent_id']).'&amp;ordinal='.(int)$r['ordinal'].'">Ret klassificering →</a></small>';
  }
  echo '</td></tr>';
 }
 echo '</tbody></table></div><p data-software-empty hidden>Ingen software matcher søgningen eller filtrene.</p></section>';
}
