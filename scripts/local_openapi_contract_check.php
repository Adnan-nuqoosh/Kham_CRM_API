<?php
/** Static local OpenAPI route coverage smoke check; no database or HTTP requests. */
declare(strict_types=1);
$root=dirname(__DIR__);
$yaml=file_get_contents($root.'/docs/openapi.yaml');
$routes=file_get_contents($root.'/routes/api.php');
if($yaml===false||$routes===false){fwrite(STDERR,"FAIL: missing documentation or routes\n");exit(1);}
$checks=[
 ['GET','/api/v1/storefront/homepage',"Route::get('/storefront/homepage'"],
 ['GET','/api/v1/homepage/sections',"Route::get('/homepage/sections'"],
 ['POST','/api/v1/homepage/sections',"Route::post('/homepage/sections'"],
 ['PATCH','/api/v1/homepage/sections/{section}',"Route::patch('/homepage/sections/{section}'"],
 ['DELETE','/api/v1/homepage/sections/{section}',"Route::delete('/homepage/sections/{section}'"],
 ['POST','/api/v1/storefront/partners/apply',"Route::post('/storefront/partners/apply'"],
 ['GET','/api/v1/partner-applications',"Route::get('/partner-applications'"],
 ['GET','/api/v1/partner-applications/{application}',"Route::get('/partner-applications/{application}'"],
 ['PATCH','/api/v1/partner-applications/{application}',"Route::patch('/partner-applications/{application}'"],
 ['POST','/api/v1/storefront/newsletter/subscribe',"Route::post('/storefront/newsletter/subscribe'"],
];
$failures=0;
foreach($checks as [$method,$path,$needle]){
 $present=str_contains($routes,$needle);
 $pathLine='  '.$path.':';
 $start=strpos($yaml,$pathLine);
 $next=$start===false?false:strpos($yaml,"\n  /api/v1/",$start+strlen($pathLine));
 $block=$start===false?'':substr($yaml,$start,($next===false?strlen($yaml):$next)-$start);
 $documented=preg_match('/^    '.strtolower($method).':/m',$block)===1;
 if($present&&$documented)echo "PASS: {$method} {$path}\n";
 else{$failures++;echo "FAIL: {$method} {$path} route=".($present?'yes':'no')." docs=".($documented?'yes':'no')."\n";}
}
if($failures){fwrite(STDERR,"FAIL: {$failures} contract route checks failed\n");exit(1);}
echo "ALL 10 OPENAPI ROUTE/METHOD SMOKE CHECKS PASSED\n";
