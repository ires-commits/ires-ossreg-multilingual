<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional editorial English copy for standard WordPress news.
 * Kept separate from AI caches; the Italian post and its content are never changed.
 */
final class IRES_OSSREG_Editorial_EN {
 const META = '_ires_ossreg_editorial_en';
 const NONCE = 'ires_ossreg_editorial_en_save';
 const AJAX = 'ires_ossreg_editorial_en_generate';
 const IMPORT_AJAX = 'ires_ossreg_editorial_en_import';

 static function boot() {
  add_action('add_meta_boxes_post', [__CLASS__, 'metabox']);
  add_action('save_post_post', [__CLASS__, 'save'], 20, 2);
  add_filter('manage_post_posts_columns', [__CLASS__, 'columns']);
  add_action('manage_post_posts_custom_column', [__CLASS__, 'column'], 10, 2);
  add_action('wp_ajax_'.self::AJAX, [__CLASS__, 'generate']);
  add_action('wp_ajax_'.self::IMPORT_AJAX, [__CLASS__, 'import']);
 }

 static function state($id) {
  $data = get_post_meta((int)$id, self::META, true);
  if (!is_array($data)) return null;
  return [
   'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
   'excerpt' => is_string($data['excerpt'] ?? null) ? $data['excerpt'] : '',
   'content' => is_string($data['content'] ?? null) ? $data['content'] : '',
   'status' => ($data['status'] ?? '') === 'published' ? 'published' : 'draft',
  ];
 }

 static function published($id) {
  $p = get_post((int)$id);
  if (!$p || $p->post_type !== 'post') return null;
  $data = self::state($id);
  if (!$data || $data['status'] !== 'published' || trim($data['title']) === '' || trim(wp_strip_all_tags($data['content'])) === '') return null;
  return ['title' => $data['title'], 'excerpt' => $data['excerpt'], 'content' => $data['content'], 'editorial' => true];
 }

 /**
  * Retrieve the existing standard AI cache without changing it.
  * A stale translation may still be useful as a draft, but is never silently
  * published or treated as the current public translation.
  */
 static function cached_translation($id) {
  $p = get_post((int)$id);
  if (!$p || $p->post_type !== 'post') return null;
  $cache = get_post_meta($id, '_ires_ossreg_en_translation', true);
  if (!is_array($cache) || !is_string($cache['title'] ?? null) || !is_string($cache['content'] ?? null)
      || trim($cache['title']) === '' || trim(wp_strip_all_tags($cache['content'])) === '') return null;
  $hash = get_post_meta($id, '_ires_ossreg_en_source_hash', true);
  $current = hash('sha256', $p->post_title."\n".$p->post_content);
  return [
   'title' => $cache['title'],
   'content' => $cache['content'],
   'excerpt' => is_string($cache['excerpt'] ?? null) ? $cache['excerpt'] : '',
   'current' => is_string($hash) && hash_equals($hash, $current),
  ];
 }

 static function metabox() {
  add_meta_box('ires-ossreg-editorial-en', 'Versione inglese — OSSREG', [__CLASS__, 'render'], 'post', 'normal', 'high');
 }

