<?php
if (!defined('ABSPATH')) exit;
final class IRES_OSSREG_GitHub_Updater {
 const REPO='ires-commits/ires-ossreg-multilingual';
 const SLUG='ires-ossreg-multilingual';
 const TRANSIENT='ires_ossreg_github_release';
 function __construct(){
  add_filter('pre_set_site_transient_update_plugins',[$this,'check']);
  add_filter('plugins_api',[$this,'info'],10,3);
  add_filter('upgrader_source_selection',[$this,'source'],10,4);
 }
 function release(){
  $cached=get_site_transient(self::TRANSIENT);
  if($cached!==false)return $cached;
  $response=wp_remote_get('https://api.github.com/repos/'.self::REPO.'/releases/latest',[
   'timeout'=>12,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'IRES-OSSREG-Multilingual']]);
  if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200){set_site_transient(self::TRANSIENT,[],HOUR_IN_SECONDS);return [];}
  $data=json_decode(wp_remote_retrieve_body($response),true);
  $version=ltrim((string)($data['tag_name']??''),'v');
  $zip='';
  foreach(($data['assets']??[]) as $asset){
   if(($asset['name']??'')==='ires-ossreg-multilingual.zip'){$zip=$asset['browser_download_url']??'';break;}
  }
  if(!preg_match('/^\d+\.\d+\.\d+$/',$version)||!preg_match('~^https://github\.com/ires-commits/ires-ossreg-multilingual/releases/download/~',$zip)){$data=[];}
  else {$data=['version'=>$version,'zip'=>$zip,'url'=>'https://github.com/'.self::REPO];}
  set_site_transient(self::TRANSIENT,$data,6*HOUR_IN_SECONDS);
  return $data;
 }
 function check($transient){
  if(!is_object($transient)||empty($transient->checked))return $transient;
  $plugin=plugin_basename(__DIR__.'/ires-ossreg-multilingual.php');
  $release=$this->release();
  if(!$release||empty($transient->checked[$plugin])||!version_compare($release['version'],$transient->checked[$plugin],'>'))return $transient;
  $transient->response[$plugin]=(object)['slug'=>self::SLUG,'plugin'=>$plugin,'new_version'=>$release['version'],'url'=>$release['url'],'package'=>$release['zip']];
  return $transient;
 }
 function info($result,$action,$args){
  if($action!=='plugin_information'||($args->slug??'')!==self::SLUG)return $result;
  $release=$this->release();if(!$release)return $result;
  return (object)['name'=>'IRES OSSREG Multilingual','slug'=>self::SLUG,'version'=>$release['version'],'author'=>'IRES Piemonte','homepage'=>$release['url'],'download_link'=>$release['zip'],'sections'=>['description'=>'Traduzione inglese di OSSREG con cache persistente e modalità test.']];
 }
 function source($source,$remote_source,$upgrader,$hook_extra){
  $plugin=plugin_basename(__DIR__.'/ires-ossreg-multilingual.php');
  if(($hook_extra['plugin']??'')!==$plugin)return $source;
  if(basename(untrailingslashit($source))===self::SLUG)return $source;
  $target=trailingslashit($remote_source).self::SLUG;
  global $wp_filesystem;
  if(!$wp_filesystem||$wp_filesystem->exists($target)||!$wp_filesystem->move($source,$target))return new WP_Error('ires_ossreg_package','Impossibile normalizzare la cartella del pacchetto GitHub.');
  return $target;
 }
}
new IRES_OSSREG_GitHub_Updater();
