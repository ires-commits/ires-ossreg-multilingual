<?php
/**
 * Plugin Name: IRES OSSREG Multilingual
 * Description: English translations for OSSREG with persistent caching and optional Gemini translation.
 * Version: 0.4.6
 * Requires PHP: 8.0
 * Text Domain: ires-ossreg-multilingual
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/github-updater.php';
final class IRES_OSSREG_Multilingual {
 const OPT='ires_ossreg_ml_settings';
 const META='_ires_ossreg_en_translation';
 const SOURCE='_ires_ossreg_en_source_hash';
 function __construct(){
  add_action('init',[$this,'rewrite']);
  add_action('template_redirect',[$this,'guard_preview'],0);
  add_filter('redirect_canonical',[$this,'canonical'],10,2);
  add_filter('wp_redirect',[$this,'preserve_english_url'],999,2);
  add_filter('request',[$this,'resolve_english_post']);
  add_action('parse_request',[$this,'route_english'],1);
  add_filter('query_vars',function($v){$v[]='ires_ossreg_en';return $v;});
  add_filter('post_link',[$this,'permalink'],10,2);
  add_filter('page_link',[$this,'page_permalink'],10,2);
  add_filter('the_content',[$this,'content'],50);
  add_filter('the_title',[$this,'title'],50,2);
  add_filter('document_title_parts',[$this,'document_title']);
  add_action('wp_head',[$this,'hreflang']);
  add_shortcode('ires_ossreg_language_switcher',[$this,'switcher']);
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
 function item($id){$t=get_post_meta($id,self::META,true);$hash=get_post_meta($id,self::SOURCE,true);$p=get_post($id);if(!$p||!is_array($t)||!isset($t['title'],$t['content']))return null;$current=hash('sha256',$p->post_title."\n".$p->post_content);return hash_equals((string)$hash,$current)?$t:null;}
 function content($content){if(!$this->enabled()||!is_singular()||!in_the_loop()||!is_main_query())return $content;$p=get_post();$t=$p?$this->item($p->ID):null;return $t?wp_kses_post($t['content']):$content;}
 function title($title,$id=0){if(!$this->enabled()||!$id||!is_singular()||get_queried_object_id()!=(int)$id)return $title;$t=$this->item($id);return $t?sanitize_text_field($t['title']):$title;}
 function document_title($parts){if($this->enabled()&&is_singular()){ $t=$this->item(get_queried_object_id());if($t)$parts['title']=$t['title'];}return $parts;}
 function hreflang(){if(!$this->opts()['public_enabled']||!is_singular())return;$url=get_permalink(get_queried_object_id());if(!$url)return;$it=str_replace(home_url('/en/'),home_url('/'),$url);$en=$this->path($it);echo '<link rel="alternate" hreflang="it" href="'.esc_url($it).'" />' . "\n";echo '<link rel="alternate" hreflang="en" href="'.esc_url($en).'" />' . "\n";}
 function switcher(){if(!$this->opts()['public_enabled']&&!current_user_can('manage_options'))return '';if(!is_singular())return '';$url=get_permalink(get_queried_object_id());$it=$this->enabled()?preg_replace('~/en/~','/',$url,1):$url;$en=$this->path($it);return '<nav aria-label="Language"><a href="'.esc_url($it).'" lang="it">IT</a> | <a href="'.esc_url($en).'" lang="en">EN</a></nav>';}
 function admin_menu(){add_management_page('OSSREG Multilingual','OSSREG Multilingual','manage_options','ires-ossreg-ml',[$this,'admin']);}
 function admin(){if(!current_user_can('manage_options'))return;$o=$this->opts();$posts=get_posts(['post_type'=>['post','page'],'post_status'=>'publish','numberposts'=>30,'orderby'=>'modified','order'=>'DESC']);echo '<div class="wrap"><h1>IRES OSSREG Multilingual — 0.4.6</h1><p>Versione sperimentale: pagine e articoli standard. I contenuti Nimble, menu e librerie dinamiche non sono ancora tradotti. Verificare su staging prima di attivare sul sito pubblico.</p>';
  if(isset($_GET['ires_ml_msg']))echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['ires_ml_msg']))).'</p></div>';
  echo '<p><strong>Modalità:</strong> '.($o['public_enabled']?'PUBBLICA':'TEST (solo amministratori)').'. Nessuna traduzione viene pubblicata finché non si abilita esplicitamente la modalità pubblica.</p>';
  echo '<h2>Configurazione</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_save">';wp_nonce_field('ires_ossreg_ml_save');echo '<table class="form-table"><tr><th>Gemini API key</th><td><input type="password" autocomplete="new-password" name="api_key" class="regular-text" placeholder="Lascia vuoto per mantenere la chiave attuale"><p>La chiave viene memorizzata nelle opzioni WordPress. Non inserire chiavi in repository pubblici.</p></td></tr><tr><th>Modello</th><td><input name="model" value="'.esc_attr($o['model']).'" class="regular-text"></td></tr><tr><th>Pubblicazione inglese</th><td><label><input type="checkbox" name="public_enabled" value="1" '.checked(!empty($o['public_enabled']),true,false).'> Abilita versione inglese pubblica (solo dopo i test)</label></td></tr></table>';submit_button('Salva configurazione');echo '</form>';
  echo '<h2>Aggiornamenti GitHub</h2><p>Versione installata: <strong>0.4.6</strong>. Il pulsante controlla direttamente la release ufficiale piu recente e installa solo versioni successive. Eseguire un backup prima di aggiornare.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ires_ossreg_ml_github_update">';wp_nonce_field('ires_ossreg_ml_github_update');submit_button('Verifica e aggiorna da GitHub','secondary','submit',false);echo '</form>';
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
