<?php
// Deterministic WordPress API double. Real WP acceptance is ops/contentbridge-staging-acceptance.sh.
define('OBJECT','OBJECT');
define('ABSPATH',sys_get_temp_dir().'/cbv1-unit-'.getmypid().'/');
foreach(['file','media','image'] as $f){@mkdir(ABSPATH.'wp-admin/includes',0777,true);file_put_contents(ABSPATH.'wp-admin/includes/'.$f.'.php','<?php');}
class WP_Error {function __construct(private $code,private $message,private $data=[]){ }function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}function get_error_data(){return $this->data;}}
class CB_Request {
 private $body;public $headers=[];
 function __construct(private $method,private $route,private $d,private $id=0){$this->body=is_string($d)?$d:json_encode($d);}
 function get_method(){return $this->method;}function get_route(){return $this->route;}function get_body(){return $this->body;}function get_json_params(){return json_decode($this->body,true);}function get_param($k){return $k==='id'?$this->id:null;}function get_header($k){return $this->headers[$k]??'';}
}
class MediaReferenceDB {
 public $posts='posts',$postmeta='postmeta',$options='options',$usermeta='usermeta',$last_error='';function query($sql){return 0;}private $values=[];
 function esc_like($s){return addcslashes($s,'_%');}function prepare($sql,...$values){$this->values=$values;return $sql;}
 function get_var($sql){global $posts,$thumbs,$options,$meta,$userMediaReferences;
  if(str_contains($sql,'FROM usermeta'))return (string)count(array_filter($userMediaReferences??[],fn($id)=>(string)$id===$this->values[1]));
  if(str_contains($sql,'FROM options')){$count=0;foreach($options as$key=>$value){if(preg_match('/^(cbv1_|cbop_|cmod_)/',$key))continue;$value=is_array($value)?serialize($value):(string)$value;foreach($this->values as$pattern)if(str_contains($value,stripslashes(trim($pattern,'%')))){$count++;break;}}return (string)$count;}
  if(str_contains($sql,'LEFT JOIN')){$count=0;$exclude=$this->values[0];$stem=stripslashes(trim($this->values[1],'%'));foreach($posts as$p){if($p->ID===$exclude)continue;if(str_contains($p->post_content,$stem)){$count++;continue;}foreach($meta[$p->ID]??[]as$value){$value=is_array($value)?serialize($value):(string)$value;if($value===$this->values[2]||str_contains($value,stripslashes(trim($this->values[3],'%')))){$count++;break;}}}return (string)$count;}
  $exclude=$this->values[0];$aid=(int)$this->values[count($this->values)-3];$count=0;foreach($posts as$p){if($p->ID===$exclude||$p->post_type==='revision')continue;if(($thumbs[$p->ID]??0)===$aid||str_contains($p->post_content,'wp-image-'.$aid)||str_contains($p->post_content,'https://unit.invalid/media/'.$aid.'.png'))$count++;}return (string)$count;}
}
$wpdb=new MediaReferenceDB();
$nextPostId=1;$options=[];$posts=[];$meta=[];$terms=[];$object_terms=[];$thumbs=[];$upload_failure=false;
function is_wp_error($v){return $v instanceof WP_Error;}function add_action(...$a){}function register_rest_route(...$a){}
function add_option($k,$v,...$a){global $options;if(array_key_exists($k,$options))return false;$options[$k]=$v;return true;}
function delete_option($k){global $options;unset($options[$k]);return true;}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}function update_option($k,$v,...$a){global $options;$options[$k]=$v;return true;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$s));}function sanitize_text_field($s){return strip_tags($s);}function sanitize_title($s){return strtolower(str_replace(' ','-',$s));}function wp_kses_post($s){return str_replace('<script>bad</script>','',$s);}function wp_slash($s){return $s;}
function esc_url($v){return htmlspecialchars($v,ENT_QUOTES);}function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES);}function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}function esc_url_raw($v){return $v;}function wp_parse_url(...$a){return parse_url(...$a);}function wp_json_encode($v,...$a){return json_encode($v,...$a);}
function get_post($id){global $posts;return $posts[$id]??null;}function get_post_type($id){return get_post($id)->post_type??null;}
function wp_insert_post($p,$err=false){global $posts,$nextPostId;$id=$p['ID']??$nextPostId++;$old=$posts[$id]??(object)['post_status'=>'draft','post_type'=>'post','post_title'=>'','post_name'=>'','post_content'=>'','post_excerpt'=>'','post_author'=>1,'post_date_gmt'=>''];$posts[$id]=(object)array_merge((array)$old,$p,['ID'=>$id]);return $id;}function wp_update_post($p,...$a){return wp_insert_post($p);}
function get_post_meta($id,$k,$single=true){global $meta;return $meta[$id][$k]??'';}function update_post_meta($id,$k,$v){global $meta;$meta[$id][$k]=$v;return true;}
function wp_get_attachment_metadata($id){return [];}function delete_post_meta($id,$key){global$meta;unset($meta[$id][$key]);}
function get_post_thumbnail_id($id){global $thumbs;return $thumbs[$id]??0;}function set_post_thumbnail($id,$aid){global $thumbs;$thumbs[$id]=$aid;return true;}
function attachment_url_to_postid($url){preg_match('~/media/(\d+)~',$url,$m);return (int)($m[1]??0);}
function wp_get_attachment_url($id){return get_post_type($id)==='attachment'?'https://unit.invalid/media/'.$id.'.png':'';}function wp_attachment_is_image($id){return get_post_type($id)==='attachment';}function get_post_field($k,$id){return get_post($id)->$k??'';}function get_the_title($id){return get_post($id)->post_title??'';}
function user_can($id,$cap){return $id===1;}function get_permalink($id){return 'https://unit.invalid/?p='.$id;}function get_preview_post_link($p){return 'https://unit.invalid/?preview='.$p->ID;}function get_post_time($fmt,$utc,$p){return gmdate($fmt,strtotime($p->post_date_gmt?:'now'));}function get_date_from_gmt($v){return $v;}function current_time(...$a){return gmdate('Y-m-d H:i:s');}
function get_page_by_path($slug,$format,$type){global $posts;foreach($posts as $p)if($p->post_type===$type&&$p->post_name===$slug)return $p;return null;}
function get_terms($a){return [];}
function wp_delete_post($id,$force){global $posts;unset($posts[$id]);return true;}function wp_delete_attachment($id,$force){return wp_delete_post($id,$force);}
function term_exists($name,$tax){global $terms;foreach($terms[$tax]??[] as $id=>$v)if($v===$name)return ['term_id'=>$id];return null;}function wp_insert_term($n,$t){global $terms;$id=count($terms[$t]??[])+1;$terms[$t][$id]=$n;return ['term_id'=>$id];}function get_term($id,$t){global $terms;return isset($terms[$t][$id])?(object)['term_id'=>$id]:null;}function wp_set_object_terms($id,$values,$tax,$append){global $object_terms;$object_terms[$id][$tax]=$values;return $values;}function wp_get_object_terms($id,$tax,$a){global $object_terms;return $object_terms[$id][$tax]??[];}
function wp_tempnam($n){return tempnam(sys_get_temp_dir(),'cbtest');}function media_handle_sideload($f,$parent){global $upload_failure;if($upload_failure)return new WP_Error('failure','fixture');return wp_insert_post(['post_type'=>'attachment','post_status'=>'inherit','post_title'=>$f['name']]);}
require __DIR__.'/../plugins/cm91-content-bridge/contentbridge-v1.php';
Comitement_ContentBridge_V1::init(fn()=>'unit-secret',['images'=>['_legacy_images'],'source'=>['_legacy_source'],'managed'=>['_legacy_managed']]);
function check($v,$m){if(!$v){fwrite(STDERR,'FAIL '.$m."\n");exit(1);}echo 'OK '.$m."\n";}
function signed($method,$route,$data,$id=0){$r=new CB_Request($method,$route,$data,$id);$ts=(string)time();$n=bin2hex(random_bytes(12));$r->headers=['x-comitement-timestamp'=>$ts,'x-comitement-nonce'=>$n,'x-comitement-signature'=>hash_hmac('sha256',$method."\n".$route."\n".$ts."\n".$n."\n".$r->get_body(),'unit-secret')];return $r;}
function create($data){return Comitement_ContentBridge_V1::create(signed('POST','/contentbridge/v1/posts',$data));}
function update($id,$data){return Comitement_ContentBridge_V1::post(signed('PATCH','/contentbridge/v1/posts/'.$id,$data,$id));}
try {
$r=signed('POST','/contentbridge/v1/posts',['x'=>1]);check(Comitement_ContentBridge_V1::auth($r)===true,'valid content HMAC');check(is_wp_error(Comitement_ContentBridge_V1::auth($r)),'nonce replay denied');
$r=signed('GET','/contentbridge/v1/capabilities',[]);$r->headers['x-comitement-signature']='bad';check(is_wp_error(Comitement_ContentBridge_V1::auth($r)),'invalid auth denied');
$r=signed('GET','/contentbridge/v1/capabilities',[]);$r->headers['x-comitement-timestamp']='1';check(is_wp_error(Comitement_ContentBridge_V1::auth($r)),'expired auth denied');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$media=['idempotency_key'=>'upload-01','file'=>'test.png','mime_type'=>'image/png','data_base64'=>base64_encode($png),'alt_text'=>'A pixel','caption'=>'Pixel caption','title'=>'Test image'];
$m=Comitement_ContentBridge_V1::upload(signed('POST','/contentbridge/v1/media',$media));check(!is_wp_error($m)&&$m['media']['alt_text']==='A pixel','native media upload alt and caption');$aid=$m['media']['attachment_id'];
$im=['attachment_id'=>$aid,'file'=>'test.png','alt_text'=>'Inline alt','caption'=>'Inline caption','title'=>'Inline title'];
$d=['idempotency_key'=>'create-01','title'=>'Draft','content'=>'<p>Hello</p>{{image:test.png}}','slug'=>'draft-test','categories'=>['Tests'],'tags'=>['Bridge','API'],'seo'=>['seo_title'=>'SEO title','meta_description'=>'SEO description','canonical'=>'https://unit.invalid/draft','focus_keyword'=>'bridge'],'featured_image'=>$im,'inline_images'=>[$im]];
$p=create($d);check(!is_wp_error($p),'create draft');$id=$p['post_id'];check($p['status']==='draft'&&$p['slug']==='draft-test'&&count($p['categories'])===1&&count($p['tags'])===2,'draft taxonomy and slug');check($p['seo']['seo_title']==='SEO title'&&$p['seo']['meta_description']==='SEO description','SEO roundtrip');check($p['featured_image']['attachment_id']===$aid&&str_contains($p['content'],'wp-image-'.$aid)&&str_contains($p['content'],'alt="Inline alt"'),'featured and inline images');check($p['placeholders_remaining']===0&&$p['media_path']==='native','native assignment replaces placeholders');
$repeat=create($d);check($repeat['post_id']===$id&&count($posts)===2,'idempotency retry does not duplicate');$bad=$d;$bad['title']='Different';check(is_wp_error(create($bad)),'idempotency conflict');
$u=update($id,['idempotency_key'=>'update-01','title'=>'Changed','categories'=>[],'tags'=>['Updated'],'seo'=>['meta_description'=>'Changed description']]);check($u['title']==='Changed'&&$u['status']==='draft'&&$u['categories']===[]&&$u['seo']['seo_title']==='SEO title','update preserves omitted values');
$f=create(['idempotency_key'=>'fallback-01','title'=>'Fallback','content'=>'<p>Text</p>{{image:missing.png}}','placeholder_fallback'=>true,'inline_images'=>[['file'=>'missing.png','alt_text'=>'Fallback alt']],'featured_image'=>['file'=>'featured.png','alt_text'=>'Featured alt']]);check(!is_wp_error($f)&&$f['media_path']==='placeholder'&&$f['placeholders_remaining']===1&&$f['featured_image_pending'],'placeholder fallback');check(get_post_meta($f['post_id'],'_legacy_images',true)[0]['file']==='missing.png'&&get_post_meta($f['post_id'],'_legacy_source',true)!=='','legacy ZIP metadata/source preserved');
$emptyA=create(['idempotency_key'=>'empty-slug-a','title'=>'Empty slug A','content'=>'A']);$emptyB=create(['idempotency_key'=>'empty-slug-b','title'=>'Empty slug B','content'=>'B']);check(!is_wp_error($emptyA)&&!is_wp_error($emptyB),'multiple drafts may start without explicit slug');$emptyRestore=update($emptyB['post_id'],['idempotency_key'=>'empty-slug-restore','slug'=>'']);check(!is_wp_error($emptyRestore)&&$emptyRestore['slug']==='','empty draft slug can be restored without false collision');
check(is_wp_error(update($f['post_id'],['idempotency_key'=>'publish-fallback','status'=>'publish'])),'publishing unresolved placeholders blocked');
check(is_wp_error(create(['idempotency_key'=>'bad-media','title'=>'Bad','content'=>'Hi','inline_images'=>[['attachment_id'=>999,'file'=>'bad.png']]])),'media failure fails without fallback');
check(is_wp_error(create(['idempotency_key'=>'malformed','title'=>[],'content'=>'Hi'])),'malformed payload denied');
check(is_wp_error(create(['idempotency_key'=>'author-bad','title'=>'Hi','content'=>'Hi','author'=>999])),'unknown author denied');
check(is_wp_error(create(['idempotency_key'=>'public-create','title'=>'Hi','content'=>'Hi','status'=>'publish'])),'create is draft-only');
check(is_wp_error(update(999,['idempotency_key'=>'unknown-post','title'=>'Hi'])),'unknown post denied');
$scheduled=update($id,['idempotency_key'=>'schedule-01','status'=>'future','publish_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+86400)]);check(!is_wp_error($scheduled)&&$scheduled['status']==='future','schedule future');
$published=update($id,['idempotency_key'=>'publish-01','status'=>'publish']);check(!is_wp_error($published)&&$published['status']==='publish','publish explicit post');
$bad=$media;$bad['idempotency_key']='mime-bad-01';$bad['mime_type']='text/html';check(is_wp_error(Comitement_ContentBridge_V1::upload(signed('POST','/contentbridge/v1/media',$bad))),'MIME mismatch denied');
$bad=$media;$bad['idempotency_key']='path-bad-01';$bad['file']='../image.png';check(is_wp_error(Comitement_ContentBridge_V1::upload(signed('POST','/contentbridge/v1/media',$bad))),'arbitrary paths denied');
$bad=$media;$bad['idempotency_key']='url-bad-01';$bad['url']='http://127.0.0.1/';check(is_wp_error(Comitement_ContentBridge_V1::upload(signed('POST','/contentbridge/v1/media',$bad))),'URL import is unsupported');
$upload_failure=true;$media['idempotency_key']='failure-01';check(is_wp_error(Comitement_ContentBridge_V1::upload(signed('POST','/contentbridge/v1/media',$media))),'WordPress media failure returned');
check(in_array('placeholder.images',Comitement_ContentBridge_V1::capabilities()['capabilities'],true),'capabilities include fallback');
$fixture=create(['idempotency_key'=>'fixture-01','test_fixture'=>'acceptance-unit-test','title'=>'Test fixture','content'=>'Fixture']);check(!is_wp_error($fixture),'fixture create');
check(is_wp_error(update($fixture['post_id'],['idempotency_key'=>'fixture-publish','status'=>'publish'])),'fixture can never publish');
check(is_wp_error(Comitement_ContentBridge_V1::cleanup(signed('DELETE','/contentbridge/v1/test-fixtures/'.$id,['idempotency_key'=>'cleanup-real','test_fixture'=>'acceptance-unit-test'],$id))),'cleanup cannot delete real post');
$fid=$fixture['post_id'];$cl=Comitement_ContentBridge_V1::cleanup(signed('DELETE','/contentbridge/v1/test-fixtures/'.$fid,['idempotency_key'=>'cleanup-fixture','test_fixture'=>'acceptance-unit-test'],$fid));check(!is_wp_error($cl)&&!get_post($fid),'fixture cleanup');
echo "CONTENTBRIDGE_V1_UNIT_OK\n";
} finally {foreach(['file','media','image'] as $f)unlink(ABSPATH.'wp-admin/includes/'.$f.'.php');rmdir(ABSPATH.'wp-admin/includes');rmdir(ABSPATH.'wp-admin');rmdir(ABSPATH);}

