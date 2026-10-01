<?php
/** Shared ContentBridge API v1. Vendored byte-for-byte by scripts/vendor_contentbridge.py. */
if (!defined('ABSPATH')) exit;
if (class_exists('Comitement_ContentBridge_V1', false)) return;
final class Comitement_ContentBridge_V1 {
 const VERSION='1.0.0';
 const NS='contentbridge/v1';
 const MAX_MEDIA=5242880;
 const MAX_BODY=7340032;
 private static $secret;
 private static $legacy=[];
 public static function init(callable $secret,array $legacy=[]):void {
  self::$secret=$secret;self::$legacy=$legacy;
  add_action('rest_api_init',[__CLASS__,'routes']);
 }
 public static function routes():void {
  foreach([
   '/capabilities'=>['GET','capabilities'], '/posts'=>['POST','create'],
   '/posts/(?P<id>[1-9][0-9]*)'=>[['GET','PATCH'],'post'], '/media'=>['POST','upload'],
   '/test-fixtures/(?P<id>[1-9][0-9]*)'=>['DELETE','cleanup'],
  ] as $path=>$row) register_rest_route(self::NS,$path,['methods'=>$row[0],'callback'=>[__CLASS__,$row[1]],'permission_callback'=>[__CLASS__,'auth']]);
 }
 public static function error(string $code,string $message,int $status=400){return new WP_Error('contentbridge_'.$code,$message,['status'=>$status]);}
 public static function auth($r) {
  if(strlen($r->get_body())>self::MAX_BODY)return self::error('payload_too_large','Request exceeds 7 MiB.',413);
  $secret=is_callable(self::$secret)?(string)call_user_func(self::$secret):'';
  if($secret==='')return self::error('not_configured','Content credential is not configured.',503);
  $ts=(string)$r->get_header('x-comitement-timestamp');$nonce=(string)$r->get_header('x-comitement-nonce');
  if(!ctype_digit($ts)||abs(time()-(int)$ts)>300||!preg_match('/^[a-zA-Z0-9_-]{16,128}$/D',$nonce))return self::error('unauthorized','Invalid signed request.',401);
  $data=$r->get_method()."\n".$r->get_route()."\n".$ts."\n".$nonce."\n".$r->get_body();
  if(!hash_equals(hash_hmac('sha256',$data,$secret),(string)$r->get_header('x-comitement-signature')))return self::error('unauthorized','Invalid signed request.',401);
  // add_option is atomic across concurrent workers; transient get/set alone is not.
  $key='cbv1_nonce_'.hash('sha256',$nonce);
  if(!add_option($key,time()+600,'','no'))return self::error('replay','Signed nonce already used.',409);
  self::gc('cbv1_nonce_');return true;
 }
 private static function gc(string $prefix):void {
  global $wpdb;
  if(!isset($wpdb))return;
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",$wpdb->esc_like($prefix).'%',time()));
 }
 public static function capabilities($r=null):array {
  $taxonomies=[];foreach(['categories'=>'category','tags'=>'post_tag'] as $key=>$tax){$rows=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>100]);$taxonomies[$key]=is_wp_error($rows)?[]:array_map(fn($t)=>['id'=>(int)$t->term_id,'name'=>$t->name],$rows);}
  return ['api_version'=>1,'implementation_version'=>self::VERSION,'capabilities'=>[
   'content.create','content.get','content.update','content.publish','content.schedule','media.upload','media.featured','media.inline','seo.metadata','taxonomy.categories','taxonomy.tags','placeholder.images'
  ],'limits'=>['media_bytes'=>self::MAX_MEDIA,'request_bytes'=>self::MAX_BODY,'inline_images'=>30],
  'media'=>['inputs'=>['base64','attachment_id'],'mime_types'=>['image/jpeg','image/png','image/webp','image/gif'],'url_import'=>false],
  'placeholder'=>'{{image:filename}}','default_status'=>'draft','taxonomies'=>$taxonomies];
 }
 private static function payload($r) {
  $d=$r->get_json_params();if(!is_array($d)||array_is_list($d))return self::error('malformed_payload','A JSON object is required.');
  return $d;
 }
 // Persistent bounded-result ledger. Unfinished mutations remain blocked for operator reconciliation.
 private static function once($r,callable $fn) {
  $d=self::payload($r);if(is_wp_error($d))return $d;
  $k=$d['idempotency_key']??null;
  if(!is_string($k)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$k))return self::error('idempotency_required','idempotency_key must contain 8–128 safe characters.');
  $key='cbv1_write_'.hash('sha256',$r->get_method().' '.$r->get_route().' '.$k);$hash=hash('sha256',$r->get_body());
  if(!add_option($key,['hash'=>$hash,'state'=>'pending','created_at'=>time()],'','no')){
   $row=get_option($key,[]);
   if(($row['hash']??'')!==$hash)return self::error('idempotency_conflict','Key was used for a different payload.',409);
   if(($row['state']??'')!=='complete')return self::error('in_progress','Mutation is pending; reconcile before retrying.',409);
   if(isset($row['error']))return self::error($row['error']['code'],$row['error']['message'],$row['error']['status']);
   return $row['result'];
  }
  $result=$fn($d);
  $row=['hash'=>$hash,'state'=>'complete','created_at'=>time()];
  if(is_wp_error($result)){$ed=$result->get_error_data();$row['error']=['code'=>str_replace('contentbridge_','',$result->get_error_code()),'message'=>$result->get_error_message(),'status'=>(int)($ed['status']??500)];}else $row['result']=$result;
  update_option($key,$row,false);return $result;
 }
 public static function create($r){return self::once($r,fn($d)=>self::write(0,$d));}
 public static function post($r){$id=(int)$r->get_param('id');return $r->get_method()==='GET'?self::read($id):self::once($r,fn($d)=>self::write($id,$d));}
 private static function existing(int $id) {
  $p=get_post($id);return $p&&$p->post_type==='post'&&!in_array($p->post_status,['trash','auto-draft'],true)?$p:self::error('not_found','Post not found.',404);
 }
 public static function read(int $id) {
  $p=self::existing($id);if(is_wp_error($p))return $p;
  $seo=[];foreach(self::seo_fields() as $field=>$meta)$seo[$field]=(string)get_post_meta($id,$meta,true);
  $terms=[];foreach(['categories'=>'category','tags'=>'post_tag'] as $field=>$tax){$v=wp_get_object_terms($id,$tax,['fields'=>'ids']);$terms[$field]=is_wp_error($v)?[]:$v;}
  $featured=(int)get_post_thumbnail_id($id);
  return array_merge(['ok'=>true,'api_version'=>1,'post_id'=>$id,'url'=>get_permalink($id),'preview_url'=>get_preview_post_link($p),'status'=>$p->post_status,
   'title'=>$p->post_title,'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'slug'=>$p->post_name,'author'=>(int)$p->post_author,
   'publish_at'=>$p->post_status==='future'?get_post_time('c',true,$p):null,'seo'=>$seo,
   'featured_image'=>$featured?self::media_info($featured):null,'inline_images'=>(array)get_post_meta($id,'_contentbridge_v1_inline',true),
   'featured_image_pending'=>(bool)get_post_meta($id,'_contentbridge_v1_featured_pending',true),'media_path'=>(string)get_post_meta($id,'_contentbridge_v1_media_path',true),'placeholders_remaining'=>substr_count($p->post_content,'{{image:'),
  ],$terms);
 }
 private static function seo_fields():array {return ['seo_title'=>'_yoast_wpseo_title','meta_description'=>'_yoast_wpseo_metadesc','canonical'=>'_yoast_wpseo_canonical','focus_keyword'=>'_yoast_wpseo_focuskw'];}
 private static function terms($values,string $tax) {
  if(!is_array($values)||!array_is_list($values)||count($values)>50)return self::error('invalid_taxonomy','Taxonomy must be a list of up to 50 names or IDs.');
  $ids=[];foreach($values as $value){
   if(is_int($value)&&$value>0){$t=get_term($value,$tax);if(!$t||is_wp_error($t))return self::error('invalid_taxonomy','Unknown taxonomy ID.');$ids[]=$value;}
   elseif(is_string($value)&&trim($value)!==''&&strlen($value)<=200){$name=sanitize_text_field($value);$t=term_exists($name,$tax);if(!$t)$t=wp_insert_term($name,$tax);if(is_wp_error($t))return self::error('taxonomy_failed','Could not resolve taxonomy.',422);$ids[]=(int)(is_array($t)?$t['term_id']:$t);}
   else return self::error('invalid_taxonomy','Invalid taxonomy name or ID.');
  }return array_values(array_unique($ids));
 }
 private static function image($image) {
  if(!is_array($image)||array_is_list($image))return self::error('invalid_media','Image must be an object.');
  foreach(array_keys($image) as $key)if(!in_array($key,['attachment_id','file','alt_text','caption','title','source','credit'],true))return self::error('invalid_media','Unknown image field.');
  foreach(['file','alt_text','caption','title','source','credit'] as $k)if(isset($image[$k])&&(!is_string($image[$k])||strlen($image[$k])>2000))return self::error('invalid_media','Invalid image metadata.');
  if(isset($image['file'])&&(!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/iD',$image['file'])||str_contains($image['file'],'..')))return self::error('invalid_media','A safe image filename is required.');
  if(isset($image['attachment_id'])&&(!is_int($image['attachment_id'])||$image['attachment_id']<1))return self::error('invalid_media','attachment_id must be a positive integer.');
  return $image;
 }
 private static function attachment(array $image):bool {return !empty($image['attachment_id'])&&get_post_type($image['attachment_id'])==='attachment'&&wp_attachment_is_image($image['attachment_id'])&&(bool)wp_get_attachment_url($image['attachment_id']);}
 private static function metadata(int $aid,array $image):void {
  if(array_key_exists('alt_text',$image))update_post_meta($aid,'_wp_attachment_image_alt',sanitize_text_field($image['alt_text']));
  $p=['ID'=>$aid];foreach(['title'=>'post_title','caption'=>'post_excerpt'] as $k=>$field)if(isset($image[$k]))$p[$field]=sanitize_text_field($image[$k]);
  if(count($p)>1)wp_update_post(wp_slash($p));
  foreach(['source','credit'] as $k)if(isset($image[$k]))update_post_meta($aid,'_contentbridge_'.$k,sanitize_text_field($image[$k]));
 }
 private static function block(int $aid,array $image):string {
  $url=wp_get_attachment_url($aid);$alt=$image['alt_text']??get_post_meta($aid,'_wp_attachment_image_alt',true);$caption=$image['caption']??get_post_field('post_excerpt',$aid);
  return '<!-- wp:image {"id":'.$aid.',"sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full"><img src="'.esc_url($url).'" alt="'.esc_attr($alt).'" class="wp-image-'.$aid.'"/>'.($caption!==''?'<figcaption>'.esc_html($caption).'</figcaption>':'').'</figure><!-- /wp:image -->';
 }
 private static function write(int $id,array $d) {
  $allowed=['idempotency_key','title','content','excerpt','slug','status','publish_at','author','categories','tags','seo','featured_image','inline_images','placeholder_fallback','test_fixture'];
  foreach(array_keys($d) as $k)if(!in_array($k,$allowed,true))return self::error('malformed_payload','Unknown content field: '.sanitize_key($k));
  foreach(['title','content','excerpt','slug','status','publish_at'] as $k)if(isset($d[$k])&&!is_string($d[$k]))return self::error('malformed_payload',$k.' must be a string.');
  if(isset($d['placeholder_fallback'])&&!is_bool($d['placeholder_fallback']))return self::error('malformed_payload','placeholder_fallback must be boolean.');
  $fixture=self::fixture($d);if(is_wp_error($fixture))return $fixture;
  $old=$id?self::existing($id):null;if(is_wp_error($old))return $old;
  if($old){$stored=(string)get_post_meta($id,'_contentbridge_v1_fixture',true);if($fixture!==''&&$fixture!==$stored)return self::error('fixture_mismatch','Fixture marker cannot be changed.');$fixture=$stored;}
  if(!$id&&(empty(trim($d['title']??''))||empty(trim($d['content']??''))))return self::error('malformed_payload','title and content are required.');
  if(isset($d['title'])&&trim($d['title'])==='')return self::error('malformed_payload','title cannot be empty.');
  $status=$d['status']??($old?$old->post_status:'draft');
  if(!in_array($status,['draft','publish','future'],true))return self::error('invalid_status','Use draft, publish or future.');
  if($fixture!==''&&$status!=='draft')return self::error('fixture_protected','Acceptance fixtures must stay draft.',409);
  if(!$id&&$status!=='draft')return self::error('draft_required','Create a draft before publishing or scheduling.',409);
  $post=['post_type'=>'post','post_status'=>$status];
  if($id)$post['ID']=$id;
  foreach(['title'=>'post_title','excerpt'=>'post_excerpt','slug'=>'post_name'] as $k=>$field)if(isset($d[$k]))$post[$field]=$k==='slug'?sanitize_title($d[$k]):sanitize_text_field($d[$k]);
  if(isset($d['author'])){if(!is_int($d['author'])||!user_can($d['author'],'edit_posts'))return self::error('invalid_author','Author must be an existing editor ID.');$post['post_author']=$d['author'];}
  if($status==='future'){
   $date=$d['publish_at']??($old?get_post_time('c',true,$old):'');
   if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$date)||($ts=strtotime($date))===false||$ts<=time()+60)return self::error('invalid_schedule','publish_at must be a future ISO-8601 date with timezone.');
   $post['post_date_gmt']=gmdate('Y-m-d H:i:s',$ts);$post['post_date']=get_date_from_gmt($post['post_date_gmt']);
  }elseif(isset($d['publish_at']))return self::error('invalid_schedule','publish_at requires status future.');
  if($status==='publish'&&$old&&$old->post_status==='future'){$post['post_date_gmt']=current_time('mysql',true);$post['post_date']=current_time('mysql');}
  if(isset($post['post_name'])){$collision=get_page_by_path($post['post_name'],OBJECT,'post');if($collision&&((int)$collision->ID)!==$id)return self::error('slug_exists','Slug is already in use.',409);}
  $seo=$d['seo']??[];if(!is_array($seo)||($seo&&array_is_list($seo)))return self::error('invalid_seo','seo must be an object.');
  foreach($seo as $k=>$v)if(!isset(self::seo_fields()[$k])||!is_string($v)||strlen($v)>2000)return self::error('invalid_seo','Unsupported SEO field or invalid value.');
  if(!empty($seo['canonical'])&&(!filter_var($seo['canonical'],FILTER_VALIDATE_URL)||!in_array(wp_parse_url($seo['canonical'],PHP_URL_SCHEME),['http','https'],true)))return self::error('invalid_seo','canonical must be an HTTP(S) URL.');
  $images=$d['inline_images']??null;if($images!==null&&(!is_array($images)||!array_is_list($images)||count($images)>30))return self::error('invalid_media','inline_images must be a list of up to 30 images.');
  $all=$images??[];if(isset($d['featured_image']))$all[]=$d['featured_image'];
  $fallback=!empty($d['placeholder_fallback']);$native=0;$missing=0;
  foreach($all as $image){$valid=self::image($image);if(is_wp_error($valid))return $valid;if(self::attachment($image)){if(get_post_meta($image['attachment_id'],'_contentbridge_v1_fixture',true)&&get_post_meta($image['attachment_id'],'_contentbridge_v1_fixture',true)!==$fixture)return self::error('fixture_mismatch','Acceptance media cannot be assigned to another fixture or real content.');$native++;}elseif($fallback&&!empty($image['file']))$missing++;else return self::error('media_unavailable','Image attachment is unavailable; use a safe filename and placeholder_fallback for a draft.',422);}
  $body=wp_kses_post($d['content']??($old?$old->post_content:''));
  $legacy=[];$inline=[];$blocks=[];
  if($id&&$images!==null){$wanted=[];foreach($images as $image)if(!empty($image['file']))$wanted[$image['file']]=true;foreach((array)get_post_meta($id,'_contentbridge_v1_inline_blocks',true) as $oldBlock){$file=$oldBlock['file']??'';$replacement=$file!==''&&isset($wanted[$file])?'{{image:'.$file.'}}':'';$body=str_replace($oldBlock['html']??'',$replacement,$body);}}
  foreach($all as $i=>$image){
   $isFeatured=isset($d['featured_image'])&&$i===count($all)-1;
   $available=self::attachment($image);
   if(!$isFeatured){$replacement=$available?self::block($image['attachment_id'],$image):'{{image:'.$image['file'].'}}';$placeholder=!empty($image['file'])?'{{image:'.$image['file'].'}}':'';
    if($placeholder!==''&&str_contains($body,$placeholder))$body=str_replace($placeholder,$replacement,$body);else $body.="\n".$replacement;
    $blocks[]=['file'=>$image['file']??'','html'=>$replacement];
    $inline[]=$available?array_merge(self::media_info($image['attachment_id']),$image):$image;
   }
   if(!empty($image['file']))$legacy[]=['file'=>$image['file'],'alt'=>$image['alt_text']??'','title'=>$image['title']??'','caption'=>$image['caption']??'','featured'=>$isFeatured];
  }
  if(in_array($status,['publish','future'],true)&&($missing||str_contains($body,'{{image:')||($id&&!isset($d['featured_image'])&&get_post_meta($id,'_contentbridge_v1_featured_pending',true))))return self::error('media_unresolved','Resolve image placeholders before publishing.',409);
  $taxonomies=[];foreach(['categories'=>'category','tags'=>'post_tag'] as $k=>$tax)if(array_key_exists($k,$d)){$terms=self::terms($d[$k],$tax);if(is_wp_error($terms))return $terms;$taxonomies[$tax]=$terms;}
  $post['post_content']=$body;$result=wp_insert_post(wp_slash($post),true);if(is_wp_error($result))return self::error('write_failed','WordPress rejected the post.',422);$id=(int)$result;
  foreach($taxonomies as $tax=>$terms){$set=wp_set_object_terms($id,$terms,$tax,false);if(is_wp_error($set))return self::error('taxonomy_failed','Post saved but taxonomy assignment failed; inspect post '.$id.'.',422);}
  foreach($seo as $k=>$v)update_post_meta($id,self::seo_fields()[$k],$k==='canonical'?esc_url_raw($v):sanitize_text_field($v));
  foreach($all as $image)if(self::attachment($image))self::metadata($image['attachment_id'],$image);
  if(isset($d['featured_image'])&&self::attachment($d['featured_image'])){set_post_thumbnail($id,$d['featured_image']['attachment_id']);if((int)get_post_thumbnail_id($id)!==$d['featured_image']['attachment_id'])return self::error('featured_failed','Post saved but featured image was not assigned.',422);}
  if($images!==null){update_post_meta($id,'_contentbridge_v1_inline',$inline);update_post_meta($id,'_contentbridge_v1_inline_blocks',$blocks);}
  if($all){$path=$missing?($native?'mixed':'placeholder'):'native';update_post_meta($id,'_contentbridge_v1_media_path',$path);}
  if($legacy){$previous=(array)get_post_meta($id,'_contentbridge_v1_images',true);$indexed=[];foreach(array_merge($previous,$legacy) as $im)if(!empty($im['file']))$indexed[$im['file']]=$im;$legacy=array_values($indexed);update_post_meta($id,'_contentbridge_v1_images',$legacy);foreach((self::$legacy['images']??[]) as $meta)update_post_meta($id,$meta,$legacy);}
  if(isset($d['featured_image']))update_post_meta($id,'_contentbridge_v1_featured_pending',self::attachment($d['featured_image'])?0:1);
  if(!$old){foreach((self::$legacy['source']??[]) as $meta)update_post_meta($id,$meta,'connect_'.sanitize_key($d['idempotency_key']));foreach((self::$legacy['managed']??[]) as $meta)update_post_meta($id,$meta,'1');}
  if($fixture!=='')update_post_meta($id,'_contentbridge_v1_fixture',$fixture);
  update_post_meta($id,'_contentbridge_v1_managed',1);
  return self::read($id);
 }
 private static function media_info(int $id):array {return ['attachment_id'=>$id,'url'=>wp_get_attachment_url($id),'alt_text'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),'title'=>get_the_title($id),'caption'=>(string)get_post_field('post_excerpt',$id)];}
 private static function fixture(array $d){$v=$d['test_fixture']??'';return is_string($v)&&($v===''||preg_match('/^acceptance-[A-Za-z0-9_-]{8,100}$/D',$v))?$v:self::error('invalid_fixture','test_fixture must be a bounded acceptance marker.');}
 public static function cleanup($r){return self::once($r,function($d)use($r){
  if(array_diff(array_keys($d),['idempotency_key','test_fixture']))return self::error('malformed_payload','Cleanup accepts only fixture marker and idempotency key.');
  $marker=self::fixture($d);if(is_wp_error($marker)||$marker==='')return self::error('invalid_fixture','Cleanup requires an acceptance marker.');
  $id=(int)$r->get_param('id');$p=get_post($id);
  if(!$p||!hash_equals($marker,(string)get_post_meta($id,'_contentbridge_v1_fixture',true)))return self::error('fixture_protected','Only matching acceptance fixtures can be deleted.',403);
  if($p->post_type==='post'&&$p->post_status==='draft')$ok=wp_delete_post($id,true);
  elseif($p->post_type==='attachment'&&$p->post_status==='inherit')$ok=wp_delete_attachment($id,true);
  else return self::error('fixture_protected','Public or unmarked objects cannot be deleted.',403);
  if(!$ok)return self::error('cleanup_failed','Fixture cleanup failed.',500);
  return ['ok'=>true,'api_version'=>1,'deleted_id'=>$id];
 });}
 public static function upload($r){return self::once($r,function($d){
  foreach(array_keys($d) as $key)if(!in_array($key,['idempotency_key','file','mime_type','data_base64','alt_text','caption','title','source','credit','test_fixture'],true))return self::error('invalid_media','Unknown upload field.');
  $fixture=self::fixture($d);if(is_wp_error($fixture))return $fixture;
  $meta=$d;unset($meta['idempotency_key'],$meta['mime_type'],$meta['data_base64'],$meta['test_fixture']);$valid=self::image($meta);if(is_wp_error($valid))return $valid;
  if(empty($d['file'])||!is_string($d['data_base64']??null)||!is_string($d['mime_type']??null))return self::error('invalid_media','file, mime_type and data_base64 are required.');
  if(strlen($d['data_base64'])>4*ceil(self::MAX_MEDIA/3))return self::error('media_too_large','Image exceeds 5 MiB.',413);
  $bytes=base64_decode($d['data_base64'],true);if($bytes===false||strlen($bytes)===0||strlen($bytes)>self::MAX_MEDIA)return self::error('invalid_media','Invalid or oversized base64 image.');
  $info=@getimagesizefromstring($bytes);$types=['image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/gif'=>['gif'],'image/webp'=>['webp']];$mime=$info['mime']??'';
  if(!isset($types[$mime])||$mime!==$d['mime_type']||!in_array(strtolower(pathinfo($d['file'],PATHINFO_EXTENSION)),$types[$mime],true))return self::error('mime_mismatch','Image MIME and filename must match actual bytes.');
  if(($info[0]??0)>12000||($info[1]??0)>12000||($info[0]??0)*($info[1]??0)>40000000)return self::error('image_dimensions','Image exceeds dimension limits.');
  require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
  $tmp=wp_tempnam($d['file']);if(!$tmp)return self::error('media_failed','Unable to allocate upload.',503);
  try{if(file_put_contents($tmp,$bytes)!==strlen($bytes))return self::error('media_failed','Unable to write upload.',503);
   $id=media_handle_sideload(['name'=>$d['file'],'tmp_name'=>$tmp],0);if(is_wp_error($id))return self::error('media_failed','WordPress rejected image upload.',422);
   if($fixture!=='')update_post_meta((int)$id,'_contentbridge_v1_fixture',$fixture);
   self::metadata((int)$id,$meta);return ['ok'=>true,'api_version'=>1,'media_path'=>'native','media'=>self::media_info((int)$id)];
  }finally{if(is_file($tmp))unlink($tmp);}
 });}
}
