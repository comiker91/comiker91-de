<?php
/**
 * Plugin Name: CM91 Git Deployer
 * Description: Secure signed GitHub deployment bridge for comiker91.de.
 * Version: 1.0.1
 * Author: comiker91
 */

if (!defined('ABSPATH')) exit;

final class CM91_Git_Deployer {
    const OPTION_SECRET = 'cm91_git_deployer_secret';
    const OPTION_LAST   = 'cm91_git_deployer_last_deploy';
    const ROUTE_NS      = 'cm91-deploy/v1';
    const MAX_BYTES     = 52428800;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_cm91_git_deployer_rotate', [__CLASS__, 'rotate_secret']);
    }

    public static function activate() {
        if (!get_option(self::OPTION_SECRET)) update_option(self::OPTION_SECRET, wp_generate_password(64, false, false), false);
    }

    private static function secret() {
        if (defined('CM91_DEPLOY_SECRET') && CM91_DEPLOY_SECRET) return (string) CM91_DEPLOY_SECRET;
        return (string) get_option(self::OPTION_SECRET, '');
    }

    public static function routes() {
        register_rest_route(self::ROUTE_NS, '/push', ['methods'=>'POST','callback'=>[__CLASS__,'deploy'],'permission_callback'=>'__return_true']);
        register_rest_route(self::ROUTE_NS, '/status', ['methods'=>'GET','callback'=>[__CLASS__,'status'],'permission_callback'=>'__return_true']);
    }

    public static function status() {
        return new WP_REST_Response(['ok'=>true,'configured'=>self::secret()!=='','last_deploy'=>get_option(self::OPTION_LAST,null)],200);
    }

    public static function deploy(WP_REST_Request $request) {
        $secret=self::secret();
        if($secret==='')return new WP_Error('cm91_not_configured','Deployment secret is not configured.',['status'=>503]);
        $files=$request->get_file_params();
        if(empty($files['package']['tmp_name'])||!is_uploaded_file($files['package']['tmp_name']))return new WP_Error('cm91_missing_package','No deployment package received.',['status'=>400]);
        $file=$files['package'];
        if(!empty($file['size'])&&(int)$file['size']>self::MAX_BYTES)return new WP_Error('cm91_too_large','Deployment package exceeds 50 MB.',['status'=>413]);
        $tmp=$file['tmp_name'];
        $signature=(string)$request->get_header('x-cm91-deploy-signature');
        $expected='sha256='.hash_hmac('sha256',file_get_contents($tmp),$secret);
        if(!$signature||!hash_equals($expected,$signature))return new WP_Error('cm91_bad_signature','Invalid deployment signature.',['status'=>401]);
        if(!class_exists('ZipArchive'))return new WP_Error('cm91_zip_missing','PHP ZipArchive is not available.',['status'=>500]);

        $commit=sanitize_text_field((string)$request->get_header('x-cm91-commit'))?:'unknown-'.gmdate('YmdHis');
        $content_root=trailingslashit(WP_CONTENT_DIR);
        $stage_root=$content_root.'.cm91-deploy-staging/';
        $backup_root=$content_root.'.cm91-deploy-backups/';
        wp_mkdir_p($stage_root); wp_mkdir_p($backup_root);
        $stage=$stage_root.'deploy-'.gmdate('Ymd-His').'-'.wp_generate_password(8,false,false).'/'; wp_mkdir_p($stage);

        $zip=new ZipArchive();
        if($zip->open($tmp)!==true){self::rrmdir($stage);return new WP_Error('cm91_bad_zip','Deployment package is not a valid ZIP archive.',['status'=>400]);}
        for($i=0;$i<$zip->numFiles;$i++){
            $name=str_replace('\\','/',(string)$zip->getNameIndex($i));
            if($name===''||str_starts_with($name,'/')||preg_match('#(^|/)\.\.(/|$)#',$name)||strpos($name,"\0")!==false){$zip->close();self::rrmdir($stage);return new WP_Error('cm91_unsafe_zip','Unsafe path found in deployment package.',['status'=>400]);}
            $top=strtok($name,'/');
            if(!in_array($top,['plugins','themes'],true)){$zip->close();self::rrmdir($stage);return new WP_Error('cm91_unexpected_path','Only plugins/ and themes/ are allowed.',['status'=>400]);}
        }
        if(!$zip->extractTo($stage)){$zip->close();self::rrmdir($stage);return new WP_Error('cm91_extract_failed','Could not extract deployment package.',['status'=>500]);}
        $zip->close();

        $items=[];
        foreach(['plugins'=>[trailingslashit($stage.'plugins'),trailingslashit(WP_PLUGIN_DIR)],'themes'=>[trailingslashit($stage.'themes'),trailingslashit(get_theme_root())]] as$type=>$paths){
            [$source_base,$target_base]=$paths;
            if(!is_dir($source_base))continue;
            foreach(glob($source_base.'*',GLOB_ONLYDIR)?:[] as$source){
                $name=basename($source);
                if(!preg_match('/^[A-Za-z0-9._-]+$/',$name)){self::rrmdir($stage);return new WP_Error('cm91_bad_name','Invalid directory name: '.$name,['status'=>400]);}
                $items[]=['type'=>$type,'name'=>$name,'source'=>$source,'target'=>$target_base.$name];
            }
        }
        if(!$items){self::rrmdir($stage);return new WP_Error('cm91_empty','Deployment package contained no managed directories.',['status'=>400]);}

        $backup=$backup_root.preg_replace('/[^A-Za-z0-9._-]/','-',$commit).'-'.gmdate('Ymd-His').'/'; wp_mkdir_p($backup);
        $changed=[];
        try{
            foreach($items as&$item){
                $item['backup']=$backup.$item['type'].'/'.$item['name'];
                $item['had_target']=is_dir($item['target']);
                if($item['had_target']){wp_mkdir_p(dirname($item['backup']));if(!self::move_dir($item['target'],$item['backup']))throw new RuntimeException('Could not back up '.$item['name']);}
            }
            unset($item);
            foreach($items as$item){if(!self::move_dir($item['source'],$item['target']))throw new RuntimeException('Could not install '.$item['name']);$changed[]=$item['type'].'/'.$item['name'];}

            // Managed infrastructure plugins may bootstrap themselves after their first Git deploy.
            $content_bridge='cm91-content-bridge/cm91-content-bridge.php';
            if(is_file(WP_PLUGIN_DIR.'/'.$content_bridge)){
                require_once ABSPATH.'wp-admin/includes/plugin.php';
                if(!is_plugin_active($content_bridge)){
                    $activated=activate_plugin($content_bridge,'',false,false);
                    if(is_wp_error($activated))throw new RuntimeException('Could not activate CM91 Content Bridge: '.$activated->get_error_message());
                }
            }
        }catch(Throwable $e){
            foreach(array_reverse($items)as$item){if(is_dir($item['target']))self::rrmdir($item['target']);if(!empty($item['had_target'])&&is_dir($item['backup']))self::move_dir($item['backup'],$item['target']);}
            self::rrmdir($stage);return new WP_Error('cm91_deploy_failed',$e->getMessage(),['status'=>500]);
        }

        self::rrmdir($stage); self::prune_backups($backup_root,5);
        if(function_exists('opcache_reset'))@opcache_reset();
        if(function_exists('wp_clean_plugins_cache'))wp_clean_plugins_cache(true);
        if(function_exists('wp_clean_themes_cache'))wp_clean_themes_cache(true);
        $result=['commit'=>$commit,'deployed_at'=>current_time('mysql',true),'items'=>$changed];
        update_option(self::OPTION_LAST,$result,false);
        return new WP_REST_Response(['ok'=>true]+$result,200);
    }

    private static function move_dir($from,$to){if(!is_dir($from))return false;wp_mkdir_p(dirname($to));if(@rename($from,$to))return true;if(!self::copy_dir($from,$to))return false;self::rrmdir($from);return true;}
    private static function copy_dir($from,$to){if(!wp_mkdir_p($to)&&!is_dir($to))return false;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as$item){$relative=substr($item->getPathname(),strlen($from)+1);$dest=$to.'/'.$relative;if($item->isDir()){if(!wp_mkdir_p($dest)&&!is_dir($dest))return false;}else{wp_mkdir_p(dirname($dest));if(!@copy($item->getPathname(),$dest))return false;}}return true;}
    private static function rrmdir($dir){if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$item)$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());@rmdir($dir);}
    private static function prune_backups($root,$keep){$dirs=glob(trailingslashit($root).'*',GLOB_ONLYDIR)?:[];usort($dirs,static fn($a,$b)=>filemtime($b)<=>filemtime($a));foreach(array_slice($dirs,$keep)as$dir)self::rrmdir($dir);}

    public static function admin_menu(){add_management_page('CM91 Git Deploy','CM91 Git Deploy','manage_options','cm91-git-deployer',[__CLASS__,'admin_page']);}
    public static function rotate_secret(){if(!current_user_can('manage_options'))wp_die('Not allowed.');check_admin_referer('cm91_git_deployer_rotate');update_option(self::OPTION_SECRET,wp_generate_password(64,false,false),false);wp_safe_redirect(admin_url('tools.php?page=cm91-git-deployer&rotated=1'));exit;}
    public static function admin_page(){if(!current_user_can('manage_options'))return;$secret=self::secret();$last=get_option(self::OPTION_LAST,[]);?>
        <div class="wrap"><h1>CM91 Git Deploy</h1><p>GitHub-Deployments für verwaltete Plugins und Themes auf comiker91.de.</p><table class="widefat striped" style="max-width:900px"><tbody>
        <tr><th style="width:180px">Deploy endpoint</th><td><code><?php echo esc_html(rest_url(self::ROUTE_NS.'/push')); ?></code></td></tr>
        <tr><th>GitHub secret</th><td><code style="word-break:break-all"><?php echo esc_html($secret); ?></code><p class="description">Als Repository Secret <code>CM91_DEPLOY_SECRET</code> speichern.</p></td></tr>
        <tr><th>Last deploy</th><td><?php echo $last?esc_html(($last['commit']??'unknown').' @ '.($last['deployed_at']??'')):'Noch kein Deployment empfangen.'; ?></td></tr>
        </tbody></table><p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cm91_git_deployer_rotate'),'cm91_git_deployer_rotate')); ?>" onclick="return confirm('Secret rotieren? Danach GitHub Secret aktualisieren.');">Secret rotieren</a></p></div>
    <?php }
}
register_activation_hook(__FILE__,['CM91_Git_Deployer','activate']);
CM91_Git_Deployer::init();
