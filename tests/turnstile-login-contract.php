<?php
$src=file_get_contents(__DIR__.'/../plugins/cm91-content-bridge/turnstile-login.php');
foreach(['siteverify','authenticate','lostpassword_post','registration_errors','hostname','cf-turnstile-response','manage_options'] as $needle){
 if(strpos($src,$needle)===false){fwrite(STDERR,"missing $needle\n");exit(1);}
}
echo "TURNSTILE_LOGIN_CONTRACT_OK=yes\n";
