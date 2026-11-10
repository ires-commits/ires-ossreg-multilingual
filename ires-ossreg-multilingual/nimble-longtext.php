<?php
if(!defined('ABSPATH'))exit;
final class IRES_OSSREG_Nimble_Longtext {
 const META='_ires_ossreg_nimble_long_en';
 const SCHEMA=2;
 static function fields($node){
  $out=[];
  $add=static function($value)use(&$out){
   $value=trim(html_entity_decode(wp_strip_all_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
   $value=preg_replace('/\\s+/u',' ',$value);
   if(mb_strlen($value)<35||preg_match('~^(?:https?://|www\\.|mailto:)~i',$value))return;
   if(preg_match('/^(?:Via |V\\.|C\\.so |Corso |Tel\\.|\\+?[0-9 ]{7})/iu',$value))return;
   $out[$value]=$value;
  };
  $walk=function($value,$key='',$depth=0)use(&$walk,$add){
   if($depth>22)return;
   if(is_array($value)){foreach($value as $k=>$v)$walk($v,is_string($k)?$k:'',$depth+1);return;}
   if(!is_string($value)||preg_match('/(?:css|style|class|script|json|url|src|href|data|font|color|background|selector|html_id|shortcode)/i',$key))return;
   if(preg_match('~^(https?://|[.#][a-z_-]+\\s*\\{|\\[[-a-z_]+\\])~i',trim($value)))return;
   if(str_contains($value,'<')){
    preg_match_all('~<(?:p|li|h[1-6]|div|span)\\b[^>]*>(.*?)</(?:p|li|h[1-6]|div|span)>~is',$value,$blocks);
    foreach($blocks[1]??[] as $block)$add($block);
   }elseif(!preg_match('/[{};]/',$value)||substr_count($value,';')<=3){$add($value);}
  };
  $walk($node);return array_values($out);
 }
 static function reusable($texts,$existing){
  $lookup=[];
  if(!is_array($existing))return $lookup;
  $sources=$existing['source']??[];$targets=$existing['translated']??[];
  if(!is_array($sources)||!is_array($targets))return $lookup;
  foreach($targets as $i=>$target){
   if(!is_string($target)||!isset($sources[$i])||!is_string($sources[$i]))continue;
   $source=trim(preg_replace('/\\s+/u',' ',html_entity_decode(wp_strip_all_tags($sources[$i]),ENT_QUOTES|ENT_HTML5,'UTF-8')));
   if($source!==''&&$source!==$target)$lookup[$source]=$target;
   if(str_contains($sources[$i],'<')&&str_contains($target,'<')){
    preg_match_all('~<(p|li|h[1-6])\\b[^>]*>(.*?)</\\1>~is',$sources[$i],$a,PREG_SET_ORDER);
    preg_match_all('~<(p|li|h[1-6])\\b[^>]*>(.*?)</\\1>~is',$target,$b,PREG_SET_ORDER);
    if(count($a)===count($b))foreach($a as $j=>$block){
     $key=trim(preg_replace('/\\s+/u',' ',html_entity_decode(wp_strip_all_tags($block[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')));
     $value=trim(wp_strip_all_tags($b[$j][2]));
     if($key!==''&&$value!=='')$lookup[$key]=$value;
    }
   }
  }
  return $lookup;
 }
 static function cached($p){
  $c=get_post_meta($p->ID,self::META,true);
  return is_array($c)&&($c['schema']??0)===self::SCHEMA&&($c['hash']??'')===hash('sha256',$p->post_content)&&isset($c['source'],$c['translated'])&&is_array($c['source'])&&is_array($c['translated'])&&count($c['source'])===count($c['translated'])?$c:null;
 }
 static function normalize_target($text){
  $text=html_entity_decode(wp_strip_all_tags((string)$text),ENT_QUOTES|ENT_HTML5,'UTF-8');
  return mb_strtolower(trim(preg_replace('/\\s+/u',' ',$text)),'UTF-8');
 }
 static function candidates($cache){
  $pairs=[];
  if(!is_array($cache))return $pairs;
  if(isset($cache['source'],$cache['translated'])&&is_array($cache['source'])&&is_array($cache['translated'])){
   $pairs=self::reusable([],$cache);
  }elseif(isset($cache['fields'],$cache['translations'])&&is_array($cache['fields'])&&is_array($cache['translations'])){
   foreach($cache['translations'] as $i=>$target){
    $source=$cache['fields'][$i]['text']??null;
    if(is_string($source)&&is_string($target))$pairs+=self::reusable([],['source'=>[$source],'translated'=>[$target]]);
   }
  }
  return $pairs;
 }
 static function recover(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Access denied'],403);
  check_ajax_referer('ires_ossreg_long_batch');
  $ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
  $global=[];$local=[];$conflicts=[];
  foreach($ids as $id){
   $p=get_post($id);if(!$p)continue;
   $long=get_post_meta($id,self::META,true);
   $short=get_post_meta($id,IRES_OSSREG_Nimble_Cache::META,true);
   $local[$id]=[];
   foreach([$long,$short] as $cache){
    foreach(self::candidates($cache) as $source=>$target){
     $normalized=self::normalize_target($target);
     if($normalized===''||$normalized===self::normalize_target($source))continue;
     if(!isset($local[$id][$source][$normalized]))$local[$id][$source][$normalized]=$target;
     if(!isset($global[$source][$normalized]))$global[$source][$normalized]=$target;
    }
   }
  }
  $recovered=0;$changed=0;$local_count=0;$global_count=0;$available=0;$ambiguous=0;
  foreach($ids as $id){
   $p=get_post($id);if(!$p||!is_serialized($p->post_content))continue;
   $tree=maybe_unserialize($p->post_content);if(!is_array($tree))continue;
   $texts=self::fields($tree);if(!$texts)continue;
   $hash=hash('sha256',$p->post_content);
   $old=get_post_meta($id,self::META,true);
   $valid=is_array($old)&&($old['schema']??0)===self::SCHEMA&&($old['hash']??'')===$hash&&($old['source']??[])===$texts&&is_array($old['translated']??null);
   $completed=$valid?$old['translated']:[];
   $original_count=count($completed);
   // Preserve sequential cache layout: only append a contiguous prefix.
   for($i=$original_count;$i<count($texts);$i++){
    $source=$texts[$i];
    $choices=$local[$id][$source]??[];
    $scope='local';
    if(count($choices)!==1){$choices=$global[$source]??[];$scope='global';}
    if(count($choices)===1){
     $available++;
     if($i===count($completed)){
      $completed[]=reset($choices);$recovered++;
      if($scope==='local')$local_count++;else $global_count++;
     }
    }elseif(count($choices)>1){$ambiguous++;$conflicts[$source]=true;}
   }
   if(count($completed)>$original_count){
    update_post_meta($id,self::META,['schema'=>self::SCHEMA,'hash'=>$hash,'source'=>$texts,'translated'=>$completed]);$changed++;
   }
  }
  wp_send_json_success(['recovered'=>$recovered,'records'=>$changed,'local'=>$local_count,'global'=>$global_count,'available'=>$available,'ambiguous'=>$ambiguous,'conflicts'=>count($conflicts),'message'=>'Recovery completed without AI calls']);
 }
 static function progress(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Access denied'],403);
  check_ajax_referer('ires_ossreg_long_batch');
  $ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
  $fail=get_option('ires_ossreg_long_failures',[]);if(!is_array($fail))$fail=[];
  $total=0;$translated=0;$records=0;$pending_records=0;$deferred=0;
  foreach($ids as $id){
   $p=get_post($id);if(!$p||!is_serialized($p->post_content))continue;
   $tree=maybe_unserialize($p->post_content);if(!is_array($tree))continue;
   $texts=self::fields($tree);$n=count($texts);if(!$n)continue;
   $records++;$total+=$n;
   $cache=get_post_meta($id,self::META,true);
   $valid=is_array($cache)&&($cache['schema']??0)===self::SCHEMA&&($cache['hash']??'')===hash('sha256',$p->post_content)&&($cache['source']??[])===$texts&&is_array($cache['translated']??null);
   $done=$valid?min($n,count($cache['translated'])):0;
   $translated+=$done;
   if($done<$n){$pending_records++;if(isset($fail[$id])&&time()-(int)$fail[$id]<1800)$deferred++;}
  }
  wp_send_json_success(['total'=>$total,'translated'=>$translated,'remaining'=>max(0,$total-$translated),'percent'=>$total?round($translated*100/$total,1):100,'records'=>$records,'pending_records'=>$pending_records,'deferred'=>$deferred]);
 }
 static function repair(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Access denied'],403);
  check_ajax_referer('ires_ossreg_long_batch');
  $id=1322;$p=get_post($id);
  if(!$p||!is_serialized($p->post_content))wp_send_json_error(['message'=>'Record 1322 non valido'],400);
  $tree=maybe_unserialize($p->post_content);
  if(!is_array($tree))wp_send_json_error(['message'=>'Contenuto non valido'],400);
  $texts=self::fields($tree);$old=get_post_meta($id,self::META,true);
  if(!is_array($old)||!is_array($old['source']??null)||!is_array($old['translated']??null))wp_send_json_error(['message'=>'Cache non disponibile'],400);
  $lookup=self::reusable([],$old);$completed=[];
  foreach($texts as $source){
   if(!isset($lookup[$source])||!is_string($lookup[$source])||trim($lookup[$source])==='')break;
   $completed[]=$lookup[$source];
  }
  if(!$completed)wp_send_json_success(['repaired'=>false,'reason'=>'Nessun prefisso traducibile con corrispondenza esatta','old_count'=>count($old['translated']),'matched'=>0]);
  $backup_key=self::META.'_backup_0479';
  if(!metadata_exists('post',$id,$backup_key))add_post_meta($id,$backup_key,wp_slash($old),true);
  $payload=['schema'=>self::SCHEMA,'hash'=>hash('sha256',$p->post_content),'source'=>$texts,'translated'=>$completed];
  update_post_meta($id,self::META,wp_slash($payload));
  wp_cache_delete($id,'post_meta');$stored=get_post_meta($id,self::META,true);
  $ok=$stored===$payload;
  wp_send_json_success(['repaired'=>$ok,'old_count'=>count($old['translated']),'matched'=>count($completed),'source_matches'=>is_array($stored)&&($stored['source']??null)===$texts,'backup_saved'=>metadata_exists('post',$id,$backup_key)]);
 }
 static function diagnose(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Access denied'],403);
  check_ajax_referer('ires_ossreg_long_batch');
  $id=1322;$p=get_post($id);
  if(!$p)wp_send_json_error(['message'=>'Record 1322 non trovato'],404);
  $tree=is_serialized($p->post_content)?maybe_unserialize($p->post_content):null;
  $texts=is_array($tree)?self::fields($tree):[];
  $cache=get_post_meta($id,self::META,true);
  $hash=hash('sha256',$p->post_content);
  $schema=is_array($cache)?($cache['schema']??null):null;
  $sources=is_array($cache)?($cache['source']??null):null;
  $targets=is_array($cache)?($cache['translated']??null):null;
  $same=is_array($sources)&&$sources===$texts;
  $first_mismatch=null;
  if(is_array($sources))foreach($texts as $i=>$text)if(!isset($sources[$i])||$sources[$i]!==$text){$first_mismatch=$i;break;}
  wp_send_json_success(['id'=>$id,'post_type'=>$p->post_type,'serialized'=>is_serialized($p->post_content),'source_count'=>count($texts),'cache_present'=>is_array($cache),'schema'=>$schema,'schema_valid'=>$schema===self::SCHEMA,'hash_valid'=>is_array($cache)&&($cache['hash']??null)===$hash,'source_matches'=>$same,'first_source_mismatch'=>$first_mismatch,'cached_source_count'=>is_array($sources)?count($sources):null,'translated_count'=>is_array($targets)?count($targets):null,'cache_meta_rows'=>count(get_post_meta($id,self::META,false)),'note'=>'Sola lettura: nessuna chiamata AI e nessuna modifica ai dati']);
 }
 static function batch(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Access denied'],403);
  check_ajax_referer('ires_ossreg_long_batch');
  $settings=get_option('ires_ossreg_ml_settings',[]);$key=$settings['api_key']??'';
  if(!$key)wp_send_json_error(['message'=>'Missing Gemini key'],400);
  $ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
  $fail=get_option('ires_ossreg_long_failures',[]);if(!is_array($fail))$fail=[];
  foreach($ids as $id){
   $p=get_post($id);if(!$p||!is_serialized($p->post_content)||self::cached($p))continue;
   if(isset($fail[$id])&&time()-$fail[$id]<1800)continue;
   $tree=maybe_unserialize($p->post_content);if(!is_array($tree))continue;
   $texts=self::fields($tree);if(!$texts)continue;
   // Small batches reduce timeouts; store partial translations per record.
   $existing=get_post_meta($id,self::META,true);
   $hash=hash('sha256',$p->post_content);
   $completed=(is_array($existing)&&($existing['schema']??0)===self::SCHEMA&&($existing['hash']??'')===$hash&&($existing['source']??[])===$texts&&is_array($existing['translated']??null))?$existing['translated']:[];
   // Never request AI again if the saved cache cannot advance past this record.
   if(count($completed)>count($texts))wp_send_json_error(['message'=>'Cache incoerente per record '.$id.'; nessuna chiamata Gemini eseguita.'],409);
   $offset=count($completed);$chunk=array_slice($texts,$offset,3);
   if(!$chunk){update_post_meta($id,self::META,['schema'=>self::SCHEMA,'hash'=>$hash,'source'=>$texts,'translated'=>$completed]);continue;}
   $prompt="Translate these Italian website paragraphs to natural English. Preserve HTML markup, links, names, numbers and structure. Return a JSON array with exactly ".count($chunk)." strings in original order.\\n".wp_json_encode($chunk,JSON_UNESCAPED_UNICODE);
   $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($settings['model']??'gemini-2.5-flash').':generateContent';
   $response=wp_remote_post($url,['timeout'=>75,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$key],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
   if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200){$fail[$id]=time();update_option('ires_ossreg_long_failures',$fail,false);wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);}
   $body=json_decode(wp_remote_retrieve_body($response),true);
   $translated=json_decode($body['candidates'][0]['content']['parts'][0]['text']??'',true);
   if(!is_array($translated)||count($translated)!==count($chunk)||count(array_filter($translated,'is_string'))!==count($chunk)){$fail[$id]=time();update_option('ires_ossreg_long_failures',$fail,false);wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);}
   $before=count($completed);
   $completed=array_merge($completed,array_values($translated));
   $payload=['schema'=>self::SCHEMA,'hash'=>$hash,'source'=>$texts,'translated'=>$completed];
   $written=update_post_meta($id,self::META,wp_slash($payload));
   wp_cache_delete($id,'post_meta');
   $stored=get_post_meta($id,self::META,true);
   $verified=is_array($stored)&&($stored['schema']??null)===self::SCHEMA&&($stored['hash']??null)===$hash&&($stored['source']??null)===$texts&&($stored['translated']??null)===$completed;
   if(!$verified){
    $fail[$id]=time();update_option('ires_ossreg_long_failures',$fail,false);
    wp_send_json_error(['message'=>'Salvataggio non verificato per record '.$id.'. Elaborazione bloccata per evitare chiamate Gemini duplicate.','id'=>$id,'write_result'=>$written,'stored_count'=>is_array($stored['translated']??null)?count($stored['translated']):null,'expected_count'=>count($completed)],409);
   }
   unset($fail[$id]);update_option('ires_ossreg_long_failures',$fail,false);
   wp_send_json_success(['done'=>false,'id'=>$id,'fields'=>count($translated),'before'=>$before,'after'=>count($completed),'verified'=>true]);
  }
  wp_send_json_success(['done'=>true]);
 }
}
add_action('wp_ajax_ires_ossreg_long_batch',['IRES_OSSREG_Nimble_Longtext','batch']);

add_action('wp_ajax_ires_ossreg_long_progress',['IRES_OSSREG_Nimble_Longtext','progress']);

add_action('wp_ajax_ires_ossreg_long_recover',['IRES_OSSREG_Nimble_Longtext','recover']);

add_action('wp_ajax_ires_ossreg_long_diagnose',['IRES_OSSREG_Nimble_Longtext','diagnose']);

add_action('wp_ajax_ires_ossreg_long_repair',['IRES_OSSREG_Nimble_Longtext','repair']);
