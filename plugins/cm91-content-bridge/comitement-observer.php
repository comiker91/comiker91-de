<?php
if(!defined('ABSPATH')) exit;
if(!class_exists('Comitement_Site_Observer_V1')){
final class Comitement_Site_Observer_V1{
 const VERSION='1.2.0'; const NS='comitement-observer/v1';
 static function site_id(){return defined('COMITEMENT_OBSERVER_SITE_ID')?(string)COMITEMENT_OBSERVER_SITE_ID:sanitize_key(parse_url(home_url('/'),PHP_URL_HOST));}
 static function site_name(){return defined('COMITEMENT_OBSERVER_SITE_NAME')?(string)COMITEMENT_OBSERVER_SITE_NAME:get_bloginfo('name');}
 static function token_source():array{
  $consts=defined('COMITEMENT_OBSERVER_TOKEN_CONSTANTS')?(array)COMITEMENT_OBSERVER_TOKEN_CONSTANTS:[];
  foreach($consts as$c) if(is_string($c)&&$c!==''&&defined($c)&&constant($c)!=='') return ['type'=>'constant','key'=>$c,'value'=>(string)constant($c),'rotatable'=>false];
  $opts=defined('COMITEMENT_OBSERVER_TOKEN_OPTIONS')?(array)COMITEMENT_OBSERVER_TOKEN_OPTIONS:[];
  foreach($opts as$o){if(!is_string($o)||$o==='')continue;$v=(string)get_option($o,'');if($v!=='')return ['type'=>'option','key'=>$o,'value'=>$v,'rotatable'=>true];}
  $key='comitement_observer_token_'.self::site_id();$v=(string)get_option($key,'');
  if($v===''){$v=wp_generate_password(64,false,false);update_option($key,$v,false);}
  return ['type'=>'option','key'=>$key,'value'=>$v,'rotatable'=>true];
 }
 static function token(){return (string)(self::token_source()['value']??'');}
 static function allowed(WP_REST_Request $r){$a=self::token();$b=(string)$r->get_header('x-comitement-site-token');return ($a!==''&&$b!==''&&hash_equals($a,$b))?true:new WP_Error('comitement_observer_forbidden','Invalid Comitement observer token.',['status'=>403]);}
 static function rotate_active_source(){
  $s=self::token_source();
  if(($s['type']??'')==='constant') return new WP_Error('comitement_observer_constant','Der aktive Observer-Token kommt aus der Server-/PHP-Konstante '.($s['key']??'').'. Er kann nicht aus WordPress rotiert werden.',['status'=>409]);
  $key=(string)($s['key']??''); if($key==='') return new WP_Error('comitement_observer_source','Keine rotierbare Token-Quelle gefunden.',['status'=>500]);
  $old=(string)($s['value']??''); do{$new=wp_generate_password(64,false,false);}while($new===$old);
  update_option($key,$new,false); return ['ok'=>true,'source_type'=>'option','source_key'=>$key,'old'=>$old,'new'=>$new];
 }
 static function init(){add_action('rest_api_init',[__CLASS__,'routes']);add_action('admin_menu',[__CLASS__,'menu']);add_action('admin_post_comitement_observer_rotate',[__CLASS__,'rotate']);}
 static function routes(){
  register_rest_route(self::NS,'/status',['methods'=>'GET','callback'=>[__CLASS__,'status_with_core'],'permission_callback'=>[__CLASS__,'allowed']]);
  register_rest_route(self::NS,'/core-update',['methods'=>'POST','callback'=>[__CLASS__,'core_update'],'permission_callback'=>[__CLASS__,'allowed']]);
 }
 static function installed_wordpress_version(){$v='';$file=ABSPATH.WPINC.'/version.php';if(is_readable($file)){include $file;$v=(string)($wp_version??'');}return$v!==''?$v:(string)get_bloginfo('version');}
 static function core_update_offer(){
  if(defined('DISALLOW_FILE_MODS')&&DISALLOW_FILE_MODS)return null;
  require_once ABSPATH.'wp-admin/includes/update.php';
  $updates=get_core_updates(['dismissed'=>false]);if(!is_array($updates))return null;$current=self::installed_wordpress_version();
  foreach($updates as$u){if(!is_object($u))continue;$response=(string)($u->response??'');$version=(string)($u->current??$u->version??'');if(in_array($response,['upgrade','autoupdate'],true)&&$version!==''&&version_compare($version,$current,'>'))return$u;}
  return null;
 }
 static function core_update_state(){
  $u=self::core_update_offer();$current=self::installed_wordpress_version();$target=$u?(string)($u->current??$u->version??''):'';
  return['update_available'=>(bool)$u,'current_version'=>$current,'available_version'=>$target];
 }
 static function status_with_core(){
  $r=self::status();$data=$r instanceof WP_REST_Response?$r->get_data():(is_array($r)?$r:[]);
  $core=self::core_update_state();$data['capabilities']=array_merge((array)($data['capabilities']??[]),['core_update'=>true]);$actions=(array)($data['actions']??[]);if(!in_array('core_update',$actions,true))$actions[]='core_update';$data['actions']=$actions;
  if(!isset($data['updates'])||!is_array($data['updates']))$data['updates']=[];$data['updates']['core']=$core;
  return rest_ensure_response($data);
 }
 static function core_update(WP_REST_Request$r){
  if((string)$r->get_header('x-comitement-confirm')!=='core-update')return new WP_Error('comitement_core_confirmation','Explicit core-update confirmation missing.',['status'=>409]);
  if(defined('DISALLOW_FILE_MODS')&&DISALLOW_FILE_MODS)return new WP_Error('comitement_core_file_mods_disabled','WordPress file modifications are disabled.',['status'=>409]);
  if(get_transient('comitement_observer_core_update_lock'))return new WP_Error('comitement_core_update_locked','A WordPress Core update is already running.',['status'=>409]);
  $offer=self::core_update_offer();if(!$offer)return new WP_Error('comitement_core_current','No WordPress Core update is currently available.',['status'=>409]);
  $target=(string)($offer->current??$offer->version??'');$body=$r->get_json_params();$requested=is_array($body)?trim((string)($body['target_version']??'')):'';
  if($requested!==''&&$target!==''&&!hash_equals($target,$requested))return new WP_Error('comitement_core_target_changed','The available WordPress Core target changed. Refresh Fleet before updating.',['status'=>409,'available_version'=>$target]);
  $before=self::installed_wordpress_version();set_transient('comitement_observer_core_update_lock',['started_at'=>time(),'from'=>$before,'to'=>$target],10*MINUTE_IN_SECONDS);
  try{
   require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
   $skin=new Automatic_Upgrader_Skin();$upgrader=new Core_Upgrader($skin);$result=$upgrader->upgrade($offer,['allow_relaxed_file_ownership'=>true]);
   if(is_wp_error($result))return$result;if($result===false)return new WP_Error('comitement_core_update_failed','WordPress Core upgrader returned no successful result.',['status'=>500]);
   if(function_exists('wp_clean_update_cache'))wp_clean_update_cache();$after=self::installed_wordpress_version();
   if($target!==''&&version_compare($after,$target,'<'))return new WP_Error('comitement_core_update_unverified','Core files were processed, but the installed version could not be verified.',['status'=>500,'before_version'=>$before,'after_version'=>$after,'target_version'=>$target]);
   return rest_ensure_response(['ok'=>true,'before_version'=>$before,'after_version'=>$after,'target_version'=>$target]);
  }finally{delete_transient('comitement_observer_core_update_lock');}
 }
 static function menu(){add_management_page('Comitement Observer','Comitement Observer','manage_options','comitement-observer',[__CLASS__,'page']);}
 static function rotate(){if(!current_user_can('manage_options'))wp_die('Nicht erlaubt.');check_admin_referer('comitement_observer_rotate');$r=self::rotate_active_source();$args=is_wp_error($r)?['rotate_error'=>$r->get_error_code()]:['rotated'=>1];wp_safe_redirect(add_query_arg($args,admin_url('tools.php?page=comitement-observer')));exit;}
 static function page(){if(!current_user_can('manage_options'))return;$s=self::token_source();$t=(string)$s['value'];echo'<div class="wrap"><h1>Comitement Observer</h1><p>Read-only Statusschnittstelle für den privaten Comitement Hub.</p><table class="widefat striped" style="max-width:1000px"><tbody><tr><th>Website</th><td>'.esc_html(self::site_name()).'</td></tr><tr><th>Endpoint</th><td><code>'.esc_html(rest_url(self::NS.'/status')).'</code></td></tr><tr><th>Aktive Token-Quelle</th><td><code>'.esc_html(($s['type']??'').':'.($s['key']??'')).'</code></td></tr><tr><th>Site Token</th><td><code style="word-break:break-all">'.esc_html($t).'</code></td></tr></tbody></table>';if(!empty($s['rotatable']))echo'<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=comitement_observer_rotate'),'comitement_observer_rotate')).'">Aktiven Observer-Token rotieren</a></p>';else echo'<p><strong>Rotation gesperrt:</strong> Der aktive Token wird durch eine Server-/PHP-Konstante bereitgestellt und muss dort geändert werden.</p>';echo'</div>';}
 static function plugins(){require_once ABSPATH.'wp-admin/includes/plugin.php';$all=get_plugins();$active=(array)get_option('active_plugins',[]);$net=is_multisite()?(array)get_site_option('active_sitewide_plugins',[]):[];$up=get_site_transient('update_plugins');$out=[];foreach($all as$f=>$p){$slug=dirname($f)==='.'?basename($f,'.php'):dirname($f);$out[]=['file'=>$f,'slug'=>$slug,'name'=>(string)($p['Name']??$slug),'version'=>(string)($p['Version']??''),'active'=>in_array($f,$active,true)||isset($net[$f]),'network_active'=>isset($net[$f]),'update_available'=>isset($up->response[$f]),'available_version'=>isset($up->response[$f])?(string)($up->response[$f]->new_version??''):null];}usort($out,fn($a,$b)=>strcasecmp($a['name'],$b['name']));return$out;}
 static function scheduled(){$types=get_post_types(['show_ui'=>true],'names');unset($types['attachment']);$q=new WP_Query(['post_type'=>array_values($types),'post_status'=>'future','posts_per_page'=>50,'orderby'=>'date','order'=>'ASC','no_found_rows'=>true]);$o=[];foreach($q->posts as$p)$o[]=['id'=>(int)$p->ID,'title'=>get_the_title($p),'post_type'=>$p->post_type,'post_type_label'=>get_post_type_object($p->post_type)->labels->singular_name??$p->post_type,'scheduled_local'=>get_post_time('c',false,$p),'scheduled_utc'=>get_post_time('c',true,$p),'edit_url'=>get_edit_post_link($p->ID,'raw'),'url'=>get_permalink($p)];return$o;}
 static function cron(){$arr=function_exists('_get_cron_array')?_get_cron_array():[];$now=time();$due=0;$next=null;foreach((array)$arr as$ts=>$hooks){$ts=(int)$ts;if($next===null||$ts<$next)$next=$ts;if($ts<$now-300)foreach((array)$hooks as$events)foreach((array)$events as$instances)$due+=count((array)$instances);}return['disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON,'overdue_events'=>$due,'next_event_utc'=>$next?gmdate('c',$next):null];}
 static function issues($plugins,$theme,$cron){$o=[];$add=function($c,$s,$m)use(&$o){$o[]=['code'=>$c,'severity'=>$s,'message'=>$m];};if(!is_ssl())$add('https','critical','WordPress erkennt die Anfrage nicht als HTTPS.');if(version_compare(PHP_VERSION,'8.1','<'))$add('php_old','warning','PHP '.PHP_VERSION.' ist veraltet.');$n=count(array_filter($plugins,fn($p)=>!empty($p['update_available'])));if($n)$add('plugin_updates','warning',$n.' Plugin-Update(s) sind verfügbar.');if(!empty($theme['update_available']))$add('theme_update','warning','Für das aktive Theme ist ein Update verfügbar.');if(!empty($cron['disabled']))$add('cron_disabled','warning','WP-Cron ist deaktiviert; externer Cron muss funktionieren.');if(($cron['overdue_events']??0)>0)$add('cron_overdue','warning',$cron['overdue_events'].' Cron-Ereignis(se) sind mehr als fünf Minuten überfällig.');return$o;}
 static function status(){global$wp_version;$plugins=self::plugins();$t=wp_get_theme();$ut=get_site_transient('update_themes');$theme=['slug'=>$t->get_stylesheet(),'name'=>$t->get('Name'),'version'=>$t->get('Version'),'update_available'=>isset($ut->response[$t->get_stylesheet()]),'available_version'=>isset($ut->response[$t->get_stylesheet()])?(string)($ut->response[$t->get_stylesheet()]['new_version']??''):null];$cron=self::cron();$s=self::token_source();return rest_ensure_response(['ok'=>true,'schema_version'=>1,'observer_version'=>self::VERSION,'generated_at'=>current_time('mysql'),'auth_source'=>['type'=>$s['type'],'key'=>$s['key'],'rotatable'=>(bool)$s['rotatable']],'site'=>['id'=>self::site_id(),'name'=>self::site_name(),'url'=>home_url('/'),'wordpress'=>$wp_version,'php'=>PHP_VERSION,'timezone'=>wp_timezone_string(),'multisite'=>is_multisite()],'theme'=>$theme,'plugins'=>$plugins,'scheduled_posts'=>self::scheduled(),'cron'=>$cron,'updates'=>['plugins'=>count(array_filter($plugins,fn($p)=>!empty($p['update_available']))),'theme'=>!empty($theme['update_available'])?1:0],'issues'=>self::issues($plugins,$theme,$cron)]);}
}
Comitement_Site_Observer_V1::init();
}
