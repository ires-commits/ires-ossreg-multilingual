<?php
if (!defined('ABSPATH')) exit;
final class IRES_OSSREG_Nimble_Cache {
 const META='_ires_ossreg_nimble_en';
 static function fields($tree){
  $found=[];
  $walk=function($node,$depth=0)use(&$walk,&$found){
   if($depth>20||!is_array($node))return;
   foreach($node as $key=>$value){
    if(is_array($value)){$walk($value,$depth+1);continue;}
    if(!is_string($key)||!in_array($key,['heading_text','title_text','text_content','button_text','description','content'],true)||!is_string($value))continue;
    if(mb_strlen(trim(wp_strip_all_tags($value)))<3)continue;
    $found[]=['field'=>$key,'text'=>$value];
   }
  };
  $walk($tree);
  return $found;
 }
 static function translated_content($post){
  if(!($post instanceof WP_Post)||!in_array($post->post_type,['nimble_post_type','nimble_section','nimble_template'],true)||!is_serialized($post->post_content))return $post;
  $cache=get_post_meta($post->ID,self::META,true);
  if(!is_array($cache)||($cache['hash']??'')!==hash('sha256',$post->post_content)||!isset($cache['translations'])||!is_array($cache['translations']))return $post;
  $tree=maybe_unserialize($post->post_content);
  if(!is_array($tree)||count(self::fields($tree))!==count($cache['translations']))return $post;
  $index=0;$translated=$cache['translations'];
  $walk=function(&$node,$depth=0)use(&$walk,&$index,$translated){
   if($depth>20||!is_array($node))return;
   foreach($node as $key=>&$value){
    if(is_array($value)){$walk($value,$depth+1);continue;}
    if(!is_string($key)||!in_array($key,['heading_text','title_text','text_content','button_text','description','content'],true)||!is_string($value))continue;
    if(mb_strlen(trim(wp_strip_all_tags($value)))<3)continue;
    if(isset($translated[$index])&&is_string($translated[$index]))$value=$translated[$index];
    $index++;
   }
   unset($value);
  };
  $walk($tree);
  if($index!==count($translated))return $post;
  $copy=clone $post;$copy->post_content=serialize($tree);return $copy;
 }
 static function translate_query_posts($posts,$query){
  if(is_admin()||!isset($GLOBALS['ires_ossreg_nimble_frontend'])||!$GLOBALS['ires_ossreg_nimble_frontend'])return $posts;
  foreach($posts as $i=>$post){$posts[$i]=self::translated_content($post);}
  return $posts;
 }
 static function run(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Permesso negato'],403);
  check_ajax_referer('ires_ossreg_nimble_batch');
  $settings=get_option('ires_ossreg_ml_settings',[]);
  $key=$settings['api_key']??'';
  if(!$key)wp_send_json_error(['message'=>'Chiave Gemini assente'],400);
  $ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
  foreach($ids as $id){
   $failures=get_option('ires_ossreg_nimble_failures',[]);
   if(is_array($failures)&&isset($failures[$id])&&time()-(int)($failures[$id]['time']??0)<1800)continue;
   $post=get_post($id);if(!$post||!is_serialized($post->post_content))continue;
   $hash=hash('sha256',$post->post_content);
   $cached=get_post_meta($id,self::META,true);
   if(is_array($cached)&&($cached['hash']??'')===$hash)continue;
   $tree=maybe_unserialize($post->post_content);if(!is_array($tree))continue;
   $fields=self::fields($tree);if(!$fields)continue;
   $texts=array_column($fields,'text');
   $prompt="Translate the Italian user-visible text values in this JSON array into English. Preserve array order, HTML tags, URLs, placeholders, shortcodes, numbers and proper names. Return only a JSON array of translated strings.\n".wp_json_encode($texts,JSON_UNESCAPED_UNICODE);
   $model=$settings['model']??'gemini-2.5-flash';
   $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
   $resp=wp_remote_post($endpoint,['timeout'=>120,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$key],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
   if(is_wp_error($resp)){
    $failures=get_option('ires_ossreg_nimble_failures',[]);
    if(!is_array($failures))$failures=[];
    $failures[$id]=['time'=>time(),'error'=>sanitize_text_field($resp->get_error_message())];
    update_option('ires_ossreg_nimble_failures',$failures,false);
    wp_send_json_success(['done'=>false,'id'=>$id,'fields'=>0,'skipped'=>true,'message'=>'Timeout o errore di rete; record rinviato']);
   }
   if(wp_remote_retrieve_response_code($resp)!==200)wp_send_json_error(['message'=>'Gemini HTTP '.wp_remote_retrieve_response_code($resp)],400);
   $body=json_decode(wp_remote_retrieve_body($resp),true);
   $translated=json_decode($body['candidates'][0]['content']['parts'][0]['text']??'',true);
   if(!is_array($translated)||count($translated)!==count($texts))wp_send_json_error(['message'=>'Risposta incompleta per #'.$id],400);
   foreach($translated as $value){if(!is_string($value))wp_send_json_error(['message'=>'Formato risposta non valido'],400);}
   update_post_meta($id,self::META,['hash'=>$hash,'fields'=>$fields,'translations'=>array_values($translated)]);
   $failures=get_option('ires_ossreg_nimble_failures',[]);if(is_array($failures)&&isset($failures[$id])){unset($failures[$id]);update_option('ires_ossreg_nimble_failures',$failures,false);}
   wp_send_json_success(['done'=>false,'id'=>$id,'fields'=>count($fields)]);
  }
  wp_send_json_success(['done'=>true]);
 }
}
add_action('wp_ajax_ires_ossreg_nimble_batch',['IRES_OSSREG_Nimble_Cache','run']);

add_filter('the_posts',['IRES_OSSREG_Nimble_Cache','translate_query_posts'],9,2);
