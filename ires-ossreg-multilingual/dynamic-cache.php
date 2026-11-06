<?php
if (!defined('ABSPATH')) exit;
final class IRES_OSSREG_Dynamic_Cache {
 const META='_ires_ossreg_dynamic_en';
 static function types(){return ['dlp_document'];}
 static function source($p){return [$p->post_title,$p->post_excerpt,$p->post_content];}
 static function cached($p){
  $c=get_post_meta($p->ID,self::META,true);
  return is_array($c)&&($c['hash']??'')===hash('sha256',implode("\n",self::source($p)))&&isset($c['translations'])&&count($c['translations'])===3?$c['translations']:null;
 }
 static function batch(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Access denied'],403);
  check_ajax_referer('ires_ossreg_dynamic_batch');
  $o=get_option('ires_ossreg_ml_settings',[]);$key=$o['api_key']??'';
  if(!$key)wp_send_json_error(['message'=>'Missing Gemini key'],400);
  $ids=get_posts(['post_type'=>self::types(),'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
  $fail=get_option('ires_ossreg_dynamic_failures',[]);if(!is_array($fail))$fail=[];
  foreach($ids as $id){
   $p=get_post($id);if(!$p||self::cached($p))continue;
   if(isset($fail[$id])&&time()-(int)$fail[$id]<1800)continue;
   $src=self::source($p);
   $prompt="Translate these Italian publication metadata fields to English in the same array order: title, excerpt, HTML content. Preserve HTML, URLs, shortcodes, filenames, proper names, and numeric data. Return only a JSON array of three strings. Do not translate the linked PDF.\n".wp_json_encode($src,JSON_UNESCAPED_UNICODE);
   $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($o['model']??'gemini-2.5-flash').':generateContent';
   $response=wp_remote_post($endpoint,['timeout'=>75,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$key],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
   if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200){
    $fail[$id]=time();update_option('ires_ossreg_dynamic_failures',$fail,false);
    wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);
   }
   $body=json_decode(wp_remote_retrieve_body($response),true);
   $translated=json_decode($body['candidates'][0]['content']['parts'][0]['text']??'',true);
   if(!is_array($translated)||count($translated)!==3||count(array_filter($translated,'is_string'))!==3){
    $fail[$id]=time();update_option('ires_ossreg_dynamic_failures',$fail,false);
    wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);
   }
   update_post_meta($id,self::META,['hash'=>hash('sha256',implode("\n",$src)),'translations'=>array_values($translated)]);
   unset($fail[$id]);update_option('ires_ossreg_dynamic_failures',$fail,false);
   wp_send_json_success(['done'=>false,'id'=>$id]);
  }
  wp_send_json_success(['done'=>true]);
 }
}
add_action('wp_ajax_ires_ossreg_dynamic_batch',['IRES_OSSREG_Dynamic_Cache','batch']);
