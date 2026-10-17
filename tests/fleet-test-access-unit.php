<?php
define('ABSPATH',__DIR__.'/');define('WP_CLI',true);
$GLOBALS['options']=[];$GLOBALS['origin']='https://site.fixture.invalid';$GLOBALS['routes']=['/comitement-observer/v1/status'=>[], '/contentbridge/v1/capabilities'=>[]];
function wp_salt($x){return 'synthetic-salt-only';}
function current_user_can($x){return $GLOBALS['admin']??false;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;return true;}
function add_option($k,$v,...$a){if(array_key_exists($k,$GLOBALS['options']))return false;return update_option($k,$v);}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function add_action(...$a){}
function home_url($p){return $GLOBALS['origin'].$p;}
class WP_Error{function __construct(public $code,public $message,public $data){}}
class WP_REST_Response{function __construct(public $data){}function header(...$a){}}
function rest_ensure_response($v){return new WP_REST_Response($v);}
function rest_get_server(){return new class {function get_routes(){return $GLOBALS['routes'];}};}
function is_wp_error($v){return $v instanceof WP_Error;}
class Request {
 public $method='GET',$route='/comitement-test/v1/preflight/observer',$body='',$query=[],$lane='observer',$headers=[];
 function get_param($k){return $k==='lane'?$this->lane:null;}
 function get_method(){return $this->method;}
 function get_route(){return $this->route;}
 function get_body(){return $this->body;}
 function get_query_params(){return $this->query;}
 function get_header($k){return $this->headers[$k]??'';}
}
require __DIR__.'/../plugins/cm91-content-bridge/fleet-test-access.php';
require __DIR__.'/../plugins/cm91-content-bridge/contentbridge-v1.php';
function check($v,$label){if(!$v){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}echo 'PASS '.$label."\n";}
function signed($secret,$lane='observer',$ts=null,$origin=null){
 $r=new Request();$r->lane=$lane;$r->route='/comitement-test/v1/preflight/'.$lane;
 $ts=(string)($ts??time());$nonce=bin2hex(random_bytes(20));
 $r->headers=['x-comitement-timestamp'=>$ts,'x-comitement-nonce'=>$nonce,
 'x-comitement-signature'=>hash_hmac('sha256',"GET\n".$r->route."\n".($origin??$GLOBALS['origin'])."\n".$ts."\n".$nonce."\n",$secret)];
 return $r;
}
$prod='synthetic-production-secret';
$GLOBALS['options']['legacy_prod']=$prod;
$observer=Comitement_Fleet_Test_Access::rotate('observer');
$content=Comitement_Fleet_Test_Access::rotate('contentbridge');
check($observer!==$content&&strlen($observer)>64,'independent random lane keys');
check(!str_contains(json_encode($GLOBALS['options']),$observer)&&!str_contains(json_encode($GLOBALS['options']),$content),'test credentials encrypted at rest');
$r=signed($observer);check(Comitement_Fleet_Test_Access::authorize($r)===true,'valid test signature');
check(is_wp_error(Comitement_Fleet_Test_Access::authorize($r)),'replay denied');
foreach([signed($prod),signed('unknown'),signed($content),signed($observer,'observer',time()-301),signed($observer,'observer',time()+301),signed($observer,'observer',null,'https://foreign.fixture.invalid')] as $denied)
 check(is_wp_error(Comitement_Fleet_Test_Access::authorize($denied)),'foreign wrong expired future or wrong-origin signature denied');
