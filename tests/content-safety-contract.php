<?php
function allowed_existing_status($status){ return $status === 'draft'; }
function safe_zip_entry($name){ $name=str_replace('\\','/',$name); return !($name==='' || str_starts_with($name,'/') || preg_match('#(^|/)\.\.(/|$)#',$name) || strpos($name,"\0")!==false); }
$cases=['draft'=>true,'publish'=>false,'future'=>false,'pending'=>false,'private'=>false,'trash'=>false,'auto-draft'=>false,'inherit'=>false];
foreach($cases as $status=>$expected){ if(allowed_existing_status($status)!==$expected){fwrite(STDERR,"FAIL status $status\n");exit(1);} }
$zipCases=['article/manifest.json'=>true,'article/article.html'=>true,'../wp-config.php'=>false,'article/../../evil'=>false,'/absolute'=>false,"evil\0name"=>false];
foreach($zipCases as $name=>$expected){ if(safe_zip_entry($name)!==$expected){fwrite(STDERR,"FAIL zip $name\n");exit(1);} }
$valid=['article-001','old_article_2','abc123']; foreach($valid as $s) if(!preg_match('/^[a-z0-9_-]+$/',$s)){fwrite(STDERR,"FAIL source $s\n");exit(1);}
$invalid=['Article 1','../evil','äbc','article/1']; foreach($invalid as $s) if(preg_match('/^[a-z0-9_-]+$/',$s)){fwrite(STDERR,"FAIL invalid source accepted $s\n");exit(1);}
echo "OK draft-only status matrix\nOK zip traversal matrix\nOK source_id contract\n";
