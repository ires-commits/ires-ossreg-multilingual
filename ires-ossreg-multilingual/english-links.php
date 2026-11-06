<?php
if (!defined('ABSPATH')) exit;
/** Rewrite only navigational links that resolve to a supported English route. */
final class IRES_OSSREG_English_Links {
 static function url($url){
  $decoded=html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8');
  if($decoded===''||preg_match('~^(?:#|mailto:|tel:|javascript:|data:)~i',$decoded))return $url;
  $home=home_url('/');
  $site=wp_parse_url($home);
  $parts=wp_parse_url($decoded);
  if(!is_array($parts))return $url;
  if(isset($parts['host'])&&strcasecmp(preg_replace('~^www\\.~i','',$parts['host']),preg_replace('~^www\\.~i','',$site['host']??''))!==0)return $url;
  if(isset($parts['scheme'])&&!in_array(strtolower($parts['scheme']),['http','https'],true))return $url;
  $base=rtrim($site['path']??'/','/');
  $path=$parts['path']??'';
  if($path===''||($base!==''&&strpos($path,$base.'/')!==0))return $url;
  $relative=ltrim(substr($path,strlen($base)),'/');
  if($relative===''||preg_match('~^(?:en(?:/|$)|wp-admin/|wp-content/|wp-includes/|wp-json/|feed/?$|xmlrpc\.php|wp-login\.php)~i',$relative))return $url;
  if(preg_match('~\.(?:pdf|zip|docx?|xlsx?|pptx?|png|jpe?g|gif|webp|svg|css|js|xml|json|mp4|mp3)(?:$|/)~i',$relative))return $url;
  $route=trim($relative,'/');
  $exists=false;
  if(preg_match('~^category/(.+?)(?:/page/[0-9]+)?$~',$route,$m)){
   $exists=(bool)get_category_by_path($m[1],false);
  }elseif(preg_match('~^documento/(.+)$~',$route,$m)){
   $p=get_page_by_path($m[1],OBJECT,'dlp_document');
   $exists=$p&&$p->post_status==='publish';
  }else{
   $p=get_page_by_path($route,OBJECT,['page','post']);
   if(!$p){$id=url_to_postid(home_url('/'.$relative));$p=$id?get_post($id):null;}
   $exists=$p&&$p->post_status==='publish'&&in_array($p->post_type,['page','post'],true);
  }
  if(!$exists)return $url;
  $new=home_url('/en/'.$relative);
  if(isset($parts['query']))$new.='?'.$parts['query'];
  if(isset($parts['fragment']))$new.='#'.$parts['fragment'];
  return esc_url($new);
 }
 static function render($html){
  return preg_replace_callback('~<a\b[^>]*>~i',static function($m){
   return preg_replace_callback('~\bhref\s*=\s*(["\'])(.*?)\1~is',static function($a){
    $new=self::url($a[2]);
    return 'href='.$a[1].esc_attr(html_entity_decode($new,ENT_QUOTES|ENT_HTML5,'UTF-8')).$a[1];
   },$m[0],1);
  },$html);
 }
}
