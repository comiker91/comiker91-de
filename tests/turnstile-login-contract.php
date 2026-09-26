<?php
/* Behavioral authentication contract: run standalone; no WordPress/network needed. */
define('ABSPATH', __DIR__);
$GLOBALS['config']=[];$GLOBALS['hooks']=[];$GLOBALS['calls']=[];
class WP_Error {public $errors=[];function __construct($c='',$m=''){if($c)$this->add($c,$m);}function add($c,$m){$this->errors[$c]=$m;}function get_error_code(){return array_key_first($this->errors);}function get_error_message(){return reset($this->errors);}}
function add_action($h,$cb){$GLOBALS['hooks'][$h]=$cb;}function add_filter($h,$cb){add_action($h,$cb);}function get_option($k,$d=[]){return $GLOBALS['config'];}function home_url($p='/'){return 'https://production.example'.$p;}function wp_parse_url($u,$c){return parse_url($u,$c);}function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}function sanitize_text_field($s){return trim(strip_tags($s));}function wp_unslash($s){return stripslashes($s);}function is_wp_error($v){return $v instanceof WP_Error;}function wp_remote_post($url,$args){$GLOBALS['calls'][]=[$url,$args];return $GLOBALS['response'];}function wp_remote_retrieve_body($r){return $r['body'];}function wp_remote_retrieve_response_code($r){return $r['response']['code'];}function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_html($s){return esc_attr($s);}function current_user_can(){return true;}function wp_nonce_field(){}function checked($a,$b,$echo=false){return $a===$b?'checked':'';}function wp_enqueue_script(){}function add_options_page(){ $GLOBALS['menu']=func_get_args(); }
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL $m\n");exit(1);}echo "PASS $m\n";}
require __DIR__.'/../plugins/cm91-content-bridge/turnstile-login.php';
$c='CM91_Turnstile_Login';
$GLOBALS['pagenow']='wp-login.php';$GLOBALS['action']='login';$_SERVER['REQUEST_METHOD']='POST';$_POST=[];
foreach(['admin_menu','login_form','authenticate','lostpassword_form','lostpassword_post','register_form','registration_errors'] as $h)ok(isset($GLOBALS['hooks'][$h]),"hook $h");
$login=$GLOBALS['hooks']['authenticate'];$user=(object)['ID'=>1];
foreach([[],['enabled'=>1,'site_key'=>'site'],['enabled'=>1,'secret'=>'secret'],['enabled'=>0,'site_key'=>'site','secret'=>'secret']] as $config){$GLOBALS['config']=$config;ok(call_user_func($login,$user,'user','password')===$user,'incomplete/disabled preserves login');}
$GLOBALS['config']=['enabled'=>1,'site_key'=>'site','secret'=>'PRIVATE_SECRET'];
ok(is_wp_error(call_user_func($login,$user,'user','password')),'missing token rejected');
$_POST=['cf-turnstile-response'=>'token'];
$good=['success'=>true,'hostname'=>'production.example','action'=>'login'];
foreach([false,'true',1] as $success){$GLOBALS['response']=['response'=>['code'=>200],'body'=>json_encode(array_merge($good,['success'=>$success]))];ok(is_wp_error($c::verify()),'strict success');}
foreach([['hostname'=>'evil.example'],['action'=>'register']] as $bad){$GLOBALS['response']=['response'=>['code'=>200],'body'=>json_encode(array_merge($good,$bad))];ok(is_wp_error($c::verify()),'hostname/action mismatch rejected');}
$GLOBALS['response']=['response'=>['code'=>500],'body'=>json_encode($good)];ok(is_wp_error($c::verify()),'HTTP failure rejected');
$GLOBALS['response']=new WP_Error('timeout','timeout');ok(is_wp_error($c::verify()),'transport failure rejected');
$GLOBALS['response']=['response'=>['code'=>200],'body'=>'invalid'];ok(is_wp_error($c::verify()),'malformed response rejected');
$GLOBALS['response']=['response'=>['code'=>200],'body'=>json_encode($good)];ok(call_user_func($login,$user,'user','password')===$user,'verified human preserves auth flow');
$last=end($GLOBALS['calls']);ok($last[0]==='https://challenges.cloudflare.com/turnstile/v0/siteverify'&&$last[1]['body']['secret']==='PRIVATE_SECRET'&&$last[1]['body']['response']==='token','server-side HTTPS verification');
$_REQUEST['action']='bogus';ok($c::action()==='login','uses core normalized action');
foreach(['index.php','admin-ajax.php','xmlrpc.php'] as $page){$GLOBALS['pagenow']=$page;$_POST=[];ok(call_user_func($login,$user,'user','password')===$user,'machine flow untouched '.$page);}
$GLOBALS['pagenow']='wp-login.php';$_SERVER['REQUEST_METHOD']='GET';ok(call_user_func($login,$user,'user','password')===$user,'GET untouched');$_SERVER['REQUEST_METHOD']='POST';
foreach(['lostpassword_post','registration_errors'] as $hook){$e=new WP_Error();call_user_func($GLOBALS['hooks'][$hook],$e,'','');ok($e->get_error_code()==='turnstile_missing','protected '.$hook);}
ob_start();$c::page();$html=ob_get_clean();ok(strpos($html,'PRIVATE_SECRET')===false,'settings never disclose secret');$c::menu();ok($GLOBALS['menu'][0]==='Bot-Schutz','settings menu exists');
define('REST_REQUEST',true);ok(call_user_func($login,$user,'user','password')===$user,'REST request explicitly exempt');
echo "TURNSTILE_LOGIN_CONTRACT_OK=yes\n";
