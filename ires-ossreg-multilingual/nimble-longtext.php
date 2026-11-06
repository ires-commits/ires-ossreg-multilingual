<?php
if(!defined('ABSPATH'))exit;
final class IRES_OSSREG_Nimble_Longtext {
 const META='_ires_ossreg_nimble_long_en';
 static function fields($node){
  $out=[];$walk=function($value,$key='',$depth=0)use(&$walk,&$out){
   if($depth>22)return;
   if(is_array($value)){foreach($value as $k=>$v)$walk($v,is_string($k)?$k:'',$depth+1);return;}
   if(!is_string($value)||mb_strlen(trim(wp_strip_all_tags($value)))<85)return;
   if(preg_match('/(?:css|style|class|script|json|url|src|href|data|font|color|background|selector|html_id|shortcode)/i',$key))return;
   if(preg_match('~^(https?://|[.#][a-z_-]+\\s*\\{|\\[[-a-z_]+\\])~i',trim($value)))return;
   if(preg_match('/[{};]/',$value)&&substr_count($value,';')>3)return;
   $out[]=$value;
  };$walk($node);return array_values(array_unique($out));
 }
 static function cached($p){
  $c=get_post_meta($p->ID,self::META,true);
  return is_array($c)&&($c['hash']??'')===hash('sha256',$p->post_content)&&isset($c['source'],$c['translated'])&&is_array($c['source'])&&is_array($c['translated'])&&count($c['source'])===count($c['translated'])?$c:null;
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
   $completed=(is_array($existing)&&($existing['hash']??'')===$hash&&($existing['source']??[])===$texts&&is_array($existing['translated']??null))?$existing['translated']:[];
   $offset=count($completed);$chunk=array_slice($texts,$offset,3);
   if(!$chunk)continue;
   $prompt="Translate these Italian website paragraphs to natural English. Preserve HTML markup, links, names, numbers and structure. Return a JSON array with exactly ".count($chunk)." strings in original order.\\n".wp_json_encode($chunk,JSON_UNESCAPED_UNICODE);
   $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($settings['model']??'gemini-2.5-flash').':generateContent';
   $response=wp_remote_post($url,['timeout'=>75,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$key],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
   if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200){$fail[$id]=time();update_option('ires_ossreg_long_failures',$fail,false);wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);}
   $body=json_decode(wp_remote_retrieve_body($response),true);
   $translated=json_decode($body['candidates'][0]['content']['parts'][0]['text']??'',true);
   if(!is_array($translated)||count($translated)!==count($chunk)||count(array_filter($translated,'is_string'))!==count($chunk)){$fail[$id]=time();update_option('ires_ossreg_long_failures',$fail,false);wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);}
   $completed=array_merge($completed,array_values($translated));
   update_post_meta($id,self::META,['hash'=>$hash,'source'=>$texts,'translated'=>$completed]);
   unset($fail[$id]);update_option('ires_ossreg_long_failures',$fail,false);
   wp_send_json_success(['done'=>false,'id'=>$id,'fields'=>count($translated)]);
  }
  wp_send_json_success(['done'=>true]);
 }
}
add_action('wp_ajax_ires_ossreg_long_batch',['IRES_OSSREG_Nimble_Longtext','batch']);
