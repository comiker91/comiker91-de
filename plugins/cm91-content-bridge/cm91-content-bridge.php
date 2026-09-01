<?php
/**
 * Plugin Name: CM91 Content Bridge
 * Description: Signed Git content drafts plus private Comitement fleet observer.
 * Version: 0.2.0
 * Author: comiker91
 */
if(!defined('ABSPATH')) exit;
define('COMITEMENT_OBSERVER_SITE_ID','comiker91');
define('COMITEMENT_OBSERVER_SITE_NAME','comiker91.de');
define('COMITEMENT_OBSERVER_TOKEN_CONSTANTS',['CM91_CONTENT_SECRET','CM91_DEPLOY_SECRET']);
define('COMITEMENT_OBSERVER_TOKEN_OPTIONS',['cm91_git_deployer_secret']);
require_once __DIR__.'/content-bridge-legacy.php';
require_once __DIR__.'/comitement-observer.php';
