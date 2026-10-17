<?php
/**
 * Fleet test access: one isolated, signed metadata-only lane per integration.
 * Canonical source: comiker91/comitement. No production credential is read here.
 */
if (!defined('ABSPATH')) exit;
if (!class_exists('Comitement_Fleet_Test_Access', false)) {
final class Comitement_Fleet_Test_Access {
 const VERSION='1.0.0';
 const NS='comitement-test/v1';
 const LANES=['observer','contentbridge'];
 private static function option(string $lane):string {return 'comitement_fleet_test_'.$lane;}
 private static function valid_lane(string $lane):bool {return in_array($lane,self::LANES,true);}
 private static function crypto_key():string {return hash('sha256','comitement-fleet-test-v1|'.wp_salt('auth'),true);}
 private static function encrypt(string $plain):string {
  if(!function_exists('sodium_crypto_secretbox'))throw new RuntimeException('Strong test credential encryption unavailable.');
  $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  return 's1:'.base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,self::crypto_key()));
 }
 private static function secret(string $lane):string {
  $stored=(string)get_option(self::option($lane),'');
  if(!str_starts_with($stored,'s1:')||!function_exists('sodium_crypto_secretbox_open'))return '';
  $raw=base64_decode(substr($stored,3),true);$n=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
  if($raw===false||strlen($raw)<$n+SODIUM_CRYPTO_SECRETBOX_MACBYTES)return '';
  $plain=sodium_crypto_secretbox_open(substr($raw,$n),substr($raw,0,$n),self::crypto_key());
  return is_string($plain)?$plain:'';
 }
 /** Internal provisioning only. Never expose its return value in REST/CLI logs. */
 public static function rotate(string $lane):string {
  if(!self::valid_lane($lane)||!(defined('WP_CLI')&&WP_CLI)&&!current_user_can('manage_options'))throw new RuntimeException('Test credential provisioning denied.');
  $key='ctest_'.$lane.'_'.bin2hex(random_bytes(32));
  update_option(self::option($lane),self::encrypt($key),false);
  return $key;
 }
 public static function revoke(string $lane):void {
  if(!self::valid_lane($lane)||!(defined('WP_CLI')&&WP_CLI)&&!current_user_can('manage_options'))throw new RuntimeException('Test credential revocation denied.');
  delete_option(self::option($lane));
 }
 /** Extend existing bridge tools pages; no additional admin menu or REST mutation. */
 private static function settings_page():bool {
  return is_admin()&&current_user_can('manage_options')&&in_array((string)($_GET['page']??''),['comitement-observer','casino-tester-net-comitement','freecash-comitement','schwabenpark-comitement'],true);
 }
 public static function settings():void {
  if(!self::settings_page())return;
  $flash=get_transient('comitement_test_key_flash_'.get_current_user_id());
  delete_transient('comitement_test_key_flash_'.get_current_user_id());
  echo '<div class="notice notice-info"><h2>Fleet-Testzugang für STAGING</h2><p>Diese separaten Keys erlauben ausschließlich technische Metadatenprüfungen. Beiträge, Medien, Updates und Moderation sind gesperrt.</p>';
  if(is_array($flash)&&isset($flash['lane'])){
   $lane=(string)$flash['lane'];
   if(self::valid_lane($lane)){
    $key=self::secret($lane);
    if($key!==''&&hash_equals((string)($flash['fingerprint']??''),hash('sha256',$key))){
     echo '<p>Neuen '.esc_html($lane).'-Test-Key jetzt einmalig in die STAGING-Verbindung übernehmen:</p><p><code style="user-select:all;overflow-wrap:anywhere">'.esc_html($key).'</code></p>';
    }
   }
  }
  foreach(self::LANES as $lane){
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   wp_nonce_field('comitement_test_key_'.$lane);
   echo '<input type="hidden" name="action" value="comitement_fleet_test_key"><input type="hidden" name="lane" value="'.esc_attr($lane).'"><p><strong>'.esc_html($lane).'</strong>: '.(self::secret($lane)===''?'Nicht eingerichtet':'Eingerichtet').' &nbsp; <button class="button" name="operation" value="rotate">Test-Key neu erzeugen</button> <button class="button" name="operation" value="revoke">Test-Key widerrufen</button></p></form>';
  }
  echo '<p>Eine Rotation betrifft ausschließlich den jeweiligen Test-Key. Produktive Zugänge bleiben unverändert.</p></div>';
 }
 public static function settings_save():void {
  if(!current_user_can('manage_options'))wp_die('Nicht erlaubt.',403);
  $lane=(string)($_POST['lane']??'');if(!self::valid_lane($lane))wp_die('Ungültiger Testzugang.',400);
  check_admin_referer('comitement_test_key_'.$lane);
  $operation=(string)($_POST['operation']??'');
  if($operation==='rotate'){
   $key=self::rotate($lane);
   set_transient('comitement_test_key_flash_'.get_current_user_id(),['lane'=>$lane,'fingerprint'=>hash('sha256',$key)],60);
   unset($key);
  }elseif($operation==='revoke'){
   self::revoke($lane);delete_transient('comitement_test_key_flash_'.get_current_user_id());
  }else wp_die('Ungültige Aktion.',400);
  $fallback=admin_url('tools.php?page=comitement-observer');
  $return=wp_get_referer();
  wp_safe_redirect($return?:$fallback);exit;
 }
 public static function routes():void {
  register_rest_route(self::NS,'/preflight/(?P<lane>observer|contentbridge)',[
   'methods'=>'GET','callback'=>[__CLASS__,'preflight'],'permission_callback'=>[__CLASS__,'authorize']
  ]);
 }
 private static function deny(string $code,int $status=403) {return new WP_Error('comitement_test_'.$code,'Fleet test request denied.',['status'=>$status]);}
 public static function authorize($r) {
  $lane=(string)$r->get_param('lane');$route='/'.self::NS.'/preflight/'.$lane;
  // A dry_run flag, query parameter, alternate route, or POST can never widen access.
  if(!self::valid_lane($lane)||$r->get_method()!=='GET'||$r->get_route()!==$route||$r->get_body()!==''||$r->get_query_params())return self::deny('scope');
  $secret=self::secret($lane);if($secret==='')return self::deny('not_configured',503);
  $ts=(string)$r->get_header('x-comitement-timestamp');$nonce=(string)$r->get_header('x-comitement-nonce');$sig=(string)$r->get_header('x-comitement-signature');
  if(!ctype_digit($ts)||abs(time()-(int)$ts)>300||!preg_match('/^[a-zA-Z0-9_-]{16,128}$/D',$nonce)||!preg_match('/^[a-f0-9]{64}$/D',$sig))return self::deny('unauthorized',401);
  // Origin binding prevents signatures being redirected to a different Fleet site.
  $origin=rtrim(home_url('/'),'/');
  $signed="GET\n".$route."\n".$origin."\n".$ts."\n".$nonce."\n";
  if(!hash_equals(hash_hmac('sha256',$signed,$secret),$sig))return self::deny('unauthorized',401);
  $key='comitement_test_nonce_'.hash('sha256',$lane.'|'.$nonce);
  if(!add_option($key,time()+600,'','no'))return self::deny('replay',409);
  // Authentication metadata only; never calls an Observer, importer, update or media handler.
  global $wpdb;
  if(isset($wpdb))$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",$wpdb->esc_like('comitement_test_nonce_').'%',time()));
  return true;
 }
 public static function preflight($r) {
  $lane=(string)$r->get_param('lane');
  $routes=rest_get_server()->get_routes();
  $target=$lane==='observer'?'/comitement-observer/v1/status':'/contentbridge/v1/capabilities';
  $available=isset($routes[$target]);
  $result=['ok'=>true,'test_contract_version'=>self::VERSION,'environment'=>'PRODUCTION_TEST_ONLY','lane'=>$lane,
   'availability'=>$available?'AVAILABLE':'UNSUPPORTED','target_api_registered'=>$available,
   'capabilities'=>['test.auth','test.preflight'],'write_available'=>false,'production_data_read'=>false,
   'limitations'=>['Metadata-only preflight; no production API handler invoked.','Writes require isolated CI fixtures.']];
  $response=rest_ensure_response($result);$response->header('Cache-Control','no-store');return $response;
 }
}
add_action('rest_api_init',['Comitement_Fleet_Test_Access','routes']);
add_action('admin_notices',['Comitement_Fleet_Test_Access','settings']);
add_action('admin_post_comitement_fleet_test_key',['Comitement_Fleet_Test_Access','settings_save']);
}
