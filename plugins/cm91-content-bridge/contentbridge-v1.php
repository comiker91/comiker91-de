<?php
/** Shared ContentBridge API v1. Vendored byte-for-byte by scripts/vendor_contentbridge.py. */
if (!defined('ABSPATH')) exit;
if (class_exists('Comitement_ContentBridge_V1', false)) return;
final class Comitement_ContentBridge_V1 {
 const VERSION='1.1.0';
 const NS='contentbridge/v1';
 const MAX_MEDIA=5242880;
 const MAX_BODY=7340032;
 private static $secret;
 private static $legacy=[];
 public static function init(callable $secret,array $legacy=[]):void {
  self::$secret=$secret;self::$legacy=$legacy;
  add_action('rest_api_init',[__CLASS__,'routes']);
  add_action('parse_request',[__CLASS__,'private_preview'],1);
 }
 public static function preview_grant(int $id,string $version):array {
  $token=bin2hex(random_bytes(32));set_transient('cbop_preview_'.hash('sha256',$token),['post_id'=>$id,'version'=>$version],300);
  return ['preview_url'=>add_query_arg(['cbop_preview'=>$token,'p'=>$id,'preview'=>'true'],home_url('/')),'expires_at'=>gmdate('c',time()+300),'version'=>$version,'capture_id'=>hash('sha256',$token)];
 }
 /** A five-minute bearer can render exactly one unchanged post, at the front-end root, by GET only. */
 public static function private_preview($wp):void {
  if(!isset($_GET['cbop_preview']))return;
  $token=$_GET['cbop_preview'];$path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$home=wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/';
  if(($_SERVER['REQUEST_METHOD']??'')!=='GET'||array_diff(array_keys($_GET),['cbop_preview','p','preview'])||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||$path!==$home){status_header(403);exit;}
  $grant=get_transient('cbop_preview_'.hash('sha256',$token));$id=(int)($grant['post_id']??0);
  if(!$id||((string)($_GET['p']??''))!==(string)$id||!hash_equals((string)$grant['version'],Comitement_ContentBridge_Operator::version($id))){status_header(403);exit;}
  $post=get_post($id);$author=(int)$post->post_author;if(!user_can($author,'edit_post',$id)){status_header(403);exit;}
  wp_set_current_user($author);if(function_exists('show_admin_bar'))show_admin_bar(false);$wp->query_vars=['post_type'=>$post->post_type,$post->post_type==='page'?'page_id':'p'=>$id,'preview'=>true];
  if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
 }
 public static function routes():void {
  foreach([
   '/capabilities'=>['GET','capabilities'], '/posts'=>['POST','create'],
   '/posts/(?P<id>[1-9][0-9]*)'=>[['GET','PATCH'],'post'], '/media'=>['POST','upload'],
   '/test-fixtures/(?P<id>[1-9][0-9]*)'=>['DELETE','cleanup'],
   '/operator'=>['POST','operator'],
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
   'operator.v1','content.create','content.get','content.update','content.publish','content.schedule','media.upload','media.featured','media.inline','seo.metadata','taxonomy.categories','taxonomy.tags','placeholder.images'
  ],'limits'=>['media_bytes'=>self::MAX_MEDIA,'request_bytes'=>self::MAX_BODY,'inline_images'=>30],
  'media'=>['inputs'=>['base64','attachment_id'],'mime_types'=>['image/jpeg','image/png','image/webp','image/gif'],'url_import'=>false],
  'placeholder'=>'{{image:filename}}','default_status'=>'draft','default_author'=>self::default_author_info(),'quality_gates'=>['reject_generic_image_metadata'=>true,'publication_requires_resolved_images'=>true],'taxonomies'=>$taxonomies];
 }
 private static function default_author():int {
  $configured=(int)get_option('comitement_contentbridge_default_author',0);
  if($configured>0&&user_can($configured,'edit_posts'))return $configured;
  if(user_can(1,'edit_posts'))return 1;
  $users=get_users(['role__in'=>['administrator','editor','author'],'number'=>1,'orderby'=>'ID','order'=>'ASC','fields'=>'ID']);
  return $users?(int)$users[0]:0;
 }
 private static function default_author_info():array {
  $id=self::default_author();$u=$id&&function_exists('get_userdata')?get_userdata($id):false;
  return ['id'=>$id,'name'=>$u?$u->display_name:''];
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
  $p=get_post($id);return $p&&in_array($p->post_type,['post','page'],true)&&!in_array($p->post_status,['trash','auto-draft'],true)?$p:self::error('not_found','Post not found.',404);
 }
 public static function read(int $id) {
  $p=self::existing($id);if(is_wp_error($p))return $p;
  $seo=[];foreach(self::seo_fields() as $field=>$meta)$seo[$field]=(string)get_post_meta($id,$meta,true);
  $terms=[];foreach(['categories'=>'category','tags'=>'post_tag'] as $field=>$tax){$v=wp_get_object_terms($id,$tax,['fields'=>'ids']);$terms[$field]=is_wp_error($v)?[]:$v;}
  $featured=(int)get_post_thumbnail_id($id);
  return array_merge(['ok'=>true,'api_version'=>1,'post_id'=>$id,'url'=>get_permalink($id),'preview_url'=>get_preview_post_link($p),'status'=>$p->post_status,
   'title'=>$p->post_title,'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'slug'=>$p->post_name,'author'=>(int)$p->post_author,
   'content_type'=>$p->post_type,'version'=>Comitement_ContentBridge_Operator::version($id),'published_at'=>get_post_time('c',true,$p),'publish_at'=>$p->post_status==='future'?get_post_time('c',true,$p):null,'seo'=>$seo,
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
  foreach(array_keys($image) as $key)if(!in_array($key,['attachment_id','file','alt_text','caption','title','description','source','credit'],true))return self::error('invalid_media','Unknown image field.');
  foreach(['file','alt_text','caption','title','description','source','credit'] as $k)if(isset($image[$k])&&(!is_string($image[$k])||strlen($image[$k])>2000))return self::error('invalid_media','Invalid image metadata.');
  if(isset($image['file'])&&(!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$/iD',$image['file'])||str_contains($image['file'],'..')))return self::error('invalid_media','A safe image filename is required.');
  if(isset($image['attachment_id'])&&(!is_int($image['attachment_id'])||$image['attachment_id']<1))return self::error('invalid_media','attachment_id must be a positive integer.');
  $qualityText=strtolower(implode(' ',array_filter([(string)($image['file']??''),(string)($image['alt_text']??''),(string)($image['title']??''),(string)($image['caption']??'')])));
  if($qualityText!==''&&preg_match('/placeholder|beispielbild|artikel[ -]?mit[ -]?bildern|so[ -]?sieht[ -]?der[ -]?artikel|bild[ -]?so[ -]?sieht/i',$qualityText))return self::error('invalid_media','Generic placeholder-style image metadata is not allowed. Use a topic-specific visual and descriptive alt text.',422);
  return $image;
 }
 private static function attachment(array $image):bool {return !empty($image['attachment_id'])&&get_post_type($image['attachment_id'])==='attachment'&&wp_attachment_is_image($image['attachment_id'])&&(bool)wp_get_attachment_url($image['attachment_id']);}
 private static function metadata(int $aid,array $image):void {
  if(array_key_exists('alt_text',$image))update_post_meta($aid,'_wp_attachment_image_alt',sanitize_text_field($image['alt_text']));
  $p=['ID'=>$aid];foreach(['title'=>'post_title','caption'=>'post_excerpt','description'=>'post_content'] as $k=>$field)if(isset($image[$k]))$p[$field]=sanitize_text_field($image[$k]);
  if(count($p)>1)wp_update_post(wp_slash($p));
  foreach(['source','credit'] as $k)if(isset($image[$k]))update_post_meta($aid,'_contentbridge_'.$k,sanitize_text_field($image[$k]));
 }
 private static function block(int $aid,array $image):string {
  $url=wp_get_attachment_url($aid);$alt=$image['alt_text']??get_post_meta($aid,'_wp_attachment_image_alt',true);$caption=$image['caption']??get_post_field('post_excerpt',$aid);
  return '<!-- wp:image {"id":'.$aid.',"sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full"><img src="'.esc_url($url).'" alt="'.esc_attr($alt).'" class="wp-image-'.$aid.'"/>'.($caption!==''?'<figcaption>'.esc_html($caption).'</figcaption>':'').'</figure><!-- /wp:image -->';
 }
 public static function operator($r){return Comitement_ContentBridge_Operator::dispatch($r);}
 public static function operator_write(int $id,array $d){return self::write_unlocked($id,$d);}
 private static function write(int $id,array $d) {
  if(!$id)return self::write_unlocked($id,$d);
  return Comitement_ContentBridge_Operator::locked($id,function()use($id,$d){
   if(isset($d['expected_version'])&&$d['expected_version']!==Comitement_ContentBridge_Operator::version($id))return self::error('stale_version','Content changed; reread before mutation.',409);
   $snapshot=Comitement_ContentBridge_Operator::snapshot($id,['actor'=>0,'reason'=>'Legacy ContentBridge write','correlation_id'=>$d['idempotency_key']]);
   if(is_wp_error($snapshot))return $snapshot;
   return self::write_unlocked($id,$d);
  });
 }
 private static function write_unlocked(int $id,array $d) {
  $allowed=['idempotency_key','title','content','excerpt','slug','status','publish_at','author','categories','tags','seo','featured_image','inline_images','placeholder_fallback','test_fixture','expected_version'];
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
  $post=['post_type'=>$old?$old->post_type:'post','post_status'=>$status];
  if($id)$post['ID']=$id;
  elseif(!isset($d['author'])){$defaultAuthor=self::default_author();if($defaultAuthor>0)$post['post_author']=$defaultAuthor;}
  foreach(['title'=>'post_title','excerpt'=>'post_excerpt','slug'=>'post_name'] as $k=>$field)if(isset($d[$k]))$post[$field]=$k==='slug'?sanitize_title($d[$k]):sanitize_text_field($d[$k]);
  if(isset($d['author'])){if(!is_int($d['author'])||!user_can($d['author'],'edit_posts'))return self::error('invalid_author','Author must be an existing editor ID.');$post['post_author']=$d['author'];}
  if($status==='future'){
   $date=$d['publish_at']??($old?get_post_time('c',true,$old):'');
   if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$date)||($ts=strtotime($date))===false||$ts<=time()+60)return self::error('invalid_schedule','publish_at must be a future ISO-8601 date with timezone.');
   $post['post_date_gmt']=gmdate('Y-m-d H:i:s',$ts);$post['post_date']=get_date_from_gmt($post['post_date_gmt']);
  }elseif(isset($d['publish_at']))return self::error('invalid_schedule','publish_at requires status future.');
  if($status==='publish'&&$old&&$old->post_status==='future'){$post['post_date_gmt']=current_time('mysql',true);$post['post_date']=current_time('mysql');}
  if(isset($post['post_name'])&&$post['post_name']!==''){$collision=get_page_by_path($post['post_name'],OBJECT,$post['post_type']);if($collision&&((int)$collision->ID)!==$id)return self::error('slug_exists','Slug is already in use.',409);}
  $seo=$d['seo']??[];if(!is_array($seo)||($seo&&array_is_list($seo)))return self::error('invalid_seo','seo must be an object.');
  foreach($seo as $k=>$v)if(!isset(self::seo_fields()[$k])||!is_string($v)||strlen($v)>2000)return self::error('invalid_seo','Unsupported SEO field or invalid value.');
  if(!empty($seo['canonical'])&&(!filter_var($seo['canonical'],FILTER_VALIDATE_URL)||!in_array(wp_parse_url($seo['canonical'],PHP_URL_SCHEME),['http','https'],true)))return self::error('invalid_seo','canonical must be an HTTP(S) URL.');
  $images=$d['inline_images']??null;if($images!==null&&(!is_array($images)||!array_is_list($images)||count($images)>30))return self::error('invalid_media','inline_images must be a list of up to 30 images.');
  $all=$images??[];if(isset($d['featured_image']))$all[]=$d['featured_image'];
  $fallback=!empty($d['placeholder_fallback']);$native=0;$missing=0;
  foreach($all as $image){$valid=self::image($image);if(is_wp_error($valid))return $valid;if(self::attachment($image)){if(get_post_meta($image['attachment_id'],'_contentbridge_v1_fixture',true)&&get_post_meta($image['attachment_id'],'_contentbridge_v1_fixture',true)!==$fixture)return self::error('fixture_mismatch','Acceptance media cannot be assigned to another fixture or real content.');$changes=false;foreach(['alt_text'=>'_wp_attachment_image_alt','source'=>'_contentbridge_source','credit'=>'_contentbridge_credit']as$k=>$meta)if(isset($image[$k])&&get_post_meta($image['attachment_id'],$meta,true)!==sanitize_text_field($image[$k]))$changes=true;foreach(['title'=>'post_title','caption'=>'post_excerpt','description'=>'post_content']as$k=>$field)if(isset($image[$k])&&get_post_field($field,$image['attachment_id'])!==sanitize_text_field($image[$k]))$changes=true;
    if($changes&&Comitement_ContentBridge_Operator::other_media_references($image['attachment_id'],$id))return self::error('shared_media','Shared attachment metadata must not be changed through content assignment.',409);$native++;}elseif($fallback&&!empty($image['file']))$missing++;else return self::error('media_unavailable','Image attachment is unavailable; use a safe filename and placeholder_fallback for a draft.',422);}
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
  $post['post_content']=$body;$result=$id?wp_update_post(wp_slash($post),true):wp_insert_post(wp_slash($post),true);if(is_wp_error($result))return self::error('write_failed','WordPress rejected the post.',422);$id=(int)$result;
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
  if(in_array($p->post_type,['post','page'],true)&&$p->post_status==='draft')$ok=wp_delete_post($id,true);
  elseif($p->post_type==='attachment'&&$p->post_status==='inherit')$ok=wp_delete_attachment($id,true);
  else return self::error('fixture_protected','Public or unmarked objects cannot be deleted.',403);
  if(!$ok)return self::error('cleanup_failed','Fixture cleanup failed.',500);
  return ['ok'=>true,'api_version'=>1,'deleted_id'=>$id];
 });}
 public static function upload($r){return self::once($r,function($d){
  foreach(array_keys($d) as $key)if(!in_array($key,['idempotency_key','file','mime_type','data_base64','alt_text','caption','title','description','source','credit','test_fixture'],true))return self::error('invalid_media','Unknown upload field.');
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

/** Canonical bounded operator API; shipped with the existing single-file bridge. */
final class Comitement_ContentBridge_Operator {
 const READ=['search_content','find_authors','find_categories','find_tags','list_content_media','content_quality_check','list_content_snapshots','find_internal_links','get_maintenance_status','get_operator_audit'];
 const WRITE=['resolve_content_taxonomy','replace_content_media','update_media_metadata','create_content_snapshot','restore_content_snapshot','apply_internal_links','run_maintenance','prepare_content_preview','record_content_visual_qa'];
 private static function error($code,$message,$status=400){return Comitement_ContentBridge_V1::error($code,$message,$status);}
 private static function ok(array $d):array{return ['ok'=>true,'api_version'=>1]+$d;}
 public static function version(int $id):string {
  $p=get_post($id);if(!$p)return '';
  $state=[];foreach(['post_type','post_status','post_title','post_content','post_excerpt','post_name','post_author','post_date','post_date_gmt','post_modified_gmt']as$k)$state[$k]=$p->$k??'';
  foreach(['category','post_tag']as$tax){$v=wp_get_object_terms($id,$tax,['fields'=>'ids']);if(is_wp_error($v))return '';sort($v);$state[$tax]=$v;}
  foreach(['_thumbnail_id','_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_canonical','_yoast_wpseo_focuskw','_contentbridge_v1_inline','_contentbridge_v1_inline_blocks','_contentbridge_v1_images','_contentbridge_v1_featured_pending']as$k)$state[$k]=get_post_meta($id,$k,true);
  $state['thumbnail']=(int)get_post_thumbnail_id($id);$media_ids=[];foreach(self::image_tags($p->post_content??'')as$image)$media_ids[]=$image['attachment_id']?:attachment_url_to_postid($image['url']);$media_ids[]=$state['thumbnail'];foreach(array_unique(array_filter($media_ids))as$aid)$state['media_'.$aid]=['alt'=>get_post_meta($aid,'_wp_attachment_image_alt',true),'title'=>get_post_field('post_title',$aid),'caption'=>get_post_field('post_excerpt',$aid),'description'=>get_post_field('post_content',$aid)];
  return hash('sha256',wp_json_encode($state));
 }
 public static function locked(int $id,callable $fn){
  $key='cbop_lock_'.$id;if(!add_option($key,['at'=>gmdate('c')],'','no'))return self::error('locked','Concurrent or interrupted mutation requires reconciliation.',409);
  $completed=false;try{$r=$fn();$completed=true;return $r;}finally{if($completed)delete_option($key);}
 }
 public static function snapshot(int $id,array $ctx){
  $p=get_post($id);if(!$p||!in_array($p->post_type,['post','page'],true))return self::error('not_found','Content not found.',404);
  $read=Comitement_ContentBridge_V1::read($id);if(is_wp_error($read))return $read;
  $meta=[];foreach(['_contentbridge_v1_inline','_contentbridge_v1_inline_blocks','_contentbridge_v1_images','_contentbridge_v1_featured_pending','_contentbridge_v1_media_path']as$k)$meta[$k]=get_post_meta($id,$k,true);
  $media_data=[];$ids=[];foreach(self::image_tags($p->post_content)as$image)$ids[]=$image['attachment_id']?:attachment_url_to_postid($image['url']);$ids[]=(int)get_post_thumbnail_id($id);foreach(array_unique(array_filter($ids))as$aid)$media_data[$aid]=['alt_text'=>get_post_meta($aid,'_wp_attachment_image_alt',true),'post_title'=>get_post_field('post_title',$aid),'post_excerpt'=>get_post_field('post_excerpt',$aid),'post_content'=>get_post_field('post_content',$aid)];
  $row=['media_data'=>$media_data,'snapshot_id'=>bin2hex(random_bytes(16)),'created_at'=>gmdate('c'),'actor'=>(int)($ctx['actor']??0),'reason'=>(string)($ctx['reason']??''),'correlation_id'=>(string)($ctx['correlation_id']??''),'content'=>$read,'dates'=>['post_date'=>$p->post_date??'','post_date_gmt'=>$p->post_date_gmt??''],'bridge_meta'=>$meta];
  $rows=get_post_meta($id,'_contentbridge_operator_snapshots',true);$rows=is_array($rows)?$rows:[];$rows[]=$row;
  if(!update_post_meta($id,'_contentbridge_operator_snapshots',array_slice($rows,-50)))return self::error('snapshot_failed','Snapshot could not be persisted.',500);
  return $row;
 }
 private static function audit(array $ctx,string $op,int $id,$before,$after,bool $verified):void {
  $rows=(array)get_option('contentbridge_operator_audit',[]);$rows[]=['actor'=>$ctx['actor'],'timestamp'=>gmdate('c'),'site'=>home_url('/'),'tool'=>$op,'object'=>$id,'before'=>$before,'after'=>$after,'reason'=>$ctx['reason'],'correlation_id'=>$ctx['correlation_id'],'verified'=>$verified];
  update_option('contentbridge_operator_audit',array_slice($rows,-200),false);
 }
 public static function dispatch($r){
  $d=$r->get_json_params();if(!is_array($d)||array_is_list($d))return self::error('malformed_payload','JSON object required.');
  if(array_diff(array_keys($d),['operation','arguments','context','idempotency_key']))return self::error('malformed_payload','Unknown operator field.');
  $op=$d['operation']??'';$a=$d['arguments']??[];
  if(!is_string($op)||!in_array($op,array_merge(self::READ,self::WRITE),true)||!is_array($a)||($a&&array_is_list($a)))return self::error('invalid_operation','Unsupported operator operation.');
  $fields=match($op){
   'search_content'=>['query','slug','url','status','after','before','author','category','tag','content_type','selection','limit','offset'],
   'find_authors','find_categories','find_tags'=>['query','limit'],
   'list_content_media','list_content_snapshots'=>['post_id'],
   'content_quality_check'=>['post_id','expected','check_links','expect_structured_data','profile'],
   'find_internal_links'=>['post_id','query'],
   'get_maintenance_status'=>['attachment_id'], 'get_operator_audit'=>['limit'],
   'resolve_content_taxonomy'=>['post_id','expected_version','author','categories','tags'],
   'replace_content_media'=>['post_id','expected_version','attachment_id','target','inline_index','convert_to'],
   'update_media_metadata'=>['post_id','expected_version','attachment_id','alt_text','title','caption','description'],
   'create_content_snapshot'=>['post_id','expected_version'],
   'restore_content_snapshot'=>['post_id','expected_version','snapshot_id'],
   'apply_internal_links'=>['post_id','expected_version','links','query'],
   'run_maintenance'=>['expected_version','action','dry_run','central_urls','attachment_id','expected_attachment_version','owner_approved','component','target_version','backup_id'],
   'prepare_content_preview'=>['post_id','expected_version'],
   'record_content_visual_qa'=>['post_id','expected_version','capture_id','screenshots','review'],
  };
  if(array_diff(array_keys($a),$fields))return self::error('invalid_argument','Unknown operation argument.');
  foreach(['post_id','attachment_id','inline_index','limit','offset']as$k)if(isset($a[$k])&&(!is_int($a[$k])||$a[$k]<($k==='offset'?0:1)))return self::error('invalid_argument','Positive integer required.');
  foreach(['query','slug','url','status','after','before','author','category','tag','content_type','selection','expected_version','target','alt_text','title','caption','description','snapshot_id','action','convert_to','expected_attachment_version','component','target_version','backup_id']as$k)if(isset($a[$k])&&(!is_string($a[$k])||strlen($a[$k])>2000))return self::error('invalid_argument','Bounded string required.');
  foreach(['check_links','expect_structured_data','dry_run','owner_approved']as$k)if(isset($a[$k])&&!is_bool($a[$k]))return self::error('invalid_argument','Boolean required.');
  if(in_array('post_id',$fields,true)&&!isset($a['post_id']))return self::error('invalid_argument','post_id required.');
  if(in_array($op,self::READ,true))return self::read_operation($op,$a);
  if($op==='run_maintenance'&&($a['dry_run']??true)===true)return self::maintenance($a);
  $ctx=$d['context']??[];
  if(!is_array($ctx)||!is_int($ctx['actor']??null)||$ctx['actor']<1||!is_string($ctx['reason']??null)||trim($ctx['reason'])===''||strlen($ctx['reason'])>2000||!is_string($ctx['correlation_id']??null)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$ctx['correlation_id']))return self::error('actor_required','Actor, reason and correlation ID required.');
  $key=$d['idempotency_key']??'';if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key))return self::error('idempotency_required','Safe idempotency key required.');
  $ledger='cbop_once_'.hash('sha256',$key);$hash=hash('sha256',$r->get_body());
  if(!add_option($ledger,['hash'=>$hash,'state'=>'pending'],'','no')){$v=get_option($ledger,[]);if(($v['hash']??'')!==$hash)return self::error('idempotency_conflict','Key already used for a different request.',409);if(($v['state']??'')!=='complete')return self::error('in_progress','Reconcile interrupted workflow.',409);return !empty($v['error'])?self::error($v['error']['code'],$v['error']['message'],$v['error']['status']):$v['result'];}
  $id=$a['post_id']??0;
  if($op==='run_maintenance')$out=self::locked(0,function()use($a,$ctx,$op){$before=self::maintenance_state();$result=self::maintenance($a,$ctx);$after=self::maintenance_state();self::audit($ctx,$op,0,$before,$after,!is_wp_error($result)&&($result['verified']??false)===true);return $result;});
  elseif(!is_int($id)||$id<1){$out=self::error('invalid_id','post_id required.');}
  else $out=self::locked($id,function()use($op,$a,$ctx,$id){
   $before=Comitement_ContentBridge_V1::read($id);if(is_wp_error($before))return $before;
   if(!is_string($a['expected_version']??null)||!hash_equals($before['version'],$a['expected_version']))return self::error('stale_version','Fresh expected_version required.',409);
   $snap=self::snapshot($id,$ctx);if(is_wp_error($snap))return $snap;
   $result=self::mutate($op,$id,$a,$ctx);
   $after=Comitement_ContentBridge_V1::read($id);$verified=!is_wp_error($result)&&!is_wp_error($after);
   self::audit($ctx,$op,$id,$before,$after,$verified);
   if(is_wp_error($result))return $result;
   if(!$verified)return self::error('verify_failed','Mutation completed but reread failed; snapshot available.',500);
   return self::ok(['result'=>$result,'content'=>$after,'snapshot_id'=>$snap['snapshot_id'],'verified'=>true]);
  });
  $row=['hash'=>$hash,'state'=>'complete'];if(is_wp_error($out))$row['error']=['code'=>str_replace('contentbridge_','',$out->get_error_code()),'message'=>$out->get_error_message(),'status'=>$out->get_error_data()['status']??500];else $row['result']=$out;
  update_option($ledger,$row,false);return $out;
 }
 private static function snapshots(int $id):array{$v=get_post_meta($id,'_contentbridge_operator_snapshots',true);return is_array($v)?$v:[];}
 private static function read_operation(string $op,array $a){return match($op){
  'search_content'=>self::search($a),
  'find_authors','find_categories','find_tags'=>self::entities($op,$a),
  'list_content_media'=>self::media((int)($a['post_id']??0)),
  'content_quality_check'=>self::qa((int)($a['post_id']??0),$a),
  'list_content_snapshots'=>self::ok(['snapshots'=>array_map(fn($s)=>array_diff_key($s,array_flip(['content','bridge_meta','dates','media_data'])),self::snapshots((int)($a['post_id']??0)))]),
  'find_internal_links'=>self::links((int)($a['post_id']??0),$a),
  'get_maintenance_status'=>self::ok(self::maintenance_state()+(!empty($a['attachment_id'])?['attachment'=>['id'=>$a['attachment_id'],'version'=>self::attachment_version($a['attachment_id']),'referenced'=>self::other_media_references($a['attachment_id'],0)]]:[])),
  'get_operator_audit'=>self::ok(['items'=>array_slice((array)get_option('contentbridge_operator_audit',[]),-min(100,max(1,(int)($a['limit']??20))))]),
 };}
 public static function entities(string $op,array $a){
  $q=trim((string)($a['query']??''));$limit=min(100,max(1,(int)($a['limit']??50)));$out=[];
  if($op==='find_authors'){$users=get_users(['role__in'=>['administrator','editor','author'],'number'=>$limit,'search'=>$q!==''?'*'.$q.'*':'','search_columns'=>['display_name','user_login']]);foreach($users as$u)if(user_can($u->ID,'edit_posts'))$out[]=['id'=>(int)$u->ID,'name'=>$u->display_name];}
  else{$tax=$op==='find_categories'?'category':'post_tag';$v=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>$limit,'search'=>$q]);if(is_wp_error($v))return self::error('taxonomy_unavailable','Could not read taxonomy.',503);foreach($v as$t)$out[]=['id'=>(int)$t->term_id,'name'=>$t->name,'slug'=>$t->slug];}
  $exact=array_values(array_filter($out,fn($x)=>strcasecmp(trim($x['name']),$q)===0));
  return self::ok(['items'=>$out,'resolution'=>$q===''?'LIST':(count($exact)===1?'UNIQUE':(count($exact)>1?'AMBIGUOUS':'NOT_FOUND')),'resolved_id'=>count($exact)===1?$exact[0]['id']:null,'bounded'=>true]);
 }
 public static function search(array $a){
  $limit=min(50,max(1,(int)($a['limit']??20)));$offset=min(10000,max(0,(int)($a['offset']??0)));$q=trim((string)($a['query']??''));$type=$a['content_type']??'post';
  if(!in_array($type,['post','page'],true))return self::error('content_type','Only post and page supported.');
  $args=['post_type'=>$type,'post_status'=>['publish','future','draft','pending','private'],'posts_per_page'=>$limit,'offset'=>$offset,'orderby'=>'date','order'=>'DESC','s'=>$q];
  if(isset($a['status'])){if(!in_array($a['status'],$args['post_status'],true))return self::error('status','Invalid content status.');$args['post_status']=$a['status'];}
  if(!empty($a['slug']))$args['name']=sanitize_title($a['slug']);
  if(!empty($a['url'])){if(wp_parse_url($a['url'],PHP_URL_HOST)!==wp_parse_url(home_url('/'),PHP_URL_HOST))return self::error('invalid_url','URL must belong to this site.');$id=url_to_postid($a['url']);if(!$id)return self::ok(['items'=>[],'resolution'=>'NOT_FOUND']);$args['p']=$id;unset($args['s']);}
  foreach(['author'=>'find_authors','category'=>'find_categories','tag'=>'find_tags']as$field=>$op)if(!empty($a[$field])){$v=self::entities($op,['query'=>$a[$field],'limit'=>100]);if(is_wp_error($v)||$v['resolution']!=='UNIQUE')return self::error('ambiguous_taxonomy','Search filter must resolve uniquely.',409);$args[match($field){'author'=>'author','category'=>'cat','tag'=>'tag_id'}]=$v['resolved_id'];}
  $date=[];foreach(['after','before']as$k)if(isset($a[$k])){if(!is_string($a[$k])||strtotime($a[$k])===false)return self::error('date','Invalid date range.');$date[$k]=$a[$k];}if(isset($date['after'],$date['before'])&&strtotime($date['after'])>strtotime($date['before']))return self::error('date','Date range is reversed.');if($date)$args['date_query']=[$date+['inclusive'=>true,'column'=>'post_date_gmt']];
  if(isset($a['selection'])&&!in_array($a['selection'],['latest','ranked'],true))return self::error('selection','Use latest or ranked.');
  if(($a['selection']??'')==='latest'&&$offset!==0)return self::error('selection','Latest cannot be combined with offset.');
  $ranked=$q!==''&&($a['selection']??'')!=='latest';if($ranked){$args['posts_per_page']=200;$args['offset']=0;}
  $query=new WP_Query($args);$rows=[];foreach($query->posts as$p){$v=Comitement_ContentBridge_V1::read((int)$p->ID);if(is_wp_error($v))continue;$v['relevance']=self::score($q,$v['title'],strip_tags($v['content']));unset($v['content']);$rows[]=$v;}
  if($q!==''&&($a['selection']??'')!=='latest')usort($rows,fn($x,$y)=>($y['relevance']<=>$x['relevance'])?:strcmp($y['published_at'],$x['published_at']));
  if(($a['selection']??'')==='latest'&&$rows){
   // Read at least two candidates even for limit=1; equal dates cannot identify one latest post.
   $probe=new WP_Query(array_replace($args,['posts_per_page'=>2]));
   if(count($probe->posts)>1&&get_post_time('c',true,$probe->posts[0])===get_post_time('c',true,$probe->posts[1]))return self::ok(['items'=>$rows,'total'=>(int)$query->found_posts,'selection'=>'latest','resolution'=>'AMBIGUOUS','has_more'=>$query->found_posts>count($rows),'reason'=>'Several posts have the latest timestamp.']);
   $rows=[$rows[0]];return self::ok(['items'=>$rows,'total'=>(int)$query->found_posts,'selection'=>'latest','resolution'=>'UNIQUE','has_more'=>false]);
  }
  $resolution=$query->found_posts===1?'UNIQUE':($query->found_posts===0?'NOT_FOUND':'AMBIGUOUS');$truncated=$ranked&&$query->found_posts>200;
  if(($a['selection']??'')==='ranked'&&!$truncated&&$offset===0&&($rows[0]['relevance']??0)>=100&&($rows[1]['relevance']??0)<100){$rows=[$rows[0]];$resolution='UNIQUE';}
  elseif($ranked)$rows=array_slice($rows,$offset,$limit);
  return self::ok(['items'=>$rows,'total'=>(int)$query->found_posts,'offset'=>$offset,'has_more'=>$offset+count($rows)<$query->found_posts,'ranking_scope'=>$ranked?'all matching candidates up to 200; incomplete ranking never resolves uniquely':'date ordered','ranking_complete'=>!$truncated,'resolution'=>$truncated?'AMBIGUOUS':$resolution]);
 }
 public static function score(string $q,string $title,string $body):int {
  $q=strtolower($q);$title=strtolower($title);$body=strtolower($body);if($q==='')return 0;
  $n=$title===$q?100:0;if(str_contains($title,$q))$n+=40;if(str_contains($body,$q))$n+=10;
  foreach(array_unique(preg_split('/\s+/',$q))as$w)if(strlen($w)>1){if(str_contains($title,$w))$n+=8;if(str_contains($body,$w))$n+=2;}return $n;
 }
 public static function image_tags(string $html):array {
  preg_match_all('/<!--.*?-->|<(script|style|textarea|pre|code)\b[^>]*>.*?<\/\1\s*>|<img\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>/is',$html,$matches,PREG_OFFSET_CAPTURE);$out=[];
  foreach($matches[0]as$m){$tag=$m[0];if(!preg_match('/^<img\b/i',$tag))continue;$i=count($out);$src='';$alt='';foreach(['src','alt']as$k)if(preg_match('/\b'. $k .'\s*=\s*(["\'])(.*?)\1/is',$tag,$a)){$v=html_entity_decode($a[2],ENT_QUOTES);if($k==='src')$src=$v;else $alt=$v;}
   preg_match('/\bwp-image-(\d+)\b/',$tag,$id);$out[]=['index'=>$i+1,'url'=>$src,'alt_text'=>$alt,'attachment_id'=>(int)($id[1]??0),'html'=>$tag,'offset'=>$m[1]];
  }return $out;
 }
 public static function media(int $id){
  $p=Comitement_ContentBridge_V1::read($id);if(is_wp_error($p))return $p;$rows=self::image_tags($p['content']);
  foreach($rows as&$row){unset($row['html'],$row['offset']);if(!$row['attachment_id']&&$row['url'])$row['attachment_id']=attachment_url_to_postid($row['url']);$row['duplicate']=count(array_filter($rows,fn($x)=>$x['url']===$row['url']))>1;
   if($row['attachment_id']){$row['file']=self::file_info($row['attachment_id']);$row['attachment_version']=self::attachment_version($row['attachment_id']);}
  }unset($row);
  return self::ok(['post_id'=>$id,'version'=>$p['version'],'featured_image'=>$p['featured_image'],'inline_images'=>$rows]);
 }
 private static function file_info(int $id):array {
  $path=get_attached_file($id);$info=is_string($path)&&is_file($path)?@getimagesize($path):false;$out=['mime_type'=>$info['mime']??'','width'=>$info[0]??0,'height'=>$info[1]??0,'missing'=>!$info,'very_small'=>$info&&min($info[0],$info[1])<32,'sha256'=>$info?hash_file('sha256',$path):null,'visual_check'=>'UNAVAILABLE'];
  if($info&&function_exists('imagecreatefromstring')&&$info[0]*$info[1]<=40000000){$im=@imagecreatefromstring(file_get_contents($path));if($im){$n=0;$black=0;$blank=0;for($y=0;$y<16;$y++)for($x=0;$x<16;$x++){$c=imagecolorsforindex($im,imagecolorat($im,min($info[0]-1,(int)($x*$info[0]/16)),min($info[1]-1,(int)($y*$info[1]/16))));$n++;if(max($c['red'],$c['green'],$c['blue'])<8)$black++;if(min($c['red'],$c['green'],$c['blue'])>248||$c['alpha']>120)$blank++;}$out['black_or_blank_suspected']=max($black,$blank)/$n>0.98;$out['visual_check']='sampled_pixels';imagedestroy($im);}}
  return $out;
 }
 private static function mutate(string $op,int $id,array $a,array $ctx){
  $key=$ctx['correlation_id'];$p=Comitement_ContentBridge_V1::read($id);
  if($op==='create_content_snapshot')return ['snapshot_created'=>true];
  if($op==='prepare_content_preview')return Comitement_ContentBridge_V1::preview_grant($id,$p['version']);
  if($op==='record_content_visual_qa'){
   $capture=$a['capture_id']??'';$grant=is_string($capture)?get_transient('cbop_preview_'.$capture):false;
   if(!is_array($grant)||($grant['post_id']??null)!==$id||($grant['version']??'')!==$p['version'])return self::error('preview_expired','A fresh preview capture bound to this exact version is required.',409);
   $review=$a['review']??[];$shots=$a['screenshots']??[];
   $required=['rendered_content','layout','images','theme_match'];
   if(!is_array($review)||array_diff(array_keys($review),$required)||count($review)!==count($required)||!is_array($shots)||!array_is_list($shots)||count($shots)!==2)return self::error('visual_evidence','Two actual browser screenshots and explicit content/layout/image/theme review required.');
   foreach($required as$key)if(!in_array($review[$key]??null,['PASS','FAIL','UNKNOWN'],true))return self::error('visual_evidence','Review uses PASS/FAIL/UNKNOWN; unknown is never success.');
   $evidence=[];$mobile=false;$desktop=false;
   foreach($shots as$shot){if(!is_array($shot)||array_diff(array_keys($shot),['data_base64','viewport_width','viewport_height','capture_method'])||($shot['capture_method']??'')!=='browser_screenshot'||!is_int($shot['viewport_width']??null)||!is_int($shot['viewport_height']??null)||!is_string($shot['data_base64']??null)||strlen($shot['data_base64'])>2800000)return self::error('visual_evidence','Bounded genuine browser capture required.');
    $bytes=base64_decode($shot['data_base64'],true);$info=is_string($bytes)?@getimagesizefromstring($bytes):false;
    if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg'],true)||$info[0]!==$shot['viewport_width']||$info[1]<$shot['viewport_height']||$shot['viewport_height']<480||$info[0]*$info[1]>40000000)return self::error('visual_evidence','Screenshot dimensions or MIME do not match capture.');
    $mobile=$mobile||($info[0]>=320&&$info[0]<=480);$desktop=$desktop||($info[0]>=1024&&$info[0]<=1920);$evidence[]=['sha256'=>hash('sha256',$bytes),'width'=>$info[0],'height'=>$info[1],'capture_method'=>'browser_screenshot'];
   }
   if(!$mobile||!$desktop)return self::error('visual_evidence','Mobile and desktop captures required.');
   $record=['version'=>$p['version'],'captured_at'=>gmdate('c'),'expires_at'=>time()+3600,'actor'=>$ctx['actor'],'reason'=>$ctx['reason'],'correlation_id'=>$ctx['correlation_id'],'screenshots'=>$evidence,'review'=>$review,'status'=>in_array('FAIL',$review,true)?'FAILED':(in_array('UNKNOWN',$review,true)?'PARTIAL':'SUCCESS')];
   update_post_meta($id,'_contentbridge_operator_visual',$record);delete_transient('cbop_preview_'.$capture);return $record;
  }
  if($op==='resolve_content_taxonomy'){$changes=[];foreach(['author'=>'find_authors','categories'=>'find_categories','tags'=>'find_tags']as$field=>$lookup)if(array_key_exists($field,$a)){
    $names=$field==='author'?[$a[$field]]:$a[$field];if(!is_array($names)||!array_is_list($names)||count($names)>50)return self::error('taxonomy','Names must be a bounded list.');$ids=[];
    foreach($names as$name){if(!is_string($name))return self::error('taxonomy','Use names.');$v=self::entities($lookup,['query'=>$name,'limit'=>100]);if(is_wp_error($v)||$v['resolution']!=='UNIQUE')return self::error('ambiguous_taxonomy','Name must resolve uniquely; no new terms created.',409);$ids[]=$v['resolved_id'];}$changes[$field]=$field==='author'?$ids[0]:array_values(array_unique($ids));
   }foreach($changes as$k=>$v)if($p[$k]===$v)unset($changes[$k]);if(!$changes)return ['unchanged'=>true];$r=Comitement_ContentBridge_V1::operator_write($id,$changes+['idempotency_key'=>$key]);if(is_wp_error($r))return $r;foreach($changes as$k=>$v)if($r[$k]!==$v)return self::error('verify_failed','Taxonomy assignment differs.',500);return $r;}
  if($op==='replace_content_media'){
   $aid=$a['attachment_id']??0;if(!is_int($aid)||!wp_attachment_is_image($aid)||!wp_get_attachment_url($aid))return self::error('invalid_media','Existing image attachment required.');
   if(get_post_meta($aid,'_contentbridge_v1_fixture',true)!==get_post_meta($id,'_contentbridge_v1_fixture',true)&&get_post_meta($aid,'_contentbridge_v1_fixture',true))return self::error('fixture_mismatch','Fixture media cannot be assigned to real content.');
   if(isset($a['convert_to'])){
    $format=$a['convert_to'];if(!in_array($format,['image/png','image/jpeg','image/webp'],true))return self::error('invalid_conversion','Use PNG, JPEG or WebP.');
    $source=get_attached_file($aid);$info=is_string($source)&&is_file($source)?@getimagesize($source):false;if(!$info||$info[0]*$info[1]>40000000||filesize($source)>Comitement_ContentBridge_V1::MAX_MEDIA)return self::error('conversion_source','A bounded readable native image is required.');
    if($info['mime']!==$format){
     $editor=wp_get_image_editor($source);if(is_wp_error($editor))return self::error('conversion_unavailable','Native WordPress image editor could not decode the image.',422);
     $temp=wp_tempnam('contentbridge-convert');if(!$temp)return self::error('conversion_unavailable','Cannot allocate conversion.');
     try{$converted=$editor->save($temp,$format);if(is_wp_error($converted))return self::error('conversion_failed','Native conversion failed.',422);$path=$converted['path']??$temp;$bytes=is_file($path)?file_get_contents($path):false;$fresh=$bytes?@getimagesizefromstring($bytes):false;
      if(!$fresh||$fresh['mime']!==$format||$fresh[0]!==$info[0]||$fresh[1]!==$info[1]||strlen($bytes)>Comitement_ContentBridge_V1::MAX_MEDIA)return self::error('conversion_failed','Converted image MIME/dimensions did not verify.');
      $extension=match($format){'image/png'=>'png','image/jpeg'=>'jpg',default=>'webp'};$request=new WP_REST_Request('POST','/contentbridge/v1/media');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode(['idempotency_key'=>$key.':convert','file'=>'converted-'.$aid.'.'.$extension,'mime_type'=>$format,'data_base64'=>base64_encode($bytes),'test_fixture'=>(string)get_post_meta($id,'_contentbridge_v1_fixture',true),'alt_text'=>(string)get_post_meta($aid,'_wp_attachment_image_alt',true),'title'=>(string)get_post_field('post_title',$aid),'caption'=>(string)get_post_field('post_excerpt',$aid)]));
      $upload=Comitement_ContentBridge_V1::upload($request);if(is_wp_error($upload))return $upload;$original=$aid;$aid=$upload['media']['attachment_id'];wp_update_post(['ID'=>$aid,'post_content'=>get_post_field('post_content',$original)]);update_post_meta($aid,'_contentbridge_operator_conversion',['source_attachment'=>$original,'correlation_id'=>$ctx['correlation_id']]);
     }finally{if(is_file($temp))unlink($temp);if(isset($path)&&$path!==$temp&&is_file($path))unlink($path);}
    }
   }
   if(($a['target']??'')==='featured')return Comitement_ContentBridge_V1::operator_write($id,['idempotency_key'=>$key,'featured_image'=>['attachment_id'=>$aid]]);
   $index=$a['inline_index']??0;if(($a['target']??'')!=='inline'||!is_int($index)||$index<1)return self::error('invalid_media','Use featured or a positive inline_index.');
   $images=self::image_tags($p['content']);if(!isset($images[$index-1]))return self::error('not_found','Inline image index not found.',404);
   $old=$images[$index-1];$new=wp_get_attachment_image($aid,'full',false,['alt'=>get_post_meta($aid,'_wp_attachment_image_alt',true)]);if(!$new)return self::error('invalid_media','Cannot render attachment.');
   if(preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is',$old['html'],$classes)){
    $class=preg_replace('/\bwp-image-\d+\b/','wp-image-'.$aid,$classes[2]);if(!preg_match('/\bwp-image-'.$aid.'\b/',$class))$class.=' wp-image-'.$aid;
    $new=preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/is','class="'.esc_attr($class).'"',$new);
   }
   $body=substr_replace($p['content'],$new,$old['offset'],strlen($old['html']));
   // Update the containing Gutenberg image block ID only; never replace another image.
   $prefix=substr($body,0,$old['offset']);$start=strrpos($prefix,'<!-- wp:image ');$end=strrpos($prefix,'<!-- /wp:image -->');
   if($start!==false&&($end===false||$start>$end)){$close=strpos($body,'-->', $start);$comment=substr($body,$start,$close+3-$start);$comment=preg_replace('/"id"\s*:\s*\d+/','"id":'.$aid,$comment);$body=substr_replace($body,$comment,$start,$close+3-$start);}
   $result=Comitement_ContentBridge_V1::operator_write($id,['idempotency_key'=>$key,'content'=>$body]);if(is_wp_error($result))return $result;
   // Preserve filename associations and exact blocks used by the existing bulk content writer.
   $tracked=(array)get_post_meta($id,'_contentbridge_v1_inline_blocks',true);$mapping=(array)get_post_meta($id,'_contentbridge_v1_inline',true);$changedFile=null;$cursor=0;
   foreach($tracked as&$block){$html=$block['html']??'';$at=$html!==''?strpos($p['content'],$html,$cursor):false;if($at===false)continue;$cursor=$at+strlen($html);if($old['offset']<$at||$old['offset']>=$cursor)continue;
    $block['html']=substr_replace($html,$new,$old['offset']-$at,strlen($old['html']));$block['html']=preg_replace_callback('/<!-- wp:image\s+.*?-->/s',fn($m)=>preg_replace('/"id"\s*:\s*\d+/','"id":'.$aid,$m[0]),$block['html']);$changedFile=$block['file']??null;break;
   }unset($block);
   if($changedFile!==null)foreach($mapping as&$entry)if(($entry['file']??null)===$changedFile){$entry['attachment_id']=$aid;$entry['url']=wp_get_attachment_url($aid);$entry['alt_text']=get_post_meta($aid,'_wp_attachment_image_alt',true);}unset($entry);
   update_post_meta($id,'_contentbridge_v1_inline',$mapping);update_post_meta($id,'_contentbridge_v1_inline_blocks',$tracked);
   $fresh=self::image_tags(Comitement_ContentBridge_V1::read($id)['content']);if(($fresh[$index-1]['attachment_id']??0)!==$aid||count($fresh)!==count($images))return self::error('verify_failed','Image replacement did not persist exactly.',500);
   foreach($images as$i=>$image)if($i!==$index-1&&($fresh[$i]['html']??'')!==$image['html'])return self::error('verify_failed','Unselected inline image changed.',500);
   if((int)get_post_thumbnail_id($id)!==(int)($p['featured_image']['attachment_id']??0))return self::error('verify_failed','Featured image changed.',500);return ['inline_index'=>$index,'attachment_id'=>$aid];
  }
  if($op==='update_media_metadata'){
   $aid=$a['attachment_id']??0;$media=self::media($id);$ids=array_column($media['inline_images'],'attachment_id');$ids[]=$p['featured_image']['attachment_id']??0;
   if(!is_int($aid)||!in_array($aid,$ids,true)||!wp_attachment_is_image($aid))return self::error('invalid_media','Attachment must belong to this content.');
   // Shared metadata is never silently changed on other posts.
   if(self::other_media_references($aid,$id))return self::error('shared_media','Shared attachment requires an explicit separate review.',409);
   foreach(['alt_text','title','caption','description']as$k)if(isset($a[$k])&&(!is_string($a[$k])||strlen($a[$k])>2000))return self::error('metadata','Bounded text metadata required.');
   $patch=['ID'=>$aid];foreach(['title'=>'post_title','caption'=>'post_excerpt','description'=>'post_content']as$k=>$field)if(isset($a[$k])){if(!is_string($a[$k]))return self::error('metadata','Metadata must be text.');$patch[$field]=sanitize_text_field($a[$k]);}
   $r=wp_update_post(wp_slash($patch),true);if(is_wp_error($r))return $r;
   if(isset($a['alt_text'])){if(!is_string($a['alt_text']))return self::error('metadata','Alt must be text.');update_post_meta($aid,'_wp_attachment_image_alt',sanitize_text_field($a['alt_text']));}
   if(isset($a['alt_text'])){
    $body=$p['content'];foreach(array_reverse(self::image_tags($body))as$image){if($image['attachment_id']!==$aid&&$image['url']!==wp_get_attachment_url($aid))continue;$tag=$image['html'];$attr='alt="'.esc_attr(sanitize_text_field($a['alt_text'])).'"';$tag=preg_match('/\balt\s*=/i',$tag)?preg_replace('/\balt\s*=\s*(["\'])(.*?)\1/is',$attr,$tag):preg_replace('/<img\b/i','<img '.$attr,$tag,1);$body=substr_replace($body,$tag,$image['offset'],strlen($image['html']));}
    if($body!==$p['content']){$save=Comitement_ContentBridge_V1::operator_write($id,['idempotency_key'=>$key,'content'=>$body]);if(is_wp_error($save))return $save;}
   }
   foreach($patch as$field=>$value)if($field!=='ID'&&get_post_field($field,$aid)!==$value)return self::error('verify_failed','Media metadata differs.',500);if(isset($a['alt_text'])&&get_post_meta($aid,'_wp_attachment_image_alt',true)!==sanitize_text_field($a['alt_text']))return self::error('verify_failed','Alt metadata differs.',500);
   return ['attachment_id'=>$aid];
  }
  if($op==='restore_content_snapshot'){
   $snapshot=null;foreach(self::snapshots($id)as$s)if($s['snapshot_id']===($a['snapshot_id']??''))$snapshot=$s;
   if(!$snapshot)return self::error('snapshot_not_found','Snapshot does not belong to this content.',404);
   $old=$snapshot['content'];foreach($snapshot['media_data']??[]as$aid=>$md)if(self::media_metadata_changed((int)$aid,$md)&&self::other_media_references((int)$aid,$id))return self::error('shared_media','Snapshot media is now shared; review required before rollback.',409);if($old['status']!==$p['status'])return self::error('status_review','Restore across publication status requires a separate explicit publication workflow.',409);
   $payload=array_intersect_key($old,array_flip(['title','content','excerpt','slug','author','categories','tags','seo']));if($old['featured_image'])$payload['featured_image']=['attachment_id'=>$old['featured_image']['attachment_id']];
   if($old['status']==='future')$payload['publish_at']=$old['publish_at'];
   $r=Comitement_ContentBridge_V1::operator_write($id,$payload+['idempotency_key'=>$key]);if(is_wp_error($r))return $r;
   // Snapshot content is trusted server-side state that was already persisted by WordPress. Restore it exactly after the normal writer so rollback is byte-faithful rather than re-sanitizing stored block HTML.
   if(get_post_field('post_content',$id)!==$old['content']){$exact=wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$old['content']]),true);if(is_wp_error($exact))return $exact;}
   if(!$old['featured_image'])delete_post_thumbnail($id);foreach($snapshot['bridge_meta']as$k=>$v)update_post_meta($id,$k,$v);
   $r=wp_update_post(wp_slash(['ID'=>$id]+$snapshot['dates']),true);if(is_wp_error($r))return $r;
   foreach($snapshot['media_data']??[]as$aid=>$md){if(!self::media_metadata_changed((int)$aid,$md))continue;$alt=$md['alt_text'];unset($md['alt_text']);$result=wp_update_post(wp_slash(['ID'=>(int)$aid]+$md),true);if(is_wp_error($result))return $result;update_post_meta((int)$aid,'_wp_attachment_image_alt',$alt);}
   $fresh=Comitement_ContentBridge_V1::read($id);foreach(['title','content','excerpt','slug','author','categories','tags','seo','status']as$k)if($fresh[$k]!==$old[$k])return self::error('restore_verify_failed','Restored content differs for '.$k.'; inspect snapshot.',500);
   return ['restored_snapshot'=>$snapshot['snapshot_id']];
  }
  if($op==='apply_internal_links')return self::apply_links($id,$a,$key);
  if($op==='run_maintenance')return self::maintenance($a);
  return self::error('unsupported','Operation unavailable.');
 }
 private static function media_metadata_changed(int $aid,array $md):bool{foreach($md as$k=>$v)if(($k==='alt_text'?get_post_meta($aid,'_wp_attachment_image_alt',true):get_post_field($k,$aid))!==$v)return true;return false;}
 public static function other_media_references(int $aid,int $exclude,int $excludePendingAvatarUser=0):bool {
  global $wpdb;$url=wp_get_attachment_url($aid);
  if(!$url||!isset($wpdb)||!method_exists($wpdb,'get_var'))return true;
  // Conservative: galleries, reusable blocks, attachment variants and serialized/native mappings count too.
  $patterns=['wp-image-'.$aid,$url,preg_replace('/(?:-scaled)?\\.[a-z0-9]+$/i','',$url),'"id":'.$aid,'"id": '.$aid,'"ids":[', 'ids="', 'i:'.$aid.';', 's:'.strlen((string)$aid).':"'.$aid.'"'];
  $clauses=[];$values=[$exclude];foreach($patterns as$pattern){$clauses[]='p.post_content LIKE %s';$values[]='%'.$wpdb->esc_like($pattern).'%';}
  $clauses[]="EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND ((m.meta_key='_thumbnail_id' AND m.meta_value=%s) OR (m.meta_key IN ('_contentbridge_v1_inline','_contentbridge_v1_images','_contentbridge_operator_snapshots','_elementor_data','_wp_attachment_metadata') AND (m.meta_value LIKE %s OR m.meta_value LIKE %s))))";
  array_push($values,(string)$aid,'%'.$wpdb->esc_like('i:'.$aid.';').'%', '%'.$wpdb->esc_like($url).'%');
  $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.ID<>%d AND p.post_type<>'revision' AND (".implode(' OR ',$clauses).')',...$values));
  if($count===null||!empty($wpdb->last_error)||(int)$count>0)return true;
  if(!isset($wpdb->usermeta))return true;
  $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE NOT (user_id=%d AND meta_key='_ctf_avatar_pending_id') AND (meta_value=%s OR meta_value LIKE %s OR meta_value LIKE %s)",$excludePendingAvatarUser,(string)$aid,'%'.$wpdb->esc_like('i:'.$aid.';').'%', '%'.$wpdb->esc_like(preg_replace('/(?:-scaled)?\\.[a-z0-9]+$/i','',$url)).'%'));
  if($count===null||!empty($wpdb->last_error)||(int)$count>0)return true;
  if(!isset($wpdb->options))return true;
  $numeric=(string)$aid;$patterns=[preg_replace('/(?:-scaled)?\\.[a-z0-9]+$/i','',$url),'i:'.$aid.';','s:'.strlen($numeric).':"'.$numeric.'"',':'.$aid.',',':'.$aid.'}',':"'.$aid.'"'];$where=['option_value=%s'];$values=[$numeric];
  foreach($patterns as$pattern){$where[]='option_value LIKE %s';$values[]='%'.$wpdb->esc_like($pattern).'%';}
  $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE 'cbv1_%' AND option_name NOT LIKE 'cbop_%' AND option_name NOT LIKE 'cmod_%' AND (".implode(' OR ',$where).')',...$values));
  return $count===null||!empty($wpdb->last_error)||(int)$count>0;
 }
 public static function links(int $id,array $a){
  $p=Comitement_ContentBridge_V1::read($id);if(is_wp_error($p))return $p;
  preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/is',$p['content'],$m);$existing=array_map(fn($u)=>html_entity_decode($u,ENT_QUOTES),$m[2]);
  $q=$a['query']??$p['title'];$v=self::search(['query'=>$q,'status'=>'publish','limit'=>20]);if(is_wp_error($v))return $v;
  $rows=[];foreach($v['items']as$r)if($r['post_id']!==$id&&$r['relevance']>=8&&!in_array($r['url'],$existing,true))$rows[]=['post_id'=>$r['post_id'],'url'=>$r['url'],'title'=>$r['title'],'relevance'=>$r['relevance']];
  return self::ok(['existing_links'=>$existing,'candidates'=>$rows,'automatic_insertion'=>false]);
 }
 private static function apply_links(int $id,array $a,string $key){
  $items=$a['links']??[];if(!is_array($items)||!array_is_list($items)||!$items||count($items)>10)return self::error('links','1–10 reviewed links required.');
  $p=Comitement_ContentBridge_V1::read($id);$body=$p['content'];$candidates=self::links($id,$a);if(is_wp_error($candidates))return $candidates;$urls=array_column($candidates['candidates'],'url');
  $dom=new DOMDocument();$prev=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?><div id="cbop-root">'.$body.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($prev);$xp=new DOMXPath($dom);
  foreach($items as$link){$anchor=$link['anchor']??'';$url=$link['url']??'';if(!is_string($anchor)||trim($anchor)===''||!in_array($url,$urls,true))return self::error('links','Link must be a relevant existing candidate.');
   $changed=false;foreach($xp->query('//div[@id="cbop-root"]//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::h1) and not(ancestor::h2)]')as$node){$pos=strpos($node->nodeValue,$anchor);if($pos===false)continue;$parent=$node->parentNode;$parent->insertBefore($dom->createTextNode(substr($node->nodeValue,0,$pos)),$node);$el=$dom->createElement('a');$el->setAttribute('href',$url);$el->appendChild($dom->createTextNode($anchor));$parent->insertBefore($el,$node);$parent->insertBefore($dom->createTextNode(substr($node->nodeValue,$pos+strlen($anchor))),$node);$parent->removeChild($node);$changed=true;break;}if(!$changed)return self::error('links','Anchor does not occur in an unlinked text node.');$urls=array_values(array_diff($urls,[$url]));
  }
  $root=$xp->query('//div[@id="cbop-root"]')->item(0);$html='';foreach($root->childNodes as$child)$html.=$dom->saveHTML($child);
  return Comitement_ContentBridge_V1::operator_write($id,['idempotency_key'=>$key,'content'=>$html]);
 }
 public static function maintenance_state():array {
  global$wp_version;$state=['wordpress'=>$wp_version??'','active_plugins'=>(array)get_option('active_plugins',[]),'stylesheet'=>get_option('stylesheet',''),'template'=>get_option('template',''),'core'=>get_site_transient('update_core'),'plugins'=>get_site_transient('update_plugins'),'themes'=>get_site_transient('update_themes')];
  $state['available_updates']=Comitement_ContentBridge_Maintenance::offers();$state['version']=hash('sha256',wp_json_encode($state));$state['policy']=['cache_purge'=>'AUTO','healthcheck'=>'AUTO','known_plugin_update'=>'REVIEW','known_theme_update'=>'REVIEW','major_core_update'=>'OWNER','destructive'=>'OWNER'];
  $state['supported_actions']=['cache_purge','healthcheck','delete_unused_media','restore_media_from_trash','known_plugin_update','known_theme_update','rollback_update'];$state['media_deletion_policy']=['enabled'=>(bool)get_option('contentbridge_operator_allow_unused_media_trash',false),'mode'=>'native_trash_only','owner_approval_required'=>true];$state['backup_contract']=['required_for_updates'=>true,'required'=>['private_restorable_backup','exact_installed_version','canonical_update_adapter','homepage_central_urls_rest_errors_smoke','rollback_verification'],'available'=>true,'preflight_required'=>true,'limits'=>['database_bytes'=>67108864,'database_rows'=>500000,'database_tables'=>100,'component_bytes'=>157286400],'database_rollback'=>'Automatic restoration never overwrites business data; concurrent changes or migrations retain the private backup for explicit owner recovery.','core_adapter'=>'Existing website core updater requires its separate complete database rollback contract.'];return $state;
 }
 private static function maintenance(array $a,array $ctx=[]){
  $state=self::maintenance_state();if(!is_string($a['expected_version']??null)||!hash_equals($state['version'],$a['expected_version']))return self::error('stale_version','Fresh site maintenance version required.',409);
  $action=$a['action']??'';if(in_array($action,['known_plugin_update','known_theme_update','major_core_update','rollback_update'],true))return Comitement_ContentBridge_Maintenance::run($a,$state,$ctx);if($action==='delete_unused_media')return self::delete_unused_media($a,$state);if($action==='restore_media_from_trash')return self::restore_trashed_media($a);if(!in_array($action,['cache_purge','healthcheck'],true))return self::error('review_required','Update/deletion requires canonical deployment and proven private backup/rollback; no action performed.',409);
  $urls=$a['central_urls']??[];if(!is_array($urls)||!array_is_list($urls)||count($urls)>5)return self::error('maintenance_urls','At most five central URLs.');
  foreach($urls as$url)if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||wp_parse_url($url,PHP_URL_SCHEME)!=='https'||wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url('/'),PHP_URL_HOST)||wp_parse_url($url,PHP_URL_USER)||wp_parse_url($url,PHP_URL_PASS)||wp_parse_url($url,PHP_URL_PORT))return self::error('maintenance_urls','Central URLs must use this exact HTTPS site.');
  $rollback=['required'=>false,'reason'=>'Read-only healthcheck or bounded object cache invalidation; no content, database or code change.'];
  if($a['dry_run']??true)return self::ok(['status'=>'SUCCESS','dry_run'=>true,'action'=>$action,'policy'=>'AUTO','version'=>$state['version'],'plan'=>['homepage'=>home_url('/'),'central_urls'=>$urls,'rest'=>rest_url('/'),'errors'=>'HTTP response and visible PHP error inspection'],'rollback'=>$rollback,'verified'=>false]);
  if($action==='cache_purge'&&wp_cache_flush()!==true)return self::error('cache_purge_failed','Object cache invalidation failed; no success asserted.',500);
  $checks=[];foreach(array_unique(array_merge([home_url('/'),rest_url('/')],$urls))as$url){$res=wp_safe_remote_request($url,['method'=>'GET','timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576]);$code=is_wp_error($res)?0:(int)wp_remote_retrieve_response_code($res);$body=is_wp_error($res)?'':wp_remote_retrieve_body($res);$checks[]=['url'=>$url,'http'=>$code,'reachable'=>$code===200?'PASS':($code===0?'UNKNOWN':'FAIL'),'visible_php_errors'=>$code===0?'UNKNOWN':(preg_match('/(?:Fatal error|Parse error|Uncaught (?:Error|Exception)|Warning:.*(?: on line | in \/))/i',$body)?'FAIL':'PASS')];}
  $values=[];foreach($checks as$check){$values[]=$check['reachable'];$values[]=$check['visible_php_errors'];}$failed=in_array('FAIL',$values,true);$unknown=in_array('UNKNOWN',$values,true);$verified=!$failed&&!$unknown;
  return self::ok(['status'=>$failed?'FAILED':($unknown?'PARTIAL':'SUCCESS'),'action'=>$action,'dry_run'=>false,'verified'=>$verified,'smoke'=>$checks,'rollback'=>$rollback,'version'=>self::maintenance_state()['version'],'error_scope'=>'Visible HTTP errors only; server log inspection requires the website observer error adapter.']);
 }
 public static function attachment_version(int $id):string {
  $post=get_post($id);if(!$post||$post->post_type!=='attachment')return '';$file=get_attached_file($id);return hash('sha256',wp_json_encode(['post'=>(array)$post,'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true),'metadata'=>wp_get_attachment_metadata($id),'file_hash'=>is_string($file)&&is_file($file)?hash_file('sha256',$file):null]));
 }
 public static function unused_media_proven(int $id):bool {
  global$wpdb;if((int)get_post_field('post_parent',$id)>0||self::other_media_references($id,0))return false;
  $url=wp_get_attachment_url($id);if(!$url)return false;$stem=preg_replace('/\.[a-z0-9]+$/i','',$url);$numeric=(string)$id;
  $patterns=[$stem,'i:'.$id.';','s:'.strlen($numeric).':"'.$numeric.'"',':'.$id.',',':'.$id.'}',':"'.$id.'"'];$where=['option_value=%s'];$args=[$numeric];
  foreach($patterns as$pattern){$where[]='option_value LIKE %s';$args[]='%'.$wpdb->esc_like($pattern).'%';}
  $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE 'cbv1_%' AND option_name NOT LIKE 'cbop_%' AND option_name NOT LIKE 'cmod_%' AND (".implode(' OR ',$where).')',...$args));if($count===null||!empty($wpdb->last_error)||(int)$count>0)return false;
  $patterns=[$stem,$numeric];$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.ID<>%d AND (p.post_content LIKE %s OR m.meta_value=%s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s)",$id,'%'.$wpdb->esc_like($stem).'%', $numeric,'%'.$wpdb->esc_like('i:'.$id.';').'%', '%'.$wpdb->esc_like(':'.$id.',').'%', '%'.$wpdb->esc_like(':'.$id.'}').'%'));
  return $count!==null&&empty($wpdb->last_error)&&(int)$count===0;
 }
 private static function delete_unused_media(array $a,array $state){
  $id=$a['attachment_id']??0;if(!is_int($id)||$id<1||!wp_attachment_is_image($id))return self::error('invalid_media','Existing image attachment required.');
  $version=self::attachment_version($id);if(!is_string($a['expected_attachment_version']??null)||!hash_equals($version,$a['expected_attachment_version']))return self::error('stale_version','Fresh attachment_version from list_content_media required.',409);
  if(empty($state['media_deletion_policy']['enabled']))return self::error('policy_blocked','Site policy does not permit unused-media trash.',409);
  if(!defined('MEDIA_TRASH')||MEDIA_TRASH!==true||!defined('EMPTY_TRASH_DAYS')||EMPTY_TRASH_DAYS<1)return self::error('policy_blocked','A recoverable native WordPress media-trash policy is required; permanent deletion is excluded.',409);
  if(!self::unused_media_proven($id))return self::error('media_in_use','No safe proof of non-use; attachment is preserved.',409);
  if($a['dry_run']??true)return self::ok(['status'=>'SUCCESS','dry_run'=>true,'action'=>'delete_unused_media','policy'=>'OWNER','attachment_id'=>$id,'attachment_version'=>$version,'verified'=>false,'plan'=>'Move proven-unused media to native recoverable trash.']);
  if(empty($a['owner_approved']))return self::error('owner_required','Explicit approval of this exact attachment required.',409);
  $before=get_post($id);$beforeStatus=$before->post_status;$file=get_attached_file($id);$hash=is_string($file)&&is_file($file)?hash_file('sha256',$file):null;
  update_post_meta($id,'_contentbridge_operator_retained',['status'=>$beforeStatus,'file_hash'=>$hash,'trashed_at'=>gmdate('c')]);
  if(!wp_trash_post($id))return self::error('trash_failed','Native media trash failed.',500);
  $after=get_post($id);$verified=$after&&$after->post_status==='trash'&&($hash===null||(is_file($file)&&hash_file('sha256',$file)===$hash));
  return self::ok(['status'=>$verified?'SUCCESS':'PARTIAL','verified'=>$verified,'attachment_id'=>$id,'before_status'=>$beforeStatus,'status_after'=>$after->post_status??null,'rollback'=>['action'=>'restore_media_from_trash','attachment_id'=>$id,'expected_attachment_version'=>self::attachment_version($id),'retain_until'=>gmdate('c',time()+EMPTY_TRASH_DAYS*86400)],'remaining'=>$verified?[]:['Inspect native trash and file preservation.']]);
 }
 private static function restore_trashed_media(array $a){
  $id=$a['attachment_id']??0;$post=get_post($id);$retained=get_post_meta($id,'_contentbridge_operator_retained',true);
  if(!$post||$post->post_type!=='attachment'||$post->post_status!=='trash'||!is_array($retained)||!in_array($retained['status']??'',['inherit','draft','private'],true))return self::error('restore_blocked','Only operator-retained native media can be restored.');
  if(!is_string($a['expected_attachment_version']??null)||!hash_equals(self::attachment_version($id),$a['expected_attachment_version']))return self::error('stale_version','Fresh native attachment version required.',409);
  $file=get_attached_file($id);if(!empty($retained['file_hash'])&&(!is_string($file)||!is_file($file)||hash_file('sha256',$file)!==$retained['file_hash']))return self::error('restore_blocked','Retained file changed or disappeared.',409);
  if($a['dry_run']??true)return self::ok(['status'=>'SUCCESS','dry_run'=>true,'action'=>'restore_media_from_trash','attachment_id'=>$id,'verified'=>false]);
  if(empty($a['owner_approved']))return self::error('owner_required','Exact media restoration approval required.',409);
  if(!wp_untrash_post($id))return self::error('restore_failed','Native untrash failed.',500);
  $result=wp_update_post(['ID'=>$id,'post_status'=>$retained['status']],true);$fresh=get_post($id);$ok=!is_wp_error($result)&&$fresh&&$fresh->post_status===$retained['status'];
  if($ok)delete_post_meta($id,'_contentbridge_operator_retained');return self::ok(['status'=>$ok?'SUCCESS':'PARTIAL','verified'=>$ok,'attachment_id'=>$id,'status_after'=>$fresh->post_status??null,'attachment_version'=>self::attachment_version($id)]);
 }
 private static function http_status(string $url):int {$r=wp_safe_remote_request($url,['method'=>'GET','timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576]);return is_wp_error($r)?0:(int)wp_remote_retrieve_response_code($r);}
 public static function qa(int $id,array $a){
  $p=Comitement_ContentBridge_V1::read($id);if(is_wp_error($p))return $p;$checks=[];$remaining=[];$expected=$a['expected']??[];$profile=$a['profile']??'full';if(!in_array($profile,['full','content','seo'],true))return self::error('profile','Unknown QA profile.');
  if(!is_array($expected)||array_diff(array_keys($expected),['status','publish_at','author','categories','tags','title']))return self::error('expected','Unsupported expected fields.');
  foreach($expected as$k=>$v)$checks['expected_'.$k]=($p[$k]??null)===$v?'PASS':'FAIL';
  $checks['title']=trim($p['title'])!==''?'PASS':'FAIL';$checks['placeholders']=$p['placeholders_remaining']===0&&!$p['featured_image_pending']?'PASS':'FAIL';
  $media=self::media($id);foreach($media['inline_images']as$im){$f=$im['file']??null;$checks['inline_'.$im['index']]=$f?(!empty($f['missing'])||!empty($f['very_small'])||!empty($f['black_or_blank_suspected'])?'FAIL':'PASS'):'UNKNOWN';if($im['duplicate'])$checks['duplicate_inline_'.$im['index']]='FAIL';}
  if($p['featured_image']){$f=self::file_info($p['featured_image']['attachment_id']);$checks['featured']=!empty($f['missing'])||!empty($f['very_small'])||!empty($f['black_or_blank_suspected'])?'FAIL':'PASS';}else $checks['featured']='NOT_REQUIRED';
  $checks['seo_title']=$p['seo']['seo_title']!==''?'PASS':($profile==='content'?'NOT_REQUIRED':'UNKNOWN');$checks['meta_description']=$p['seo']['meta_description']!==''?'PASS':($profile==='content'?'NOT_REQUIRED':'UNKNOWN');
  if($p['status']==='publish'){
   $res=wp_safe_remote_request($p['url'],['method'=>'GET','timeout'=>15,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>2097152]);$status=is_wp_error($res)?0:(int)wp_remote_retrieve_response_code($res);$checks['reachable']=$status===200?'PASS':($status===0?'UNKNOWN':'FAIL');
   if($status===200){$html=wp_remote_retrieve_body($res);$dom=new DOMDocument();$prev=libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($prev);$xp=new DOMXPath($dom);$checks['h1']=$xp->query('//h1')->length===1?'PASS':'FAIL';$checks['rendered_title']=$xp->query('//title')->length===1?'PASS':'FAIL';$checks['rendered_meta']=$xp->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->length===1?'PASS':($profile==='content'&&$p['seo']['meta_description']===''?'NOT_REQUIRED':'UNKNOWN');
    $canon=$xp->query('//link[contains(@rel,"canonical")]/@href');$checks['canonical']=$canon->length===1&&untrailingslashit($canon->item(0)->nodeValue)===untrailingslashit($p['seo']['canonical']?:$p['url'])?'PASS':'UNKNOWN';
    $checks['structured_data']=$xp->query('//script[@type="application/ld+json"]')->length>0?'PASS':(!empty($a['expect_structured_data'])?'FAIL':'NOT_REQUIRED');
    foreach($media['inline_images']as$im)$checks['rendered_inline_'.$im['index']]=str_contains(html_entity_decode($html,ENT_QUOTES),$im['url'])?'PASS':'UNKNOWN';
    if($p['featured_image'])$checks['rendered_featured']=str_contains(html_entity_decode($html,ENT_QUOTES),$p['featured_image']['url'])?'PASS':'UNKNOWN';
    $checks['visible_shortcode']=preg_match('/\[(?:gallery|caption|vc_row|elementor-template|contact-form-7)\b/',strip_tags($html))?'FAIL':'PASS';
   }
  }else{$checks['rendered_page']='UNKNOWN';$remaining[]='Draft/scheduled content requires authenticated preview; public rendering not asserted.';}
  $visual=get_post_meta($id,'_contentbridge_operator_visual',true);
  if(is_array($visual)&&($visual['version']??'')===$p['version']&&($visual['expires_at']??0)>time()){
   $checks['visual_layout']=($visual['status']??'')==='SUCCESS'?'PASS':(($visual['status']??'')==='FAILED'?'FAIL':'UNKNOWN');
   if($p['status']!=='publish'){$checks['rendered_page']=$visual['review']['rendered_content'];$remaining=array_values(array_filter($remaining,fn($s)=>!str_contains($s,'authenticated preview')));}
  }else{$checks['visual_layout']='UNKNOWN';$remaining[]='Capture the version-bound private preview on desktop/mobile and explicitly review it before record_content_visual_qa. Pixel sampling alone is not visual evidence.';}
  if(!empty($a['check_links'])){$links=self::links($id,[]);$urls=array_unique($links['existing_links']??[]);$limit=10;foreach(array_slice($urls,0,$limit)as$i=>$url){$parsed=wp_parse_url($url);if(!is_array($parsed)||($parsed['scheme']??'')!=='https'){$checks['link_'.$i]='UNKNOWN';continue;}$status=self::http_status($url);$checks['link_'.$i]=$status>=200&&$status<400?'PASS':($status===0?'UNKNOWN':'FAIL');}if(count($urls)>$limit){$checks['link_coverage']='UNKNOWN';$remaining[]='Link check bounded to first 10 unique links; remaining links are not verified.';}}
  $failed=in_array('FAIL',$checks,true);$unknown=in_array('UNKNOWN',$checks,true);
  return self::ok(['post_id'=>$id,'version'=>$p['version'],'status'=>$failed?'FAILED':($unknown?'PARTIAL':'SUCCESS'),'passed'=>!$failed&&!$unknown,'profile'=>$profile,'checks'=>$checks,'remaining'=>$remaining,'links'=>[$p['url']]]);
 }
}

/** Native WordPress.org component updates, with private retained backups.
 * Repository-owned components continue through their Git Bridge release process.
 * Database backups are retained for recovery; concurrent/business database changes
 * prohibit automatic rollback. No database data is automatically overwritten.
 */
final class Comitement_ContentBridge_Maintenance {
 private static function error(string $code,string $message,int $status=409){return new WP_Error('contentbridge_'.$code,$message,['status'=>$status]);}
 private static function encode($value):string {return base64_encode(serialize($value));}
 private static function db_snapshot(?string $file=null){
  global $wpdb;
  if(!isset($wpdb->prefix)||!preg_match('/^[a-zA-Z0-9_]+$/D',$wpdb->prefix))return self::error('backup_unavailable','Native database prefix unavailable.');
  $tables=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'),ARRAY_A);
  if(!is_array($tables)||!$tables||count($tables)>100||!empty($wpdb->last_error))return self::error('backup_unavailable','Bounded native database table inventory required.');
  foreach($tables as$t)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t['Name']??'')||strtoupper($t['Engine']??'')!=='INNODB')return self::error('backup_unavailable','Consistent backup requires native InnoDB tables.');
  usort($tables,fn($a,$b)=>strcmp($a['Name'],$b['Name']));$handle=$file?fopen($file,'xb'):null;if($file&&!$handle)return self::error('backup_failed','Private database backup cannot be created.');
  if($handle)chmod($file,0600);$hash=hash_init('sha256');$bytes=0;$rows=0;
  if($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')===false){if($handle)fclose($handle);return self::error('backup_failed','Consistent native database snapshot unavailable.');}
  try {
   foreach($tables as$t){$name=$t['Name'];$columns=$wpdb->get_results('SHOW COLUMNS FROM `'.$name.'`',ARRAY_A);if(!is_array($columns)||!$columns||!empty($wpdb->last_error))throw new RuntimeException('schema');
    $order=[];foreach($columns as$col){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$col['Field']??''))throw new RuntimeException('column');if(($col['Key']??'')==='PRI')$order[]='`'.$col['Field'].'`';}
    if(!$order)throw new RuntimeException('primary_key');$schema=$wpdb->get_row('SHOW CREATE TABLE `'.$name.'`',ARRAY_A);if(!is_array($schema)||!empty($wpdb->last_error))throw new RuntimeException('schema');
    if($handle&&fwrite($handle,json_encode(['table'=>$name,'schema'=>self::encode($schema)])."\n")===false)throw new RuntimeException('write');$stableSchema=$schema;foreach($stableSchema as&$definition)if(is_string($definition))$definition=preg_replace('/ AUTO_INCREMENT=[0-9]+/','',$definition);unset($definition);hash_update($hash,$name.self::encode($stableSchema));
    for($offset=0;;$offset+=500){$batch=$wpdb->get_results('SELECT * FROM `'.$name.'` ORDER BY '.implode(',',$order).' LIMIT 500 OFFSET '.(int)$offset,ARRAY_A);if(!is_array($batch)||!empty($wpdb->last_error))throw new RuntimeException('read');
     foreach($batch as$row){$line=json_encode(['row'=>self::encode($row)])."\n";$bytes+=strlen($line);if(++$rows>500000||$bytes>67108864)throw new RuntimeException('limit');if($handle&&fwrite($handle,$line)!==strlen($line))throw new RuntimeException('write');
      // Only operational update/cache/ledger rows are excluded from the drift hash.
      $option=$row['option_name']??null;if($name===$wpdb->options&&is_string($option)&&(preg_match('/^(?:_transient_|_site_transient_|cbop_|cbv1_|cmod_)/D',$option)||in_array($option,['cron','auto_updater.lock','core_updater.lock','uninstall_plugins','recently_activated','contentbridge_operator_audit'],true)))continue;
      hash_update($hash,self::encode($row));
     }if(count($batch)<500)break;
    }
   }
   if($handle&&!fflush($handle))throw new RuntimeException('flush');
   return ['hash'=>hash_final($hash),'rows'=>$rows,'bytes'=>$bytes,'file_hash'=>$file?hash_file('sha256',$file):null];
  }catch(Throwable $e){return self::error('backup_failed','Native database backup/drift proof failed: '.$e->getMessage().'.');}
  finally{$wpdb->query('ROLLBACK');if($handle)fclose($handle);}
 }
 private static function manifest(string $root){
  if(!is_dir($root)||is_link($root))return self::error('backup_unavailable','Native component directory required.');$files=[];$bytes=0;
  try{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$f){if($f->isLink())return self::error('backup_unavailable','Symlink component cannot be backed up safely.');if(!$f->isFile())continue;$rel=substr($f->getPathname(),strlen($root)+1);$bytes+=$f->getSize();if(count($files)>=5000||$bytes>157286400)return self::error('backup_limit','Component backup exceeds bounded limits.');$h=hash_file('sha256',$f->getPathname());if(!$h)return self::error('backup_failed','Component file cannot be read.');$files[$rel]=$h;}}catch(Throwable $e){return self::error('backup_failed','Component inventory failed.');}ksort($files);return $files;
 }
 private static function copy(string $from,string $to,bool $private=true){
  $m=self::manifest($from);if(is_wp_error($m))return $m;if(!is_dir($to)&&!mkdir($to,$private?0700:0755,true))return self::error('backup_failed','Private backup directory unavailable.');
  foreach($m as$rel=>$hash){$dest=$to.'/'.$rel;if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),$private?0700:0755,true))return self::error('backup_failed','Backup subdirectory unavailable.');if(!copy($from.'/'.$rel,$dest)||hash_file('sha256',$dest)!==$hash)return self::error('backup_failed','Backup file did not verify.');chmod($dest,$private?0600:0644);}return self::manifest($to);
 }
 private static function component(array $a){
  $kind=($a['action']??'')==='known_theme_update'?'theme':'plugin';$key=$a['component']??'';$target=$a['target_version']??'';
  if(!is_string($key)||!preg_match('/^[a-z0-9][a-z0-9_-]*(?:\/[a-zA-Z0-9_.-]+\.php)?$/D',$key)||!is_string($target)||!preg_match('/^[0-9][0-9a-zA-Z.+_-]{0,63}$/D',$target))return self::error('invalid_component','Exact installed component and offered target version required.');
  if($kind==='plugin'){require_once ABSPATH.'wp-admin/includes/plugin.php';$all=get_plugins();if(!isset($all[$key])||!str_contains($key,'/'))return self::error('component_not_installed','Installed directory plugin required.');$slug=dirname($key);$version=$all[$key]['Version'];$active=is_plugin_active($key);$root=WP_PLUGIN_DIR.'/'.$slug;$updates=get_site_transient('update_plugins');$offer=$updates->response[$key]??null;}
  else{$theme=wp_get_theme($key);if(!$theme->exists())return self::error('component_not_installed','Installed theme required.');$slug=$key;$version=$theme->get('Version');$root=$theme->get_stylesheet_directory();$updates=get_site_transient('update_themes');$offer=$updates->response[$key]??null;}
  $offered=is_object($offer)?(array)$offer:$offer;$package=is_array($offered)?($offered['package']??''):'';$url=parse_url($package);
  if(!is_array($offered)||($offered['new_version']??'')!==$target||!version_compare($target,$version,'>')||!is_array($url)||($url['scheme']??'')!=='https'||($url['host']??'')!=='downloads.wordpress.org'||isset($url['query'])||isset($url['user'])||isset($url['port'])||($url['path']??'')!=='/'.$kind.'/'.$slug.'.'.$target.'.zip')return self::error('canonical_update_required','Exact current WordPress.org update required; repository components use their existing canonical release.');
  $real=realpath($root);if(!$real||is_link($root))return self::error('backup_unavailable','Native installed component path unavailable.');return ['kind'=>$kind,'key'=>$key,'slug'=>$slug,'before_version'=>$version,'target_version'=>$target,'root'=>$real,'active'=>$kind==='plugin'?$active:null];
 }
 public static function offers():array {
  if(!function_exists('get_plugins')&&is_file(ABSPATH.'wp-admin/includes/plugin.php'))require_once ABSPATH.'wp-admin/includes/plugin.php';
  $out=[];foreach(['plugin','theme']as$kind){$update=get_site_transient($kind==='plugin'?'update_plugins':'update_themes');foreach((array)(is_object($update)?($update->response??[]):[])as$key=>$offer){if(count($out)>=50)break 2;$v=is_object($offer)?(array)$offer:$offer;if(!is_array($v))continue;if($kind==='plugin'&&!function_exists('get_plugins'))continue;if($kind==='theme'&&!function_exists('wp_get_theme'))continue;$args=['action'=>$kind==='plugin'?'known_plugin_update':'known_theme_update','component'=>$key,'target_version'=>$v['new_version']??''];$c=self::component($args);if(is_wp_error($c))continue;$name=$kind==='plugin'?(get_plugins()[$key]['Name']??$key):wp_get_theme($key)->get('Name');$out[]=array_diff_key($c,['root'=>1])+['name'=>$name,'action'=>$args['action'],'policy'=>'REVIEW'];}}return $out;
 }
 private static function installed(array $c):string {if($c['kind']==='plugin'){wp_clean_plugins_cache(false);$all=get_plugins();return $all[$c['key']]['Version']??'';}wp_clean_themes_cache(false);return wp_get_theme($c['key'])->get('Version');}
 private static function smoke(array $urls):array {
  $checks=[];foreach(array_unique(array_merge([home_url('/'),rest_url('/')],$urls))as$url){$r=wp_safe_remote_request($url,['method'=>'GET','timeout'=>10,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576,'headers'=>['Cache-Control'=>'no-cache']]);$code=is_wp_error($r)?0:(int)wp_remote_retrieve_response_code($r);$body=is_wp_error($r)?'':wp_remote_retrieve_body($r);$checks[]=['url'=>$url,'http'=>$code,'reachable'=>$code===200?'PASS':($code?'FAIL':'UNKNOWN'),'visible_php_errors'=>$code?(preg_match('/Fatal error|Parse error|Uncaught (?:Error|Exception)|Warning:.* on line /i',$body)?'FAIL':'PASS'):'UNKNOWN'];}return $checks;
 }
 private static function smoke_pass(array $checks):bool {foreach($checks as$c)if($c['reachable']!=='PASS'||$c['visible_php_errors']!=='PASS')return false;return (bool)$checks;}
 private static function restore(array $backup){
  $c=$backup['component'];$manifest=self::manifest($backup['directory'].'/files');if(is_wp_error($manifest)||$manifest!==$backup['manifest'])return self::error('restore_blocked','Private component backup changed.');
  $current=is_dir($c['root'])?self::manifest($c['root']):[];if(is_link($c['root'])||is_wp_error($current))return self::error('restore_blocked','Installed component path cannot be restored safely.');
  foreach($current as$rel=>$hash)if(!isset($manifest[$rel])&&!unlink($c['root'].'/'.$rel))return self::error('restore_failed','New component file could not be removed.');$copied=self::copy($backup['directory'].'/files',$c['root'],false);
  // Native installed files must remain readable by WordPress; private backups stay 0600.
  if(!is_wp_error($copied))foreach($manifest as$rel=>$hash)chmod($c['root'].'/'.$rel,0644);
  return !is_wp_error($copied)&&$copied===$manifest&&self::installed($c)===$c['before_version'];
 }
 public static function run(array $a,array $state,array $ctx=[]){
  $urls=$a['central_urls']??[];if(!is_array($urls)||!array_is_list($urls)||count($urls)>5)return self::error('maintenance_urls','At most five central URLs.');foreach($urls as$url)if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_HOST)!==parse_url(home_url('/'),PHP_URL_HOST)||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_PASS)||parse_url($url,PHP_URL_PORT))return self::error('maintenance_urls','Exact HTTPS site smoke targets required.');
  if(($a['action']??'')==='major_core_update')return self::error('canonical_core_required','Major core update requires the existing website core adapter and its complete database rollback contract.');
  if(($a['action']??'')==='rollback_update')return self::rollback($a,$urls);
  if(is_file(ABSPATH.'.maintenance'))return self::error('update_in_progress','Existing native maintenance marker blocks concurrent update.');
  if(function_exists('is_multisite')&&is_multisite())return self::error('policy_blocked','Network component updates require their native network backup contract.');
  if(defined('DISALLOW_FILE_MODS')&&DISALLOW_FILE_MODS)return self::error('policy_blocked','Native site disallows component modifications.');
  $c=self::component($a);if(is_wp_error($c))return $c;$manifest=self::manifest($c['root']);if(is_wp_error($manifest))return $manifest;
  if($a['dry_run']??true)return ['ok'=>true,'status'=>'SUCCESS','dry_run'=>true,'verified'=>false,'policy'=>'REVIEW','component'=>array_diff_key($c,['root'=>1]),'plan'=>['private_file_and_consistent_database_backup','native_wordpress_upgrader','exact_version_reread','homepage_central_urls_rest_smoke','database_drift_check'],'rollback'=>['automatic'=>'Only if business database unchanged; never overwrite concurrent data.']];
  if(empty($a['owner_approved']))return self::error('owner_required','Approval of this exact installed component update required.');
  require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
  if(get_filesystem_method()!=='direct')return self::error('backup_unavailable','Native direct filesystem required; no credentials are fabricated.');
  $base=rtrim(sys_get_temp_dir(),'/').'/comitement-native-backups';$web=realpath(ABSPATH);if(str_starts_with($base.'/',rtrim($web?:ABSPATH,'/').'/')||is_link($base))return self::error('backup_unavailable','Private backup location must be outside the web root.');if(!is_dir($base)&&!mkdir($base,0700,true))return self::error('backup_unavailable','Private backup directory cannot be created.');if(!chmod($base,0700)||((fileperms($base)&0777)!==0700))return self::error('backup_unavailable','Private backup permissions did not verify.');
  $id=bin2hex(random_bytes(24));$dir=$base.'/'.$id;if(!mkdir($dir,0700))return self::error('backup_failed','Private backup creation failed.');$copy=self::copy($c['root'],$dir.'/files');if(is_wp_error($copy)||$copy!==$manifest)return self::error('backup_failed','Private component backup does not match installed files.');$db=self::db_snapshot($dir.'/database.snapshot');if(is_wp_error($db))return $db;
  $backup=['directory'=>$dir,'component'=>$c,'manifest'=>$manifest,'database'=>$db,'created_at'=>gmdate('c'),'actor'=>$ctx['actor']??0,'state'=>'prepared'];if(!add_option('cbop_update_'.$id,$backup,'','no'))return self::error('backup_failed','Private backup metadata could not be retained.');
  $freshComponent=self::component($a);$freshManifest=self::manifest($c['root']);if(is_wp_error($freshComponent)||$freshComponent!==$c||is_wp_error($freshManifest)||$freshManifest!==$manifest)return self::error('stale_version','Native update offer or installed files changed during backup.');
  $activeBefore=(array)get_option('active_plugins',[]);if(!WP_Filesystem())return self::error('backup_unavailable','Native filesystem connection unavailable; private backup retained.');$skin=new Automatic_Upgrader_Skin();$upgrader=$c['kind']==='plugin'?new Plugin_Upgrader($skin):new Theme_Upgrader($skin);$upgrader->maintenance_mode(true);if(!is_file(ABSPATH.'.maintenance'))return self::error('maintenance_failed','Native maintenance marker did not verify; update was not started.');ob_start();try{$result=$upgrader->upgrade($c['key']);if($c['kind']==='plugin'&&$c['active']&&!is_plugin_active($c['key'])&&!is_wp_error($result)&&$result!==false){$activation=activate_plugin($c['key']);if(is_wp_error($activation))$result=$activation;else{$activeAfter=(array)get_option('active_plugins',[]);$beforeSet=$activeBefore;$afterSet=$activeAfter;sort($beforeSet);sort($afterSet);if($beforeSet===$afterSet&&$activeBefore!==$activeAfter)update_option('active_plugins',$activeBefore);}}}catch(Throwable $e){$result=self::error('native_update_failed','Native WordPress upgrader failed.');}finally{ob_end_clean();$upgrader->maintenance_mode(false);}
  $version=self::installed($c);$after=self::db_snapshot();$smoke=self::smoke($urls);$unchanged=!is_wp_error($after)&&hash_equals($db['hash'],$after['hash']);$ok=!is_wp_error($result)&&$result!==false&&$version===$c['target_version']&&self::smoke_pass($smoke)&&$unchanged;
  $backup['after_database']=is_wp_error($after)?null:$after;$backup['installed_version']=$version;$backup['installed_manifest']=self::manifest($c['root']);$backup['state']=$ok?'verified':'partial';$restored=false;
  if(!$ok&&$unchanged){$restore=self::restore($backup);$restored=$restore===true;$backup['rollback_smoke']=$restored?self::smoke($urls):[];$backup['state']=$restored&&self::smoke_pass($backup['rollback_smoke'])?'rolled_back':'partial';}
  update_option('cbop_update_'.$id,$backup,false);
  return ['ok'=>true,'status'=>$ok?'SUCCESS':($backup['state']==='rolled_back'?'FAILED':'PARTIAL'),'verified'=>$ok,'dry_run'=>false,'policy'=>'REVIEW','component'=>array_diff_key($c,['root'=>1]),'installed_version'=>self::installed($c),'smoke'=>$smoke,'business_database_unchanged'=>$unchanged,'rollback'=>['backup_id'=>$id,'action'=>'rollback_update','restored'=>$backup['state']==='rolled_back','database_backup_retained'=>true,'verification'=>$backup['rollback_smoke']??[]],'remaining'=>$ok?[]:[$unchanged?'Inspect native updater and rollback evidence.':'Business database changed or could not be verified; private backup retained, automatic restoration prohibited.']];
 }
 private static function rollback(array $a,array $urls){
  $id=$a['backup_id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id))return self::error('restore_blocked','Exact retained backup ID required.');$backup=get_option('cbop_update_'.$id,[]);if(!is_array($backup)||!isset($backup['component'],$backup['after_database']['hash'])||($backup['state']??'')!=='verified')return self::error('restore_blocked','Verified operator update backup required.');
  if(is_wp_error($backup['installed_manifest']??null)||!isset($backup['installed_manifest'])||self::manifest($backup['component']['root'])!==$backup['installed_manifest']||self::installed($backup['component'])!==$backup['installed_version'])return self::error('stale_version','Installed component changed after update.');$db=self::db_snapshot();if(is_wp_error($db)||!hash_equals($backup['database']['hash'],$db['hash'])||!hash_equals($backup['after_database']['hash'],$db['hash']))return self::error('restore_blocked','Business database drift prohibits automatic rollback.');
  if($a['dry_run']??true)return ['ok'=>true,'status'=>'SUCCESS','dry_run'=>true,'verified'=>false,'policy'=>'OWNER','backup_id'=>$id];if(empty($a['owner_approved']))return self::error('owner_required','Exact retained update rollback approval required.');$r=self::restore($backup);$smoke=$r===true?self::smoke($urls):[];$fresh=self::db_snapshot();$ok=$r===true&&self::smoke_pass($smoke)&&!is_wp_error($fresh)&&hash_equals($db['hash'],$fresh['hash']);$backup['state']=$ok?'rolled_back':'partial';update_option('cbop_update_'.$id,$backup,false);return ['ok'=>true,'status'=>$ok?'SUCCESS':'PARTIAL','verified'=>$ok,'backup_id'=>$id,'smoke'=>$smoke,'installed_version'=>self::installed($backup['component'])];
 }
}
