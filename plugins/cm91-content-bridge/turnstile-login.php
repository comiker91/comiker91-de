<?php
if(!defined('ABSPATH')) exit;
final class CM91_Turnstile_Login{
 const OPT='cm91_turnstile_settings';
 static function init(){
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_init',[__CLASS__,'save']);
  add_action('login_enqueue_scripts',[__CLASS__,'enqueue']);
  add_action('login_form',[__CLASS__,'field']);
  add_filter('authenticate',[__CLASS__,'check_login'],30,3);
  add_action('lostpassword_form',[__CLASS__,'field']);
  add_action('lostpassword_post',[__CLASS__,'check_lostpassword'],10,2);
  add_action('register_form',[__CLASS__,'field']);
  add_filter('registration_errors',[__CLASS__,'check_registration'],10,3);
 }
 static function cfg(){return array_merge(['enabled'=>0,'site_key'=>'','secret'=>''],(array)get_option(self::OPT,[]));}
 static function active(){ $c=self::cfg(); return !empty($c['enabled'])&&trim((string)$c['site_key'])!==''&&trim((string)$c['secret'])!==''; }
 static function expected_host(){return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));}
 static function action(){return sanitize_key((string)($_REQUEST['action']??'login'));}
 static function protected_action(){return in_array(self::action(),['login','lostpassword','retrievepassword','register'],true);}
 static function enqueue(){if(!self::active()||!self::protected_action())return;wp_enqueue_script('cloudflare-turnstile','https://challenges.cloudflare.com/turnstile/v0/api.js',[],null,true);}
 static function field(){if(!self::active()||!self::protected_action())return;$c=self::cfg();echo '<div class="cf-turnstile" data-sitekey="'.esc_attr($c['site_key']).'" data-action="'.esc_attr(self::action()).'" style="margin:12px 0"></div>';}
 static function verify(){
  if(!self::active())return true;
  $token=trim((string)($_POST['cf-turnstile-response']??''));if($token==='')return new WP_Error('turnstile_missing','Bitte bestätige die Sicherheitsprüfung.');
  $c=self::cfg();$body=['secret'=>(string)$c['secret'],'response'=>$token];if(!empty($_SERVER['REMOTE_ADDR']))$body['remoteip']=sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
  $r=wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify',['timeout'=>10,'body'=>$body]);
  if(is_wp_error($r))return new WP_Error('turnstile_unavailable','Die Sicherheitsprüfung ist derzeit nicht erreichbar. Bitte erneut versuchen.');
  $d=json_decode((string)wp_remote_retrieve_body($r),true);if(!is_array($d)||empty($d['success']))return new WP_Error('turnstile_invalid','Die Sicherheitsprüfung war nicht erfolgreich.');
  $host=strtolower((string)($d['hostname']??''));if($host===''||!hash_equals(self::expected_host(),$host))return new WP_Error('turnstile_hostname','Die Sicherheitsprüfung konnte dieser Website nicht zugeordnet werden.');
  return true;
 }
 static function check_login($user,$username,$password){if(!self::protected_action())return$user;$v=self::verify();return is_wp_error($v)?$v:$user;}
 static function check_lostpassword($errors,$user_data){$v=self::verify();if(is_wp_error($v))$errors->add($v->get_error_code(),$v->get_error_message());}
 static function check_registration($errors,$login,$email){$v=self::verify();if(is_wp_error($v))$errors->add($v->get_error_code(),$v->get_error_message());return$errors;}
 static function menu(){add_options_page('Bot-Schutz','Bot-Schutz','manage_options','comitement-turnstile-login',[__CLASS__,'page']);}
 static function save(){
  if(!is_admin()||($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_POST['option_page']??'')!=='comitement_turnstile_login')return;
  if(!current_user_can('manage_options'))wp_die('Nicht erlaubt.');check_admin_referer('comitement_turnstile_login_save');
  $old=self::cfg();$secret=trim((string)($_POST['secret']??''));$new=['enabled'=>!empty($_POST['enabled'])?1:0,'site_key'=>sanitize_text_field(wp_unslash($_POST['site_key']??'')),'secret'=>$secret!==''?sanitize_text_field(wp_unslash($secret)):(string)$old['secret']];
  update_option(self::OPT,$new,false);wp_safe_redirect(add_query_arg(['page'=>'comitement-turnstile-login','saved'=>1],admin_url('options-general.php')));exit;
 }
 static function page(){if(!current_user_can('manage_options'))return;$c=self::cfg();$ready=trim((string)$c['site_key'])!==''&&trim((string)$c['secret'])!=='';echo'<div class="wrap"><h1>Bot-Schutz</h1><p>Cloudflare Turnstile für WordPress-Login, Passwort-Reset und Registrierung. Ohne vollständige Konfiguration bleibt der Login offen.</p><p><strong>Hostname:</strong> <code>'.esc_html(self::expected_host()).'</code></p><form method="post">';wp_nonce_field('comitement_turnstile_login_save');echo'<input type="hidden" name="option_page" value="comitement_turnstile_login"><table class="form-table"><tr><th>Schutz aktivieren</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($c['enabled']),true,false).'> aktiv</label></td></tr><tr><th>Site Key</th><td><input class="regular-text" name="site_key" value="'.esc_attr((string)$c['site_key']).'" autocomplete="off"></td></tr><tr><th>Secret Key</th><td><input class="regular-text" type="password" name="secret" value="" placeholder="'.(!empty($c['secret'])?'gespeichert – leer lassen zum Beibehalten':'Secret eintragen').'" autocomplete="new-password"></td></tr><tr><th>Status</th><td>'.($ready?'<strong>Keys vollständig</strong>':'Keys unvollständig – Schutz wird nicht erzwungen').'</td></tr></table><p><button class="button button-primary">Speichern</button></p></form></div>';}
}
CM91_Turnstile_Login::init();
