<?php
if (!defined('ABSPATH')) exit;
function ires_ossreg_event_batch(){
 if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Forbidden'],403);
 check_ajax_referer('ires_ossreg_event_batch');
 $o=get_option('ires_ossreg_ml_settings',[]);
 if(empty($o['api_key']))wp_send_json_error(['message'=>'Gemini key missing'],400);
 $ids=get_posts(['post_type'=>IRES_OSSREG_Event_Titles::types(),'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
 $fail=get_option('ires_ossreg_event_failures',[]);
 if(!is_array($fail))$fail=[];
 foreach($ids as $id){
  $p=get_post($id);
  if(!$p||trim($p->post_title)===''||IRES_OSSREG_Event_Titles::cached($p)!==null)continue;
  if(isset($fail[$id])&&time()-$fail[$id]<1800)continue;
  $prompt='Translate this Italian event or website content title into natural English. Preserve proper names and numbers. Return a JSON object with one string property called title: '.wp_json_encode($p->post_title,JSON_UNESCAPED_UNICODE);
  $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($o['model']??'gemini-2.5-flash').':generateContent';
  $response=wp_remote_post($url,['timeout'=>75,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$o['api_key']],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
  $data=is_wp_error($response)?null:json_decode(wp_remote_retrieve_body($response),true);
  $value=json_decode($data['candidates'][0]['content']['parts'][0]['text']??'',true);
  if(!is_array($value)||!is_string($value['title']??null)||trim($value['title'])===''){
   $fail[$id]=time();update_option('ires_ossreg_event_failures',$fail,false);
   wp_send_json_success(['done'=>false,'skipped'=>true,'id'=>$id]);
  }
  update_post_meta($id,IRES_OSSREG_Event_Titles::META,['hash'=>hash('sha256',$p->post_title),'title'=>sanitize_text_field($value['title'])]);
  unset($fail[$id]);update_option('ires_ossreg_event_failures',$fail,false);
  wp_send_json_success(['done'=>false,'id'=>$id]);
 }
 wp_send_json_success(['done'=>true]);
}
add_action('wp_ajax_ires_ossreg_event_batch','ires_ossreg_event_batch');
