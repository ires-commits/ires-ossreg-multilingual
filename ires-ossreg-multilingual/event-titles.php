<?php
if (!defined('ABSPATH')) exit;
final class IRES_OSSREG_Event_Titles {
 const META='_ires_ossreg_event_title_en';
 static function types(){return array_values(array_diff(get_post_types(['public'=>true],'names'),['post','page','attachment','dlp_document']));}
 static function cached($p){
  $v=get_post_meta($p->ID,self::META,true);
  return is_array($v)&&($v['hash']??'')===hash('sha256',$p->post_title)?($v['title']??null):null;
 }
 static function map(){
  $map=[];
  foreach(get_posts(['post_type'=>self::types(),'post_status'=>'publish','numberposts'=>-1,'suppress_filters'=>true]) as $p){
   $en=self::cached($p);
   if(is_string($en)&&$en!==''&&$en!==$p->post_title)$map[trim($p->post_title)]=$en;
  }
  return $map;
 }
}