 static function render($post) {
  $data = self::state($post->ID) ?: ['title'=>'', 'excerpt'=>'', 'content'=>'', 'status'=>'draft'];
  wp_nonce_field(self::NONCE, 'ires_ossreg_en_nonce');
  echo '<p>Inserisci qui la versione inglese della notizia. Il testo italiano rimane invariato. La traduzione approvata ha precedenza sulla cache Gemini in pagine, archivi e slider.</p>';
  echo '<p><label for="ires_ossreg_en_title"><strong>Titolo inglese</strong></label><br>';
  echo '<input type="text" id="ires_ossreg_en_title" name="ires_ossreg_en_title" class="widefat" value="'.esc_attr($data['title']).'"></p>';
  echo '<p><label for="ires_ossreg_en_excerpt"><strong>Estratto inglese</strong> (per homepage, slider e archivio; facoltativo)</label><br>';
  echo '<textarea id="ires_ossreg_en_excerpt" name="ires_ossreg_en_excerpt" class="widefat" rows="3">'.esc_textarea($data['excerpt']).'</textarea></p>';
  echo '<p><strong>Contenuto inglese</strong></p>';
  wp_editor($data['content'], 'ires_ossreg_en_content', [
   'textarea_name' => 'ires_ossreg_en_content',
   'textarea_rows' => 12,
   'media_buttons' => false,
   // Quicktags work inside Gutenberg's legacy meta-box area; HTML remains editable.
   'tinymce' => false,
   'quicktags' => true,
  ]);
  echo '<p><label for="ires_ossreg_en_status"><strong>Stato della versione inglese:</strong></label> ';
  echo '<select id="ires_ossreg_en_status" name="ires_ossreg_en_status">';
  echo '<option value="draft"'.selected($data['status'],'draft',false).'>Bozza EN — non pubblicare questa versione</option>';
  echo '<option value="published"'.selected($data['status'],'published',false).'>EN pubblicato — usa la versione redazionale</option>';
  echo '</select></p>';
  echo '<p class="description">Per pubblicare in inglese servono titolo e contenuto. Salva o aggiorna la notizia con il normale pulsante WordPress; le versioni IT ed EN restano associate a un unico articolo.</p>';
  if (!empty($data['status']) && $data['status'] === 'published' && !self::published($post->ID)) {
   echo '<p style="color:#b45309"><strong>Attenzione:</strong> titolo o contenuto inglese mancanti: la versione redazionale non può essere pubblicata.</p>';
  }
  $has_copy = trim($data['title'])!=='' || trim($data['excerpt'])!=='' || trim($data['content'])!=='';
  $status = $data['status'] === 'published' && self::published($post->ID) ? 'EN pubblicato' : ($has_copy ? 'Bozza EN' : 'EN redazionale mancante');
  echo '<p><strong>Stato attuale:</strong> '.esc_html($status).'.</p>';

  // Show the translation that already exists in the legacy AI cache.
  $cached = self::cached_translation($post->ID);
  if ($cached) {
   $current = $cached['current'];
   echo '<div style="padding:12px 15px;border-left:4px solid '.($current?'#2271b1':'#dba617').';background:#f6f7f7;margin:14px 0">';
   echo '<p><strong>'.($current?'Traduzione AI già disponibile':'Traduzione AI disponibile, ma non aggiornata').' — '.($current?'cache valida':'contenuto italiano modificato dopo la traduzione').'</strong></p>';
   echo '<p>Questa traduzione è già memorizzata nel sito, separatamente dai campi redazionali qui sopra. L’importazione non utilizza Gemini e non la pubblica automaticamente.</p>';
   if (!$current) echo '<p><strong>Attenzione:</strong> la traduzione potrebbe non riflettere l’ultima versione italiana. Puoi importarla soltanto come bozza da verificare.</p>';
   echo '<details><summary style="cursor:pointer"><strong>Visualizza traduzione esistente</strong></summary>';
   echo '<div style="margin-top:9px"><p><strong>Titolo:</strong> '.esc_html($cached['title']).'</p>';
   if ($cached['excerpt']!=='') echo '<p><strong>Estratto:</strong> '.esc_html($cached['excerpt']).'</p>';
   echo '<p><strong>Anteprima contenuto:</strong> '.esc_html(wp_trim_words(wp_strip_all_tags($cached['content']),100,'…')).'</p></div></details>';
   if (!$has_copy) {
    echo '<p><button type="button" class="button button-secondary" id="ires-ossreg-en-import">Importa traduzione esistente (senza Gemini)</button></p>';
   } else {
    echo '<p><em>È già presente una versione redazionale: non verrà sovrascritta.</em></p>';
   }
   echo '</div>';
  } else {
   echo '<p class="description">Nessuna traduzione completa nella cache standard di questo articolo. Se sul sito compare già del testo inglese, potrebbe provenire da un altro sistema di traduzione o dal contenuto originale.</p>';
  }

  echo '<p><button type="button" class="button" id="ires-ossreg-en-generate">Prepara bozza con Gemini (solo se necessario)</button> <span id="ires-ossreg-en-generation-status" role="status" aria-live="polite"></span></p>';
  echo '<p class="description">Per recuperare una traduzione esistente usa il pulsante «Importa» qui sopra. «Prepara bozza con Gemini» riutilizza prima una cache aggiornata; solo in sua assenza può eseguire una nuova richiesta a pagamento, previa conferma. Nessun testo redazionale esistente viene sovrascritto.</p>';
  $ajax = esc_js(admin_url('admin-ajax.php'));
  $generate_nonce = esc_js(wp_create_nonce(self::AJAX.'_'.$post->ID));
  $import_nonce = esc_js(wp_create_nonce(self::IMPORT_AJAX.'_'.$post->ID));
  $id = (int)$post->ID;
  $stale_js = $cached && !$cached['current'] ? 'true':'false';
  echo '<script>(function(){function init(){
  var generate=document.getElementById("ires-ossreg-en-generate");
  var importBtn=document.getElementById("ires-ossreg-en-import");
  var out=document.getElementById("ires-ossreg-en-generation-status");
  if(!out)return;
  function fields(){return {title:document.getElementById("ires_ossreg_en_title"),excerpt:document.getElementById("ires_ossreg_en_excerpt"),content:document.getElementById("ires_ossreg_en_content")};}
  function hasContent(f){return [f.title,f.excerpt,f.content].some(function(el){return el&&el.value.trim()!=="";});}
  async function run(button, action, nonce, stale){
   var f=fields();
   if(hasContent(f)){out.textContent="È presente una versione redazionale: non verrà sovrascritta.";return;}
   var prompt=stale ? "Questa traduzione AI potrebbe essere superata perché il contenuto italiano è stato modificato. Importarla comunque come bozza da rivedere, senza Gemini?" : action==="'.self::IMPORT_AJAX.'" ? "Importare la traduzione AI esistente come bozza inglese, senza Gemini?" : "Preparare una bozza inglese dal contenuto italiano SALVATO? Se non esiste una traduzione aggiornata in cache, sarà chiamato Gemini.";
   if(!window.confirm(prompt))return;
   button.disabled=true;out.textContent="Recupero in corso…";
   try {
    var fd=new FormData();fd.append("action",action);fd.append("post_id","'.$id.'");fd.append("_ajax_nonce",nonce);
    if(stale)fd.append("allow_stale","1");
    var response=await fetch("'.$ajax.'",{method:"POST",credentials:"same-origin",body:fd});
    var result=await response.json();
    if(!result.success)throw new Error(result.data&&result.data.message||"Operazione non riuscita");
    if(f.title)f.title.value=result.data.title||"";
    if(f.excerpt)f.excerpt.value=result.data.excerpt||"";
    if(f.content)f.content.value=result.data.content||"";
    var status=document.getElementById("ires_ossreg_en_status");if(status)status.value="draft";
    out.textContent="Importazione completata: versione EN salvata come bozza. Verifica il testo e scegli «EN pubblicato» quando pronto; poi aggiorna l’articolo. Nessuna pubblicazione automatica.";
    if(importBtn)importBtn.disabled=true;
   }catch(err){out.textContent=err.message;}finally{button.disabled=false;}
  }
  if(importBtn)importBtn.addEventListener("click",function(){run(importBtn,"'.self::IMPORT_AJAX.'","'.$import_nonce.'",'.$stale_js.');});
  if(generate)generate.addEventListener("click",function(){run(generate,"'.self::AJAX.'","'.$generate_nonce.'",false);});
 }
 if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);else init();
 })();</script>';
 }

 static function save($post_id, $post) {
  if (!($post instanceof WP_Post) || $post->post_type !== 'post' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
  if (empty($_POST['ires_ossreg_en_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ires_ossreg_en_nonce'])), self::NONCE)) return;
  if (!current_user_can('edit_post', $post_id)) return;
  $title = isset($_POST['ires_ossreg_en_title']) ? sanitize_text_field(wp_unslash($_POST['ires_ossreg_en_title'])) : '';
  $excerpt = isset($_POST['ires_ossreg_en_excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['ires_ossreg_en_excerpt'])) : '';
  $content = isset($_POST['ires_ossreg_en_content']) ? wp_kses_post(wp_unslash($_POST['ires_ossreg_en_content'])) : '';
  $requested = isset($_POST['ires_ossreg_en_status']) && $_POST['ires_ossreg_en_status'] === 'published';
  // Do not publish incomplete English copy.
  $status = $requested && trim($title)!=='' && trim(wp_strip_all_tags($content))!=='' ? 'published' : 'draft';
  if ($title==='' && $excerpt==='' && trim($content)==='') {
   delete_post_meta($post_id, self::META);
  } else {
   update_post_meta($post_id, self::META, wp_slash(compact('title','excerpt','content','status')));
  }
 }

 static function columns($columns) {
  $new = [];
  foreach ($columns as $key => $label) {
   $new[$key] = $label;
   if ($key === 'title') $new['ires_ossreg_en_status'] = 'Inglese OSSREG';
  }
  return $new;
 }

 static function column($column, $post_id) {
  if ($column !== 'ires_ossreg_en_status') return;
  $data = self::state($post_id);
  if ($data && $data['status'] === 'published' && self::published($post_id)) {
   echo '<strong style="color:#166534">✓ EN pubblicato</strong>';
  } elseif ($data && (trim($data['title'])!=='' || trim($data['content'])!=='')) {
   echo '<span style="color:#92400e">● Bozza EN</span>';
  } else {
   $automatic = get_post_meta($post_id, '_ires_ossreg_en_translation', true);
   $hash = get_post_meta($post_id, '_ires_ossreg_en_source_hash', true);
   $p = get_post($post_id);
   if ($p && is_array($automatic) && isset($automatic['title'],$automatic['content']) && hash_equals((string)$hash, hash('sha256',$p->post_title."\n".$p->post_content))) {
    echo '<span>Solo traduzione AI</span>';
   } else {
    echo '<span style="color:#64748b">EN mancante</span>';
   }
  }
 }

 /**
  * Explicit, AI-free import from legacy cache into an unpublished editorial draft.
  * Refuses to overwrite existing work and requires confirmation for stale data.
  */
 static function import() {
  $id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
  if (!$id || !current_user_can('edit_post',$id)) wp_send_json_error(['message'=>'Permesso negato'],403);
  check_ajax_referer(self::IMPORT_AJAX.'_'.$id);
  $post = get_post($id);
  if (!$post || $post->post_type !== 'post') wp_send_json_error(['message'=>'Articolo non valido'],400);
  $existing = self::state($id);
  if ($existing && (trim($existing['title'])!=='' || trim($existing['excerpt'])!=='' || trim($existing['content'])!=='')) {
   wp_send_json_error(['message'=>'Esiste già una versione inglese redazionale. Non verrà sovrascritta.'],409);
  }
  $cached = self::cached_translation($id);
  if (!$cached) wp_send_json_error(['message'=>'Nessuna traduzione completa nella cache standard di questo articolo. Nessuna chiamata Gemini effettuata.'],404);
  if (!$cached['current'] && empty($_POST['allow_stale'])) wp_send_json_error(['message'=>'La cache è precedente all’ultima modifica italiana. Conferma esplicitamente l’importazione come bozza da verificare.'],409);
  $title = sanitize_text_field($cached['title']);
  $content = wp_kses_post($cached['content']);
  $excerpt = sanitize_textarea_field($cached['excerpt']!=='' ? $cached['excerpt'] : wp_trim_words(wp_strip_all_tags(strip_shortcodes($content)),35,'…'));
  if ($title==='' || trim(wp_strip_all_tags($content))==='') wp_send_json_error(['message'=>'Testo inglese incompleto: impossibile importare.'],422);
  $data = ['title'=>$title,'excerpt'=>$excerpt,'content'=>$content,'status'=>'draft'];
  update_post_meta($id,self::META,wp_slash($data));
  wp_cache_delete($id,'post_meta');
  $saved=self::state($id);
  if (!$saved || $saved !== $data) wp_send_json_error(['message'=>'Il salvataggio non è stato verificato: controllare la cache prima di riprovare.'],500);
  wp_send_json_success(['title'=>$title,'excerpt'=>$excerpt,'content'=>$content,'status'=>'draft','source'=>'cache','stale'=>!$cached['current']]);
 }

 static function generate() {
  $id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
  if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['message'=>'Permesso negato'],403);
  check_ajax_referer(self::AJAX.'_'.$id);
  $p = get_post($id);
  if (!$p || $p->post_type !== 'post' || !in_array($p->post_status, ['publish','draft','pending','future','private'], true)) wp_send_json_error(['message'=>'Articolo non valido'],400);
  $old = self::state($id);
  if ($old && (trim($old['title'])!=='' || trim($old['content'])!=='' || trim($old['excerpt'])!=='')) wp_send_json_error(['message'=>'Una versione redazionale esiste già: non verrà sovrascritta.'],409);
  if (trim($p->post_title)==='' || trim(wp_strip_all_tags($p->post_content))==='') wp_send_json_error(['message'=>'Salva prima titolo e contenuto italiani.'],400);
  $cached = get_post_meta($id, '_ires_ossreg_en_translation', true);
  $hash = get_post_meta($id, '_ires_ossreg_en_source_hash', true);
  $valid = is_array($cached) && isset($cached['title'],$cached['content']) && is_string($cached['title']) && is_string($cached['content']) && hash_equals((string)$hash, hash('sha256',$p->post_title."\n".$p->post_content));
  if ($valid) {
   $translation = ['title'=>$cached['title'],'content'=>$cached['content'],'excerpt'=>$cached['excerpt']??''];
   $source = 'cache';
  } else {
   $settings = get_option('ires_ossreg_ml_settings', []);
   $key = is_array($settings) ? (string)($settings['api_key'] ?? '') : '';
   if ($key === '') wp_send_json_error(['message'=>'Chiave Gemini mancante. Configurala in Strumenti → OSSREG Multilingual.'],400);
   $model = is_array($settings) ? (string)($settings['model'] ?? 'gemini-2.5-flash') : 'gemini-2.5-flash';
   $prompt = "Translate this Italian WordPress news article into natural institutional English. Return ONLY JSON with three string fields title, excerpt and content. Excerpt should be a concise 1–2 sentence summary. Preserve original meaning, HTML tags, links, image markup, shortcodes, numbers and proper names. Do not invent facts.\n".wp_json_encode(['title'=>$p->post_title,'excerpt'=>$p->post_excerpt,'content'=>$p->post_content], JSON_UNESCAPED_UNICODE);
   $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
   $response = wp_remote_post($url, ['timeout'=>90,'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>$key],'body'=>wp_json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']])]);
   if (is_wp_error($response)) wp_send_json_error(['message'=>$response->get_error_message()],502);
   $code = wp_remote_retrieve_response_code($response);
   if ($code !== 200) wp_send_json_error(['message'=>'Gemini HTTP '.$code],502);
   $body = json_decode(wp_remote_retrieve_body($response),true);
   $translation = json_decode($body['candidates'][0]['content']['parts'][0]['text'] ?? '',true);
   if (!is_array($translation) || !is_string($translation['title']??null) || !is_string($translation['content']??null) || !is_string($translation['excerpt']??null)) wp_send_json_error(['message'=>'La risposta Gemini non è valida. Nessuna bozza salvata.'],502);
   $source = 'gemini';
  }
  $title = sanitize_text_field($translation['title']);
  $excerpt = sanitize_textarea_field($translation['excerpt']??'');
  $content = wp_kses_post($translation['content']);
  if (trim($title)==='' || trim(wp_strip_all_tags($content))==='') wp_send_json_error(['message'=>'La traduzione è vuota; non è stata salvata.'],502);
  $data = ['title'=>$title,'excerpt'=>$excerpt,'content'=>$content,'status'=>'draft'];
  update_post_meta($id,self::META,wp_slash($data));
  wp_send_json_success(['title'=>$title,'excerpt'=>$excerpt,'content'=>$content,'status'=>'draft','source'=>$source]);
 }
}
IRES_OSSREG_Editorial_EN::boot();
