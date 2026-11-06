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
   'Mappa'=>'Map',
   'Elenco sedi'=>'List of locations',
   'Trova l’ente DSU che ti interessa in base alla tua sede di studio.'=>'Find the student support agency for your place of study.',
   'Trova l\'ente DSU che ti interessa in base alla tua sede di studio.'=>'Find the student support agency for your place of study.',
   'Scarica'=>'Download',
   'Anteprima'=>'Preview',
   'Tipo file'=>'File type',
   'Anno'=>'Year',
   'Anno:'=>'Year:',
   'Autore:'=>'Author:',
   'Tipo file:'=>'File type:',
   'CERCA IL CORSO CHE TI INTERESSA'=>'FIND A COURSE',
   'Cerca il corso che ti interessa'=>'Find a course',
   'Si accede tramite un bando di concorso, pubblicato annualmente, a:'=>'Accommodation is allocated through an annual application process at:',
   'A Torino è possibile alloggiare presso le seguenti strutture residenziali, previo contatto.'=>'In Turin, accommodation is available at the following residences. Please contact them directly.',
   'Autore'=>'Author',
   'Gennaio'=>'January',
   'Marzo'=>'March',
   'Maggio'=>'May',
   'Agosto'=>'August',
   'Ottobre'=>'October',
   'Novembre'=>'November',
   'Dicembre'=>'December',
   'Febbraio'=>'February',
   'Aprile'=>'April',
   'Settembre'=>'September',
   'Luglio'=>'July',
   'Giugno'=>'June',
   'Le residenze universitarie EDISU Piemonte, sono situate in diverse sedi del Piemonte (Torino, Grugliasco, Novara, Alessandria, Vercelli, Cuneo). Il bando è lo stesso della borsa di studio ma il concorso è riservato agli studenti fuori sede. In totale EDISU dispone di quasi 2.000 posti letto. A chi beneficia dell’alloggio sono detratti dall’importo della borsa di studio 2.700 euro quale corrispettivo del servizio abitativo usufruito per undici mesi.'=>'EDISU Piemonte university residences are located across Piedmont (Turin, Grugliasco, Novara, Alessandria, Vercelli and Cuneo). The application process is the same as for scholarships, but accommodation is reserved for students living away from home. EDISU offers nearly 2,000 beds in total. Students receiving accommodation have €2,700 deducted from their scholarship to cover eleven months of housing.',
   'Il Collegio Universitario Renato Einaudi dispone complessivamente di quasi 800 posti letto distribuiti in cinque'=>'Renato Einaudi University College offers almost 800 beds across five',
   'tutte con sede a Torino. La selezione si basa sul merito e la retta è differenziata in base all’ISEE. Il vincitore può usufruire del posto letto per tutta la carriera universitaria purché rispetti i requisiti di merito previsti dal bando di conferma.'=>'all located in Turin. Admission is merit-based, and fees vary according to ISEE household income. Successful applicants may retain their accommodation throughout their university studies, provided they continue to meet the academic requirements.',
   'Il Collegio Camplus Bernini, con sede a Torino, dispone di 150 posti alloggio. Per accedere occorre presentare una domanda di ammissione. La selezione avviene in base a requisiti di merito e tramite un colloquio. La tariffa è all inclusive, ovvero comprende il vitto (colazione e cena), pulizie, utenze e dei servizi formativi.'=>'Camplus Bernini College in Turin provides 150 accommodation places. Applicants must submit an admission application and are selected on academic merit and through an interview. The all-inclusive fee covers meals (breakfast and dinner), cleaning, utilities and educational services.',
   'Corsi SSML'=>'SSML courses',
   'Residenze cui si accede tramite bando di concorso'=>'Accommodation allocated through a competitive application',
   'Residenze ad accesso diretto'=>'Direct-access accommodation',
   'La ricerca dell’alloggio è la prima necessità per uno studente fuori sede. Esistono diverse possibilità di sistemazione.'=>'Finding accommodation is a primary need for students living away from home. Several options are available.',
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
  foreach($ids as $id){
   $post=get_post($id);if(!$post)continue;
   $long=IRES_OSSREG_Nimble_Longtext::cached($post);
   if(!$long)continue;
   foreach($long['translated'] as $i=>$translated){
    $source=trim(wp_strip_all_tags($long['source'][$i]??''));
    $target=trim(wp_strip_all_tags($translated));
    if($source!==''&&$target!==''&&$source!==$target&&!isset($map[$source]))$map[$source]=$target;
   }
  }
  // Reuse the existing standard post/page translation cache for cards and event listings.
  $standard_ids=get_posts(['post_type'=>['post','page'],'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
  foreach($standard_ids as $id){
   $post=get_post($id);if(!$post)continue;
   $cached=get_post_meta($id,'_ires_ossreg_en_translation',true);
   $hash=get_post_meta($id,'_ires_ossreg_en_source_hash',true);
   if(!is_array($cached)||!isset($cached['title'])||!hash_equals((string)$hash,hash('sha256',$post->post_title."\\n".$post->post_content)))continue;
   $source=trim(wp_strip_all_tags($post->post_title));$target=trim(wp_strip_all_tags($cached['title']));
   if($source!==''&&$target!==''&&$source!==$target&&!isset($map[$source]))$map[$source]=$target;
  }
  $doc_ids=get_posts(['post_type'=>IRES_OSSREG_Dynamic_Cache::types(),'post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','suppress_filters'=>true]);
  foreach($doc_ids as $id){
   $post=get_post($id);if(!$post)continue;
   $translated=IRES_OSSREG_Dynamic_Cache::cached($post);
   if(!$translated)continue;
   foreach(IRES_OSSREG_Dynamic_Cache::source($post) as $i=>$source){
    $original=trim(wp_strip_all_tags($source));$english=trim(wp_strip_all_tags($translated[$i]??''));
    if($original!==''&&$english!==''&&$original!==$english&&!isset($map[$original]))$map[$original]=$english;
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
