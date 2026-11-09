<?php
/** Offline OpenAPI structural guard. No database or network calls. */
declare(strict_types=1);
$yaml=file_get_contents(dirname(__DIR__).'/docs/openapi.yaml');
if($yaml===false){fwrite(STDERR,"FAIL: missing docs/openapi.yaml\n");exit(1);}
$errors=[];
if(!preg_match('/^openapi: 3\.0\.\d+$/m',$yaml))$errors[]='Expected OpenAPI 3.0.x declaration';
if(!preg_match('/^paths:$/m',$yaml)||!preg_match('/^components:$/m',$yaml))$errors[]='Missing paths or components';
$before=explode("\ncomponents:",$yaml,2)[0];
$pathCount=[];$operations=[];$placeholderCount=0;
$chunks=preg_split('/(?=^  \/api\/v1\/)/m',$before);
foreach($chunks as $chunk){
 if(!preg_match('/^  (\/api\/v1\/[^\n:]+):/',$chunk,$m))continue;
 $path=$m[1];$pathCount[$path]=($pathCount[$path]??0)+1;
 foreach(['get','post','put','patch','delete'] as $method){
  if(!preg_match('/^    '.$method.':\s*$/m',$chunk))continue;
  $key=strtoupper($method).' '.$path;
  $operations[$key]=($operations[$key]??0)+1;
  if(!preg_match('/^      responses:\s*$/m',$chunk))$errors[]="Missing responses: {$key}";
 }
 $placeholderCount+=substr_count($chunk,'x-documentation-status: route-inventory-only');
}
foreach($pathCount as $path=>$n)if($n!==1)$errors[]="Duplicate path: {$path}";
foreach($operations as $key=>$n)if($n!==1)$errors[]="Duplicate operation: {$key}";
preg_match_all('/\$ref:\s*[\x27"]?#\/components\/(schemas|responses|securitySchemes)\/([A-Za-z0-9_.-]+)/',$yaml,$matches,PREG_SET_ORDER);
foreach($matches as $ref){
 if(!preg_match('/^    '.preg_quote($ref[2],'/').':\s*$/m',explode("\n  ".$ref[1].":",$yaml,2)[1]??''))$errors[]="Unresolved reference: {$ref[0]}";
}
printf("OpenAPI paths: %d | Operations: %d | Inventory-only operations: %d\n",count($pathCount),count($operations),$placeholderCount);
foreach($errors as $error)fwrite(STDERR,"FAIL: {$error}\n");
if($errors)exit(1);
echo "PASS: OPENAPI STRUCTURAL GUARDS\n";
echo "NOTE: This is not a complete YAML/OpenAPI schema validator.\n";
