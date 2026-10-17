<?php
/**
 * Fleet test access: one isolated, signed metadata-only lane per integration.
 * Canonical source: comiker91/comitement. No production credential is read here.
 */
if (!defined('ABSPATH')) exit;
if (class_exists('Comitement_Fleet_Test_Access', false)) return;
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
