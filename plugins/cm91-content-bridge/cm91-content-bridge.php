<?php
/**
 * Plugin Name: CM91 Content Bridge
 * Description: Signed Git content drafts plus private Comitement fleet observer.
 * Version: 0.3.0
 * Author: comiker91
 */
if(!defined('ABSPATH')) exit;
define('COMITEMENT_OBSERVER_SITE_ID','comiker91');
define('COMITEMENT_OBSERVER_SITE_NAME','comiker91.de');
define('COMITEMENT_OBSERVER_TOKEN_CONSTANTS',['CM91_CONTENT_SECRET','CM91_DEPLOY_SECRET']);
define('COMITEMENT_OBSERVER_TOKEN_OPTIONS',['cm91_git_deployer_secret']);
require_once __DIR__.'/content-bridge-legacy.php';
require_once __DIR__.'/comitement-observer.php';

require_once __DIR__.'/turnstile-login.php';
require_once __DIR__.'/contentbridge-v1-loader.php';

function cm91_contentbridge_admin_secret(){
 if(defined('CM91_CONTENT_SECRET')&&CM91_CONTENT_SECRET)return(string)CM91_CONTENT_SECRET;
 if(defined('CM91_DEPLOY_SECRET')&&CM91_DEPLOY_SECRET)return(string)CM91_DEPLOY_SECRET;
 return(string)get_option('cm91_git_deployer_secret','');
}
add_action('admin_menu',function(){add_management_page('ContentBridge Zugang','ContentBridge Zugang','manage_options','cm91-contentbridge-access','cm91_contentbridge_access_page');});
function cm91_contentbridge_access_page(){
 if(!current_user_can('manage_options'))return;
 $reveal=isset($_POST['cm91_contentbridge_reveal'])&&check_admin_referer('cm91_contentbridge_reveal');
 echo '<div class="wrap"><h1>ContentBridge Zugang</h1><p>Der bestehende Content-Schlüssel wird nur nach expliziter Freigabe angezeigt. Dies ist derselbe Schlüssel, den der ContentBridge-v1-Adapter bereits verwendet.</p>';
 if($reveal){$secret=cm91_contentbridge_admin_secret();if($secret==='')echo '<div class="notice notice-error"><p>Kein Content-Schlüssel konfiguriert.</p></div>';else echo '<p><label><strong>Content Secret</strong></label><br><input type="text" readonly style="width:min(760px,100%)" value="'.esc_attr($secret).'" onclick="this.select()"></p><p class="description">Nur in Comitement Fleet &gt; ContentBridge koppeln übernehmen. Nicht in Logs, Git, Tickets oder Chats kopieren.</p>';}
 echo '<form method="post">';wp_nonce_field('cm91_contentbridge_reveal');echo '<p><button class="button button-primary" name="cm91_contentbridge_reveal" value="1">Content-Schlüssel anzeigen</button></p></form></div>';
}
