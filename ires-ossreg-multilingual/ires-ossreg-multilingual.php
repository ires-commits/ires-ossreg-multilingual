<?php
/**
 * Plugin Name: IRES OSSREG Multilingual
 * Description: English translations for OSSREG with persistent caching and optional Gemini translation.
 * Version: 0.4.28
 * Requires PHP: 8.0
 * Text Domain: ires-ossreg-multilingual
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/github-updater.php';
require_once __DIR__.'/nimble-cache.php';
require_once __DIR__.'/frontend-translation.php';
require_once __DIR__.'/dynamic-cache.php';
require_once __DIR__.'/nimble-longtext.php';
final class IRES_OSSREG_Multilingual {
 const OPT='ires_ossreg_ml_settings';
 const META='_ires_ossreg_en_translation';
 const SOURCE='_ires_ossreg_en_source_hash';
 function __construct(){
  add_action('init',[$this,'rewrite']);
  add_action('wp',function(){if($this->enabled())$GLOBALS['ires_ossreg_nimble_frontend']=true;},1);
  add_action('template_redirect',[$this,'guard_preview'],0);
  add_action('template_redirect',function(){if($this->enabled()&&!is_feed()&&!wp_doing_ajax()&&!is_robots()&&!is_trackback())ob_start(['IRES_OSSREG_Frontend_Translation','render']);},2);
  add_filter('redirect_canonical',[$this,'canonical'],10,2);
  add_filter('wp_redirect',[$this,'preserve_english_url'],999,2);
  add_filter('request',[$this,'resolve_english_post']);
  add_action('parse_request',[$this,'route_english'],1);
  add_filter('query_vars',function($v){$v[]='ires_ossreg_en';return $v;});
  add_filter('post_link',[$this,'permalink'],10,2);
  add_filter('page_link',[$this,'page_permalink'],10,2);
  add_filter('nav_menu_link_attributes',[$this,'menu_link'],20,4);
  add_filter('wp_nav_menu_objects',[$this,'menu_labels'],20,2);
  add_filter('wp_nav_menu_items',[$this,'menu_html'],25,2);
  add_filter('the_posts',[$this,'translated_posts'],20,2);
  add_filter('the_content',[$this,'content'],50);
  add_filter('the_title',[$this,'title'],50,2);
  add_filter('document_title_parts',[$this,'document_title']);
  add_action('wp_head',[$this,'hreflang']);
  add_shortcode('ires_ossreg_language_switcher',[$this,'switcher']);
  add_action('wp_footer',[$this,'floating_switcher'],100);
  add_action('wp_footer',[$this,'dynamic_catalog_translation'],120);
  add_action('admin_menu',[$this,'admin_menu']);
  add_action('admin_post_ires_ossreg_ml_test',[$this,'test_api']);
  add_action('admin_post_ires_ossreg_ml_github_update',[$this,'github_update']);
  add_action('admin_post_ires_ossreg_ml_save',[$this,'save']);
  add_action('admin_post_ires_ossreg_ml_translate',[$this,'translate_action']);
  add_action('admin_post_ires_ossreg_ml_flush',[$this,'flush']);
  add_action('wp_ajax_ires_ossreg_ml_batch',[$this,'batch_ajax']);
 }
 function opts(){return wp_parse_args(get_option(self::OPT,[]),['api_key'=>'','model'=>'gemini-2.5-flash','public_enabled'=>false]);}
 function enabled(){return !is_admin() && (string)get_query_var('ires_ossreg_en')==='1' && ($this->opts()['public_enabled'] || current_user_can('manage_options'));}
 function guard_preview(){if(!is_admin() && (string)get_query_var('ires_ossreg_en')==='1' && !$this->opts()['public_enabled'] && !current_user_can('manage_options')){status_header(404);nocache_headers();include get_404_template();exit;}}
 function rewrite(){add_rewrite_rule('^en/?$','index.php?ires_ossreg_en=1','top');add_rewrite_rule('^en/(.+)/?$','index.php?ires_ossreg_en=1&pagename=$matches[1]','top');}
 function preserve_english_url($location,$status){
  $path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
  $prefix=rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
  if(!is_string($path)||!preg_match('~^'.preg_quote($prefix,'~').'/en(?:/|$)~',$path))return $location;
  $target_path=wp_parse_url($location,PHP_URL_PATH);
  if(!is_string($target_path))return $location;
  $expected=substr($path,strlen($prefix)+3);
  if(trim($expected,'/')===trim(substr($target_path,strlen($prefix)),'/'))return false;
  return $location;
 }
 function canonical($redirect,$requested){return (string)get_query_var('ires_ossreg_en')==='1'?false:$redirect;}
 function route_english($wp){
  $request=trim((string)$wp->request,'/');
  if($request!=='en' && strpos($request,'en/')!==0)return;
  $wp->query_vars['ires_ossreg_en']='1';
  if($request==='en'){
   unset($wp->query_vars['pagename'],$wp->query_vars['name'],$wp->query_vars['p']);
   return;
  }
  $slug=substr($request,3);
  if(strpos($slug,'documento/')===0){
   $document_slug=trim(substr($slug,strlen('documento/')),'/');
   $document=get_page_by_path($document_slug,OBJECT,'dlp_document');
   if($document&&$document->post_status==='publish'){
    unset($wp->query_vars['pagename'],$wp->query_vars['page_id'],$wp->query_vars['p'],$wp->query_vars['error']);
    $wp->query_vars['name']=$document->post_name;
    $wp->query_vars['post_type']='dlp_document';
    return;
   }
  }
  $page=get_page_by_path($slug,OBJECT,'page');
  $post=$page?:get_page_by_path($slug,OBJECT,'post');
  if(!$post){
   $last=basename($slug);
   $matches=get_posts(['name'=>$last,'post_type'=>['post','page'],'post_status'=>'publish','numberposts'=>5,'suppress_filters'=>true]);
   foreach($matches as $candidate){
    $permalink=get_permalink($candidate->ID);
    $permalink_path=trim((string)wp_parse_url($permalink,PHP_URL_PATH),'/');
    if($permalink_path===$slug){$post=$candidate;break;}
   }
  }
  if($post){
   unset($wp->query_vars['pagename'],$wp->query_vars['name'],$wp->query_vars['page_id']);
   $wp->query_vars['p']=$post->ID;
   $wp->query_vars['post_type']=$post->post_type;
  }
 }
 function resolve_english_post($vars){
  if(empty($vars['ires_ossreg_en'])||empty($vars['pagename']))return $vars;
  $slug=trim($vars['pagename'],'/');
  if(get_page_by_path($slug,OBJECT,'page'))return $vars;
  $post=get_page_by_path($slug,OBJECT,'post');
  if($post){unset($vars['pagename']);$vars['p']=$post->ID;}
  return $vars;
 }
 function path($url){$home=trailingslashit(home_url('/'));if(strpos($url,$home)!==0)return $url;$relative=ltrim(substr($url,strlen($home)),'/');if(strpos($relative,'en/')===0)return $url;return home_url('/en/'. $relative);}
 function permalink($url,$post){return $this->enabled()?$this->path($url):$url;}
 function page_permalink($url){return $this->enabled()?$this->path($url):$url;}
 function menu_link($atts,$item,$args,$depth){
  if(!$this->enabled()||empty($atts['href']))return $atts;
  $url=$atts['href'];
  if(!is_string($url)||strpos($url,home_url('/'))!==0)return $atts;
  $atts['href']=$this->path($url);
  return $atts;
 }
 function menu_labels($items,$args){
  if(!$this->enabled())return $items;
  $labels=['home'=>'Home','chi siamo'=>'About us','contatti'=>'Contact','contattaci'=>'Contact us','pubblicazioni'=>'Publications','ricerca'=>'Research','notizie'=>'News','news'=>'News','eventi'=>'Events','osservatori'=>'Observatories','approfondimenti'=>'Insights','documenti'=>'Documents','rapporti'=>'Reports','indicatori'=>'Indicators','dati'=>'Data','statistiche'=>'Statistics','temi'=>'Topics','progetti'=>'Projects','servizi'=>'Services','informazioni'=>'Information','leggi tutto'=>'Read more','scopri di più'=>'Learn more','archivio'=>'Archive'];
  foreach($items as $item){
   $key=mb_strtolower(trim(wp_strip_all_tags($item->title)),'UTF-8');
   if(isset($labels[$key]))$item->title=$labels[$key];
   elseif(!empty($item->object_id)&&in_array($item->object??'',['post','page'],true)){$t=$this->item((int)$item->object_id);if($t&&!empty($t['title']))$item->title=sanitize_text_field($t['title']);}
   if(!empty($item->url)&&strpos($item->url,home_url('/'))===0)$item->url=$this->path($item->url);
  }
  return $items;
 }
 function menu_html($html,$args){
  if(!$this->enabled())return $html;
  return $html;
 }
 function item($id){$t=get_post_meta($id,self::META,true);$hash=get_post_meta($id,self::SOURCE,true);$p=get_post($id);if(!$p||!is_array($t)||!isset($t['title'],$t['content']))return null;$current=hash('sha256',$p->post_title."\n".$p->post_content);return hash_equals((string)$hash,$current)?$t:null;}
 function translated_posts($posts,$query){
  if(!$query->is_main_query()||!$query->is_singular()||!$this->enabled()||empty($posts))return $posts;
  foreach($posts as $i=>$post){
   if($post->post_type==='dlp_document'){
    $dynamic=IRES_OSSREG_Dynamic_Cache::cached($post);
    if($dynamic){$copy=clone $post;$copy->post_title=$dynamic[0];$copy->post_excerpt=$dynamic[1];$copy->post_content=$dynamic[2];$posts[$i]=$copy;}
    continue;
   }
   $translation=$this->item($post->ID);
   if(!$translation)continue;
   $copy=clone $post;
   $copy->post_title=$translation['title'];
   $copy->post_content=$translation['content'];
   $posts[$i]=$copy;
  }
  return $posts;
 }
 function content($content){if(!$this->enabled()||!is_singular()||!in_the_loop()||!is_main_query())return $content;$p=get_post();$t=$p?$this->item($p->ID):null;return $t?wp_kses_post($t['content']):$content;}
 function title($title,$id=0){if(!$this->enabled()||!$id||!is_singular()||get_queried_object_id()!=(int)$id)return $title;$t=$this->item($id);return $t?sanitize_text_field($t['title']):$title;}
 function document_title($parts){if($this->enabled()&&is_singular()){ $t=$this->item(get_queried_object_id());if($t)$parts['title']=$t['title'];}return $parts;}
 function hreflang(){if(!$this->opts()['public_enabled']||!is_singular())return;$url=get_permalink(get_queried_object_id());if(!$url)return;$it=str_replace(home_url('/en/'),home_url('/'),$url);$en=$this->path($it);echo '<link rel="alternate" hreflang="it" href="'.esc_url($it).'" />' . "\n";echo '<link rel="alternate" hreflang="en" href="'.esc_url($en).'" />' . "\n";}
 function language_urls(){
  $uri=(string)($_SERVER['REQUEST_URI']??'/');
  $path=(string)wp_parse_url($uri,PHP_URL_PATH);
  $base=rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
  $relative=ltrim(substr($path,strlen($base)),'/');
  if($relative==='en'||strpos($relative,'en/')===0)$relative=ltrim(substr($relative,2),'/');
  $it=home_url('/'.$relative);
  $en=home_url('/en/'.$relative);
  return [$it,$en];
 }
 function switcher(){
  if(!$this->opts()['public_enabled']&&!current_user_can('manage_options'))return '';
  list($it,$en)=$this->language_urls();
  $english=$this->enabled();
  return '<nav class="ires-ossreg-language" aria-label="Choose language"><a class="ires-lang-it'.(!$english?' is-current':'').'" href="'.esc_url($it).'" lang="it" hreflang="it" aria-label="Italiano" '.(!$english?'aria-current="page"':'').'>🇮🇹 <span>IT</span></a><a class="ires-lang-en'.($english?' is-current':'').'" href="'.esc_url($en).'" lang="en" hreflang="en" aria-label="English" '.($english?'aria-current="page"':'').'>🇬🇧 <span>EN</span></a></nav>';
 }
 function dynamic_catalog_translation(){
  if(!$this->enabled())return;
  $map=IRES_OSSREG_Frontend_Translation::map();
  $map['Agosto']='August';$map['Maggio']='May';$map['Gennaio']='January';$map['Marzo']='March';$map['Ottobre']='October';$map['Novembre']='November';$map['Dicembre']='December';
  $map['Strutture']='Accommodation facilities';$map['posti:']='beds:';$map['utenza:']='Gender:';
  $map['sezioni']='locations';
  echo '<script id="ires-ossreg-dynamic-translations">(function(){const translations='.wp_json_encode($map,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';const excluded="SCRIPT,STYLE,TEXTAREA,INPUT,OPTION,SELECT,CODE,PRE,SVG,NOSCRIPT";const ignored=new Set(excluded.split(","));const months={"Gennaio":"January","Febbraio":"February","Marzo":"March","Aprile":"April","Maggio":"May","Giugno":"June","Luglio":"July","Agosto":"August","Settembre":"September","Ottobre":"October","Novembre":"November","Dicembre":"December"};function translate(text){const t=text.trim();if(!t)return null;if(Object.prototype.hasOwnProperty.call(translations,t))return translations[t];let result=t;for(const [it,en] of Object.entries(months))result=result.replace(new RegExp("\\b"+it+"\\b","g"),en);result=result.replace(/^(posti|utenza|Periodo di chiusura)(\\s*:)/i,function(_,label,colon){return ({"posti":"beds","utenza":"Gender","periodo di chiusura":"Closure period"})[label.toLowerCase()]+colon});return result!==t?result:null;}function apply(root){if(!root||root.nodeType!==1)return;if(root.closest&&root.closest("#wpadminbar, .ires-ossreg-language, #ires-ossreg-dynamic-translations"))return;const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);const nodes=[];while(walker.nextNode()){const node=walker.currentNode;const parent=node.parentElement;if(!parent||ignored.has(parent.tagName)||parent.closest("#wpadminbar,script,style,textarea,code,pre,svg,noscript,[contenteditable=true]"))continue;nodes.push(node);}for(const node of nodes){const value=node.nodeValue;const replacement=translate(value);if(replacement!==null){const start=value.match(/^\\s*/u)[0],end=value.match(/\\s*$/u)[0];node.nodeValue=start+replacement+end;}}}let queued=false;const observer=new MutationObserver(function(){if(queued)return;queued=true;requestAnimationFrame(function(){queued=false;apply(document.body);});});function start(){if(!document.body)return;apply(document.body);observer.observe(document.body,{childList:true,subtree:true,characterData:false});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",start,{once:true});else start();})();</script>';
 }
 function floating_switcher(){
  if(is_admin()||(!$this->opts()['public_enabled']&&!current_user_can('manage_options')))return;
  echo '<style>.ires-ossreg-language{display:inline-flex;align-items:center;gap:3px;padding:5px;background:#fff;border:1px solid #dce3ea;border-radius:999px;box-shadow:0 4px 18px rgba(0,0,0,.14);font:600 12px/1.3 system-ui,sans-serif;z-index:99999}.ires-ossreg-language a{display:flex;align-items:center;gap:5px;padding:7px 9px;border-radius:999px;text-decoration:none!important;color:#24384b!important;white-space:nowrap}.ires-ossreg-language a.is-current{background:#e9f1fa;color:#143b63!important}.ires-ossreg-language a:focus-visible{outline:2px solid #1769aa;outline-offset:2px}.ires-ossreg-floating{position:fixed;top:14px;right:14px;z-index:99999}@media(max-width:600px){.ires-ossreg-floating{top:8px;right:8px}.ires-ossreg-language{padding:3px}.ires-ossreg-language a{padding:6px}}</style>';
  echo '<div class="ires-ossreg-floating">'.$this->switcher().'</div>';
  if(!$this->opts()['public_enabled'])return;
  list($it,$en)=$this->language_urls();
  echo '<script>(function(){try{var it='.wp_json_encode($it).',en='.wp_json_encode($en).';var pref=localStorage.getItem("ires_ossreg_language");var path=location.pathname;var enPath=new URL(en,location.href).pathname;var isEnglish=path===enPath;var isItalian=path===new URL(it,location.href).pathname;document.querySelectorAll(".ires-ossreg-language a").forEach(function(a){a.addEventListener("click",function(){localStorage.setItem("ires_ossreg_language",a.lang)})});if(!isItalian||isEnglish||location.search.indexOf("preview=")!==-1)return;var langs=navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||"it"];var browserLang=String(langs[0]||"it").toLowerCase();if((pref==="en"||(!pref&&browserLang.slice(0,2)!=="it"))&&location.pathname!==enPath){location.replace(en+location.search+location.hash)}}catch(e){}})();</script>';
 }

 function admin_menu(){add_management_page('OSSREG Multilingual','OSSREG Multilingual','manage_options','ires-ossreg-ml',[$this,'admin']);}
 function admin(){if(!current_user_can('manage_options'))return;$o=$this->opts();$posts=get_posts(['post_type'=>['post','page'],'post_status'=>'publish','numberposts'=>30,'orderby'=>'modified','order'=>'DESC']);echo '<div class="wrap"><h1>IRES OSSREG Multilingual — 0.4.28</h1><p>Versione sperimentale: pagine e articoli standard. I menu comuni sono tradotti con un glossario iniziale. I layout Nimble vengono tradotti nelle query WordPress compatibili usando la cache, senza modificare gli originali. Verificare le sezioni dinamiche e i menu in anteprima. Verificare su staging prima di attivare sul sito pubblico.</p>';
  if(isset($_GET['ires_ml_msg']))echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['ires_ml_msg']))).'</p></div>';
  echo '<p><strong>Modalità:</strong> '.($o['public_enabled']?'PUBBLICA':'TEST (solo amministratori)').'. Nessuna traduzione viene pubblicata finché non si abilita esplicitamente la modalità pubblica.</p>';
  echo '<h2>Configurazione</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_save">';wp_nonce_field('ires_ossreg_ml_save');echo '<table class="form-table"><tr><th>Gemini API key</th><td><input type="password" autocomplete="new-password" name="api_key" class="regular-text" placeholder="Lascia vuoto per mantenere la chiave attuale"><p>La chiave viene memorizzata nelle opzioni WordPress. Non inserire chiavi in repository pubblici.</p></td></tr><tr><th>Modello</th><td><input name="model" value="'.esc_attr($o['model']).'" class="regular-text"></td></tr><tr><th>Pubblicazione inglese</th><td><label><input type="checkbox" name="public_enabled" value="1" '.checked(!empty($o['public_enabled']),true,false).'> Abilita versione inglese pubblica (solo dopo i test)</label></td></tr></table>';submit_button('Salva configurazione');echo '</form>';
  echo '<h2>Aggiornamenti GitHub</h2><p>Versione installata: <strong>0.4.28</strong>. Il pulsante controlla direttamente la release ufficiale piu recente e installa solo versioni successive. Eseguire un backup prima di aggiornare.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_github_update">';wp_nonce_field('ires_ossreg_ml_github_update');submit_button('Verifica e aggiorna da GitHub','secondary','submit',false);echo '</form>';
  $nimble_counts=wp_count_posts('nimble_post_type');
  $nimble_total=$nimble_counts?array_sum((array)$nimble_counts):0;
  echo '<p><strong>Nimble:</strong> '.(int)$nimble_total.' record di layout. Traduzione strutturata attiva nelle query WordPress compatibili in anteprima inglese.</p>';
  echo '<details style="max-width:950px;margin:18px 0"><summary style="cursor:pointer;font-weight:600">Diagnostica avanzata (facoltativa)</summary><div style="padding:12px 16px;background:#fff;border:1px solid #ddd;margin-top:8px">';
  echo '<p>Homepage: '.esc_html(get_option('show_on_front')).'. Contenuti Nimble serializzati in post_content.</p>';
  $sample_ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'any','numberposts'=>12,'fields'=>'ids','orderby'=>'ID','order'=>'DESC']);
  $counts=[];
  $walk=function($node,$depth=0)use(&$walk,&$counts){
   if($depth>15||!is_array($node))return;
   foreach($node as $key=>$value){
    if(is_string($key)&&is_string($value)&&preg_match('/(?:text|title|heading|label|description|content|button)/i',$key)){
     $counts[$key]=($counts[$key]??0)+1;
    }
    if(is_array($value))$walk($value,$depth+1);
   }
  };
  foreach($sample_ids as $nid){$raw=(string)get_post_field('post_content',$nid,'raw');if(is_serialized($raw)){$tree=maybe_unserialize($raw);$walk($tree);}}
  arsort($counts);
  echo '<p>Campi testuali candidati (campione di '.count($sample_ids).' record, solo nomi e occorrenze):</p>';
  if(!$counts)echo '<p>Nessun campo individuato nel campione.</p>';
  foreach(array_slice($counts,0,25,true) as $key=>$n){echo '<p><code>'.esc_html($key).'</code>: '.(int)$n.'</p>';}
  echo '<p>Diagnostica di sola lettura. Non vengono mostrati valori, testi o credenziali.</p></div></details>';
  $nimble_ids=get_posts(['post_type'=>['nimble_post_type','nimble_section','nimble_template'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids']);
  $n_total=count($nimble_ids);$n_cached=0;$n_pending=0;$n_fields=0;$n_empty=0;
  foreach($nimble_ids as $nid){
   $np=get_post($nid);
   if(!$np||!is_serialized($np->post_content)){$n_empty++;continue;}
   $tree=maybe_unserialize($np->post_content);
   if(!is_array($tree)){$n_empty++;continue;}
   $candidates=IRES_OSSREG_Nimble_Cache::fields($tree);
   if(!$candidates){$n_empty++;continue;}
   $cache=get_post_meta($nid,IRES_OSSREG_Nimble_Cache::META,true);
   if(is_array($cache)&&($cache['hash']??'')===hash('sha256',$np->post_content)&&isset($cache['translations'])&&is_array($cache['translations'])&&count($cache['translations'])===count($candidates)){
    $n_cached++;$n_fields+=count($cache['translations']);
   }else{$n_pending++;}
  }
  echo '<h2>Stato traduzioni Nimble</h2><table class="widefat striped" style="max-width:680px"><tbody>';
  echo '<tr><th>Record Nimble pubblicati</th><td>'.(int)$n_total.'</td></tr>';
  echo '<tr><th>Record con traduzione aggiornata</th><td><strong>'.(int)$n_cached.'</strong></td></tr>';
  echo '<tr><th>Record da tradurre o aggiornare</th><td>'.(int)$n_pending.'</td></tr>';
  echo '<tr><th>Campi testuali tradotti in cache</th><td>'.(int)$n_fields.'</td></tr>';
  echo '<tr><th>Record senza campi traducibili o non supportati</th><td>'.(int)$n_empty.'</td></tr>';
  echo '</tbody></table><p>Conteggi aggiornati a ogni caricamento della pagina, senza chiamate a Gemini. La cache Nimble viene applicata alle query WordPress compatibili nella versione inglese. Alcuni moduli dinamici potrebbero richiedere integrazione dedicata.</p>';
  echo '<h2>Preparazione traduzioni Nimble</h2><p>Salva una cache inglese separata senza modificare i layout originali. La cache viene usata nella versione inglese quando disponibile; l\'anteprima rimane riservata agli amministratori finché non abiliti la pubblicazione.</p><p><button type="button" class="button" id="ires-nimble-run">Prepara traduzioni Nimble</button> <button type="button" class="button" id="ires-nimble-stop" disabled>Interrompi</button></p><p id="ires-nimble-result" role="status"></p>';
  echo '<script>document.addEventListener("DOMContentLoaded",function(){var run=document.getElementById("ires-nimble-run"),stop=document.getElementById("ires-nimble-stop"),out=document.getElementById("ires-nimble-result"),cancel=false;stop.onclick=function(){cancel=true};run.onclick=async function(){cancel=false;run.disabled=true;stop.disabled=false;out.textContent="Elaborazione avviata. Attendo la risposta di Gemini per il prossimo record...";var count=0,fields=0,skipped=0;try{while(!cancel){out.textContent="Richiesta in corso... Record completati in questa sessione: "+count+"; campi: "+fields+". Attendere fino a 2 minuti per risposta.";var fd=new FormData();fd.append("action","ires_ossreg_nimble_batch");fd.append("_ajax_nonce","'.esc_js(wp_create_nonce('ires_ossreg_nimble_batch')).'");var response=await fetch("'.esc_js(admin_url('admin-ajax.php')).'",{method:"POST",body:fd,credentials:"same-origin"});var data=await response.json();if(!data.success)throw new Error(data.data&&data.data.message||"Errore");if(data.data.done){out.textContent="Sessione terminata: "+count+" record tradotti, "+fields+" campi, "+skipped+" record rinviati. I rinviati saranno riprovati dopo 30 minuti.";break;}if(data.data.skipped){skipped++;out.textContent="Record #"+data.data.id+" rinviato per errore di rete. Rinviati: "+skipped+"; tradotti: "+count;continue;}count++;fields+=data.data.fields;out.textContent="Elaborati "+count+" record e "+fields+" campi. Rinviati: "+skipped+". Ultimo #"+data.data.id;}}catch(e){out.textContent="Interrotto: "+e.message}finally{run.disabled=false;stop.disabled=true}}});</script>';
  $document_ids=get_posts(['post_type'=>'dlp_document','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
  $document_done=0;
  foreach($document_ids as $document_id){$document=get_post($document_id);if($document&&IRES_OSSREG_Dynamic_Cache::cached($document))$document_done++;}
  echo '<h2>Testi lunghi Nimble</h2><p>Traduzione integrativa dei paragrafi lunghi, separata dalle cache già completate. Nessun layout o testo italiano viene modificato.</p>';
  echo '<p><button type="button" class="button" id="ires-long-run">Traduci paragrafi Nimble</button> <button type="button" class="button" id="ires-long-stop" disabled>Interrompi</button></p><p id="ires-long-status" role="status" aria-live="polite"></p>';
  echo '<script>document.addEventListener("DOMContentLoaded",function(){var run=document.getElementById("ires-long-run"),stop=document.getElementById("ires-long-stop"),out=document.getElementById("ires-long-status"),cancel=false;stop.onclick=function(){cancel=true;out.textContent="Interruzione richiesta: attendo la risposta in corso."};run.onclick=async function(){cancel=false;run.disabled=true;stop.disabled=false;var done=0,skipped=0,fields=0;try{while(!cancel){out.textContent="Traduzione paragrafi in corso: "+done+" richieste, "+fields+" campi, "+skipped+" rinviati.";var fd=new FormData();fd.append("action","ires_ossreg_long_batch");fd.append("_ajax_nonce","'.esc_js(wp_create_nonce('ires_ossreg_long_batch')).'");var response=await fetch("'.esc_js(admin_url('admin-ajax.php')).'",{method:"POST",body:fd,credentials:"same-origin"});var result=await response.json();if(!result.success)throw new Error(result.data?.message||"Errore");if(result.data.done){out.textContent="Elaborazione completata: "+fields+" paragrafi tradotti, "+skipped+" richieste rinviate.";break;}if(result.data.skipped)skipped++;else{done++;fields+=result.data.fields||0;}}}catch(e){out.textContent="Interrotto: "+e.message}finally{run.disabled=false;stop.disabled=true}}});</script>';
  echo '<h2>Traduzioni schede pubblicazioni</h2><p>Document Library Pro: '.(int)$document_done.' di '.count($document_ids).' schede in cache. Titoli, descrizioni e contenuti web; i PDF non vengono modificati.</p>';
  echo '<p><button type="button" class="button" id="ires-dynamic-run">Traduci schede pubblicazioni</button> <button type="button" class="button" id="ires-dynamic-stop" disabled>Interrompi</button></p><p id="ires-dynamic-result" role="status" aria-live="polite"></p>';
  echo '<script>document.addEventListener("DOMContentLoaded",function(){var run=document.getElementById("ires-dynamic-run"),stop=document.getElementById("ires-dynamic-stop"),out=document.getElementById("ires-dynamic-result"),cancel=false;stop.onclick=function(){cancel=true;out.textContent="Interruzione richiesta: attendo la risposta in corso."};run.onclick=async function(){cancel=false;run.disabled=true;stop.disabled=false;var done=0,skipped=0;try{while(!cancel){out.textContent="Elaborazione pubblicazioni in corso. Tradotte: "+done+"; rinviate: "+skipped;var fd=new FormData();fd.append("action","ires_ossreg_dynamic_batch");fd.append("_ajax_nonce","'.esc_js(wp_create_nonce('ires_ossreg_dynamic_batch')).'");var response=await fetch("'.esc_js(admin_url('admin-ajax.php')).'",{method:"POST",body:fd,credentials:"same-origin"});var result=await response.json();if(!result.success)throw new Error(result.data?.message||"Errore");if(result.data.done){out.textContent="Sessione completata. Tradotte: "+done+"; rinviate: "+skipped+". Aggiorna la pagina per i conteggi.";break;}if(result.data.skipped)skipped++;else done++;}}catch(e){out.textContent="Interrotto: "+e.message}finally{run.disabled=false;stop.disabled=true}}});</script>';
  echo '<h2>Diagnostica</h2><p>PHP '.esc_html(PHP_VERSION).' | WordPress '.esc_html(get_bloginfo('version')).' | Gemini: '.(!empty($o['api_key'])?'chiave configurata':'chiave assente').' | Anteprima: '.(!$o['public_enabled']?'attiva':'disattivata').'</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_test">';wp_nonce_field('ires_ossreg_ml_test');submit_button('Verifica connessione Gemini','secondary','submit',false);echo '</form>';
  echo '<h2>Traduzione batch (sperimentale)</h2><p>Elabora una pagina alla volta, soltanto mentre questa scheda rimane aperta. Le pagine con dati Nimble sono escluse automaticamente per evitare traduzioni parziali. Ogni richiesta Gemini può comportare costi o limiti di quota.</p><p><button type="button" class="button button-secondary" id="ires-ml-batch">Traduci contenuti standard mancanti</button> <button type="button" class="button" id="ires-ml-stop" disabled>Interrompi</button></p><p id="ires-ml-progress" role="status" aria-live="polite"></p>';
  echo '<script>document.addEventListener("DOMContentLoaded",function(){let stop=false;const run=document.getElementById("ires-ml-batch"),cancel=document.getElementById("ires-ml-stop"),out=document.getElementById("ires-ml-progress");cancel.onclick=()=>{stop=true;out.textContent="Interruzione richiesta: attendo la fine della traduzione in corso."};run.onclick=async()=>{stop=false;run.disabled=true;cancel.disabled=false;let n=0;try{while(!stop){const fd=new FormData();fd.append("action","ires_ossreg_ml_batch");fd.append("_ajax_nonce","'.esc_js(wp_create_nonce('ires_ossreg_ml_batch')).'");const response=await fetch("'.esc_js(admin_url('admin-ajax.php')).'",{method:"POST",body:fd,credentials:"same-origin"});const result=await response.json();if(!result.success)throw new Error(result.data?.message||"Errore di traduzione");if(result.data.done){out.textContent="Completato. Traduzioni nuove: "+n+". Aggiorna la pagina per vedere gli stati.";break;}n++;out.textContent="Tradotte "+n+" pagine. Ultima: "+result.data.title;}}catch(e){out.textContent="Interrotto: "+e.message;}finally{run.disabled=false;cancel.disabled=true;}}});</script>';
  echo '<h2>Traduzioni disponibili</h2><table class="widefat striped"><thead><tr><th>Contenuto</th><th>Stato</th><th>Azione</th></tr></thead><tbody>';
  foreach($posts as $p){$t=$this->item($p->ID);echo '<tr><td>'.esc_html($p->post_title).' (#'.(int)$p->ID.')</td><td>'.($t?'In cache':'Da tradurre / da aggiornare').'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_translate"><input type="hidden" name="post_id" value="'.(int)$p->ID.'">';wp_nonce_field('ires_ossreg_ml_translate_'.$p->ID);submit_button($t?'Rigenera':'Traduci','secondary','submit',false);echo '</form></td></tr>';}
  echo '</tbody></table><p>Per inserire il selettore lingue, usare lo shortcode <code>[ires_ossreg_language_switcher]</code> in una pagina o nell’area shortcode del tema.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_flush">';wp_nonce_field('ires_ossreg_ml_flush');submit_button('Rigenera permalink (una volta dopo installazione)','secondary');echo '</form></div>';
 }
 function github_update(){
  if(!current_user_can('update_plugins')||!current_user_can('manage_options'))wp_die('Permesso negato');
  check_admin_referer('ires_ossreg_ml_github_update');
  delete_site_transient(IRES_OSSREG_GitHub_Updater::TRANSIENT);
  $updater=new IRES_OSSREG_GitHub_Updater();
  $release=$updater->release();
  if(!$release||empty($release['version'])||empty($release['zip'])){
   $this->redirect('Nessuna release GitHub valida disponibile. Controlla GitHub Actions e la release.');
  }
  $plugin=plugin_basename(__FILE__);
  if(!function_exists('is_plugin_active'))require_once ABSPATH.'wp-admin/includes/plugin.php';
  $was_active=is_plugin_active($plugin);
  $was_network_active=is_multisite() && is_plugin_active_for_network($plugin);
  if(!function_exists('get_plugin_data'))require_once ABSPATH.'wp-admin/includes/plugin.php';
  $installed=get_plugin_data(__FILE__,false,false)['Version'];
  if(!version_compare($release['version'],$installed,'>')){
   $this->redirect('Plugin aggiornato: versione installata '.$installed.', ultima release '.$release['version']);
  }
  require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
  require_once ABSPATH.'wp-admin/includes/update.php';
  wp_update_plugins();
  $transient=get_site_transient('update_plugins');
  if(!is_object($transient))$transient=new stdClass();
  if(!isset($transient->response)||!is_array($transient->response))$transient->response=[];
  $transient->response[$plugin]=(object)[
   'slug'=>IRES_OSSREG_GitHub_Updater::SLUG,
   'plugin'=>$plugin,
   'new_version'=>$release['version'],
   'url'=>$release['url'],
   'package'=>$release['zip']
  ];
  set_site_transient('update_plugins',$transient);
  $skin=new Automatic_Upgrader_Skin();
  $installer=new Plugin_Upgrader($skin);
  $result=$installer->upgrade($plugin);
  if(is_wp_error($result))$this->redirect('Aggiornamento fallito: '.$result->get_error_message());
  if(!$result)$this->redirect('Aggiornamento non completato. Verificare permessi file e diagnostica WordPress.');
  wp_clean_plugins_cache(true);
  if(($was_active||$was_network_active)&&!is_plugin_active($plugin)&&!is_plugin_active_for_network($plugin)){
   $activation=activate_plugin($plugin,'',$was_network_active,true);
   if(is_wp_error($activation))$this->redirect('Aggiornamento completato ma riattivazione fallita: '.$activation->get_error_message());
  }
  if(($was_active||$was_network_active)&&!is_plugin_active($plugin)&&!is_plugin_active_for_network($plugin))$this->redirect('Aggiornamento completato ma plugin non attivo: verificare la pagina Plugin.');
  $this->redirect('Aggiornamento GitHub completato alla versione '.$release['version'].'; plugin '.(is_plugin_active($plugin)||is_plugin_active_for_network($plugin)?'attivo':'non attivo').'.');
 }
 function test_api(){
  if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('ires_ossreg_ml_test');
  $o=$this->opts();if(empty($o['api_key']))$this->redirect('Chiave Gemini non configurata');
  $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($o['model']);
  $r=wp_remote_post($endpoint.':generateContent',['timeout'=>30,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$o['api_key']],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>'Say OK']]]]])]);
  if(is_wp_error($r))$this->redirect('Connessione fallita: '.$r->get_error_message());
  $code=wp_remote_retrieve_response_code($r);
  $this->redirect($code===200?'Generazione Gemini riuscita':'Gemini HTTP '.$code.': '.substr(wp_strip_all_tags(wp_remote_retrieve_body($r)),0,450));
 }
 function redirect($message){wp_safe_redirect(add_query_arg(['page'=>'ires-ossreg-ml','ires_ml_msg'=>$message],admin_url('tools.php')));exit;}
 function save(){if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('ires_ossreg_ml_save');$o=$this->opts();$key=isset($_POST['api_key'])?trim(sanitize_text_field(wp_unslash($_POST['api_key']))):'';if($key!=='')$o['api_key']=$key;$o['model']=isset($_POST['model'])?sanitize_text_field(wp_unslash($_POST['model'])):'gemini-2.5-flash';$o['public_enabled']=!empty($_POST['public_enabled']);update_option(self::OPT,$o,false);$this->redirect('Configurazione salvata');}
 function flush(){if(!current_user_can('manage_options'))wp_die('Forbidden');check_admin_referer('ires_ossreg_ml_flush');$this->rewrite();flush_rewrite_rules(false);$this->redirect('Regole permalink aggiornate');}
 function nimble($id){
  $keys=array_keys(get_post_meta($id));
  foreach($keys as $key){if(stripos($key,'nimble')!==false)return true;}
  return false;
 }
 function translate_post($id){
  $p=get_post($id);
  if(!$p||!in_array($p->post_type,['post','page'],true)||$p->post_status!=='publish')return new WP_Error('invalid','Contenuto non pubblicato o non valido');
  if($this->nimble($id))return new WP_Error('nimble','Contenuto Nimble: traduzione non ancora supportata');
  $o=$this->opts();if(empty($o['api_key']))return new WP_Error('api_key','Chiave Gemini mancante');
  $prompt="Translate the following Italian WordPress content into natural institutional English. Preserve HTML tags, shortcodes, URLs, numbers, proper names and factual meaning. Return ONLY valid JSON with two string fields: title and content. Do not add commentary.\n".wp_json_encode(['title'=>$p->post_title,'content'=>$p->post_content],JSON_UNESCAPED_UNICODE);
  $endpoint='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($o['model']).':generateContent';
  $resp=wp_remote_post($endpoint,['timeout'=>120,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$o['api_key']],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
  if(is_wp_error($resp))return $resp;
  $status=wp_remote_retrieve_response_code($resp);if($status!==200)return new WP_Error('gemini','Gemini HTTP '.$status.': '.substr(wp_strip_all_tags(wp_remote_retrieve_body($resp)),0,450));
  $body=json_decode(wp_remote_retrieve_body($resp),true);
  $translated=json_decode($body['candidates'][0]['content']['parts'][0]['text']??'',true);
  if(!is_array($translated)||!isset($translated['title'],$translated['content'])||!is_string($translated['title'])||!is_string($translated['content']))return new WP_Error('json','Risposta Gemini non valida');
  update_post_meta($id,self::META,['title'=>sanitize_text_field($translated['title']),'content'=>wp_kses_post($translated['content'])]);
  update_post_meta($id,self::SOURCE,hash('sha256',$p->post_title."\n".$p->post_content));
  return true;
 }
 function translate_action(){
  if(!current_user_can('manage_options'))wp_die('Forbidden');
  $id=isset($_POST['post_id'])?absint($_POST['post_id']):0;
  check_admin_referer('ires_ossreg_ml_translate_'.$id);
  $result=$this->translate_post($id);
  $this->redirect(is_wp_error($result)?$result->get_error_message():'Traduzione salvata per ID '.$id);
 }
 function batch_ajax(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Permesso negato'],403);
  check_ajax_referer('ires_ossreg_ml_batch');
  $ids=get_posts(['post_type'=>['post','page'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
  foreach($ids as $id){
   if($this->nimble($id)||$this->item($id))continue;
   $result=$this->translate_post($id);
   if(is_wp_error($result))wp_send_json_error(['message'=>$result->get_error_message()],400);
   wp_send_json_success(['done'=>false,'title'=>get_the_title($id)]);
  }
  wp_send_json_success(['done'=>true]);
 }

}
new IRES_OSSREG_Multilingual();
register_activation_hook(__FILE__,function(){add_rewrite_rule('^en/?$','index.php?ires_ossreg_en=1','top');add_rewrite_rule('^en/(.+)/?$','index.php?ires_ossreg_en=1&pagename=$matches[1]','top');flush_rewrite_rules(false);});
register_deactivation_hook(__FILE__,function(){flush_rewrite_rules(false);});
