<?php
if (!defined('ABSPATH')) exit;
final class IRES_OSSREG_Frontend_Translation {
 static function map(){
  $map=[
   'Dati e statistiche'=>'Data and statistics',
   'Studiare in Piemonte'=>'Studying in Piedmont',
   'Pubblicazioni'=>'Publications',
   'Convegni'=>'Conferences',
   'Archivio News >>'=>'News archive >>',
   'Archivio News'=>'News archive',
   'Leggi tutto'=>'Read more',
   'Programma (altro...)'=>'Programme (more...)',
   'Chi siamo'=>'About us',
   'Contatti'=>'Contact',
   'Ricerca'=>'Research',
  ];
  $ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
  foreach($ids as $id){
   $post=get_post($id);if(!$post||!is_serialized($post->post_content))continue;
   $cache=get_post_meta($id,IRES_OSSREG_Nimble_Cache::META,true);
   if(!is_array($cache)||($cache['hash']??'')!==hash('sha256',$post->post_content)||!isset($cache['fields'],$cache['translations'])||!is_array($cache['fields'])||!is_array($cache['translations'])||count($cache['fields'])!==count($cache['translations']))continue;
   foreach($cache['fields'] as $i=>$field){
    $source=trim(wp_strip_all_tags($field['text']??''));
    $target=trim(wp_strip_all_tags($cache['translations'][$i]??''));
    if(mb_strlen($source)<3||$target===''||$source===$target||isset($map[$source]))continue;
    // Avoid ambiguous source phrases whose translations disagree between records.
    $map[$source]=$target;
   }
  }
  return $map;
 }
 static function render($html){
  if(!is_string($html)||$html==='')return $html;
  $map=self::map();
  $parts=preg_split('~(<[^>]*>)~s',$html,-1,PREG_SPLIT_DELIM_CAPTURE);
  if(!is_array($parts))return $html;
  $blocked=0;$blockTags=['script','style','textarea','code','pre','svg','noscript'];
  foreach($parts as $i=>$part){
   if($part!==''&&$part[0]==='<'){
    if(preg_match('~^<(/?)([a-z][a-z0-9:-]*)\\b~i',$part,$m)&&in_array(strtolower($m[2]),$blockTags,true)){
     if($m[1]==='/')$blocked=max(0,$blocked-1);
     elseif(!preg_match('~/\\s*>$~',$part))$blocked++;
    }
    continue;
   }
   if($blocked||trim($part)==='')continue;
   $decoded=html_entity_decode(trim($part),ENT_QUOTES|ENT_HTML5,'UTF-8');
   if(!isset($map[$decoded]))continue;
   $replacement=esc_html($map[$decoded]);
   preg_match('/^\\s*/u',$part,$leading);preg_match('/\\s*$/u',$part,$trailing);
   $parts[$i]=($leading[0]??'').$replacement.($trailing[0]??'');
  }
  return implode('',$parts);
 }
}