foreach(['POST','PATCH','PUT','DELETE','HEAD'] as $method){$bad=signed($observer);$bad->method=$method;check(is_wp_error(Comitement_Fleet_Test_Access::authorize($bad)),'non-GET method denied');}
foreach(['/contentbridge/v1/posts','/contentbridge/v1/media','/contentbridge/v1/operator','/comitement-observer/v1/core-update','/comitement-test/v1/preflight/observer/'] as $route){$bad=signed($observer);$bad->route=$route;check(is_wp_error(Comitement_Fleet_Test_Access::authorize($bad)),'other route denied');}
$bad=signed($observer);$bad->query=['dry_run'=>true];check(is_wp_error(Comitement_Fleet_Test_Access::authorize($bad)),'dry_run cannot widen scope');
$bad=signed($observer);$bad->body='{}';check(is_wp_error(Comitement_Fleet_Test_Access::authorize($bad)),'body manipulation denied');
$response=Comitement_Fleet_Test_Access::preflight(signed($observer))->data;
check($response['write_available']===false&&$response['production_data_read']===false&&$response['availability']==='AVAILABLE','metadata-only response');
check(!str_contains(json_encode($response),$observer)&&!str_contains(json_encode($response),$prod),'no credential in response');
unset($GLOBALS['routes']['/contentbridge/v1/capabilities']);
check(Comitement_Fleet_Test_Access::preflight(signed($content,'contentbridge'))->data['availability']==='UNSUPPORTED','legacy absence is honest');
$new=Comitement_Fleet_Test_Access::rotate('observer');
check(is_wp_error(Comitement_Fleet_Test_Access::authorize(signed($observer)))&&Comitement_Fleet_Test_Access::authorize(signed($new))===true,'rotation invalidates old lane key');
check(Comitement_Fleet_Test_Access::authorize(signed($content,'contentbridge'))===true&&$GLOBALS['options']['legacy_prod']===$prod,'rotation preserves other lane and PROD');
Comitement_Fleet_Test_Access::revoke('observer');
check(is_wp_error(Comitement_Fleet_Test_Access::authorize(signed($new))),'revoked key denied');
// Real existing ContentBridge authentication remains independent.
Comitement_ContentBridge_V1::init(fn()=>$prod);
$prodRequest=new Request();$prodRequest->route='/contentbridge/v1/posts';$prodRequest->method='POST';$prodRequest->body='{"idempotency_key":"fixture-only"}';
$ts=(string)time();$nonce=bin2hex(random_bytes(20));
$prodRequest->headers=['x-comitement-timestamp'=>$ts,'x-comitement-nonce'=>$nonce,
 'x-comitement-signature'=>hash_hmac('sha256',"POST\n".$prodRequest->route."\n".$ts."\n".$nonce."\n".$prodRequest->body,$prod)];
check(Comitement_ContentBridge_V1::auth($prodRequest)===true,'existing PROD signature still accepted');
foreach([$observer,$content,$new] as $key){$prodRequest->headers['x-comitement-nonce']=bin2hex(random_bytes(20));$n=$prodRequest->headers['x-comitement-nonce'];$prodRequest->headers['x-comitement-signature']=hash_hmac('sha256',"POST\n".$prodRequest->route."\n".$ts."\n".$n."\n".$prodRequest->body,$key);check(is_wp_error(Comitement_ContentBridge_V1::auth($prodRequest)),'test key cannot authenticate a production write');}
check($GLOBALS['options']['legacy_prod']===$prod,'no production credential mutation');
echo "FLEET_TEST_ACCESS_UNIT_OK\n";

function is_admin(){return true;}
function get_current_user_id(){return 7;}
function get_transient($k){return get_option('transient_'.$k);}
function set_transient($k,$v,...$a){return update_option('transient_'.$k,$v);}
function delete_transient($k){return delete_option('transient_'.$k);}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}
function admin_url($p){return 'https://site.fixture.invalid/wp-admin/'.$p;}
function wp_nonce_field($a){echo '<input name="_wpnonce" value="synthetic">';}
$_GET['page']='comitement-observer';
ob_start();Comitement_Fleet_Test_Access::settings();$html=ob_get_clean();
check($html==='','non-admin cannot view test key settings');
$GLOBALS['admin']=true;$visible=Comitement_Fleet_Test_Access::rotate('observer');
ob_start();Comitement_Fleet_Test_Access::settings();$html=ob_get_clean();
check(str_contains($html,'Fleet-Testzugang')&&!str_contains($html,$visible),'existing admin page extension never reveals stored keys by default');
set_transient('comitement_test_key_flash_7',['lane'=>'observer','fingerprint'=>hash('sha256',$visible)],60);
ob_start();Comitement_Fleet_Test_Access::settings();$html=ob_get_clean();
check(str_contains($html,$visible),'new key shown once only to its authenticated administrator');
ob_start();Comitement_Fleet_Test_Access::settings();$html=ob_get_clean();
check(!str_contains($html,$visible),'one-time key display is consumed');
$_GET['page']='unrelated';
ob_start();Comitement_Fleet_Test_Access::settings();$html=ob_get_clean();
check($html==='','unrelated admin pages unchanged');
