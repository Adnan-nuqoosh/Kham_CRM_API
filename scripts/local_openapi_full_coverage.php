<?php
/** Local static OpenAPI inventory check; no database writes. */
declare(strict_types=1);
$root=dirname(__DIR__);
$yaml=file_get_contents($root.'/docs/openapi.yaml');
if($yaml===false){fwrite(STDERR,"FAIL: docs/openapi.yaml missing\n");exit(1);}
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/artisan').' route:list --json --path=api/v1 2>&1';
exec($command,$output,$exit);
$routes=json_decode(implode("\n",$output),true);
if($exit!==0||!is_array($routes)){fwrite(STDERR,"FAIL: Laravel route:list unavailable\n".implode("\n",$output)."\n");exit(2);}
$blocks=preg_split('/(?=^  \/api\/v1\/)/m',substr($yaml,0,strpos($yaml,"\ncomponents:")?:strlen($yaml)));
$docs=[];$duplicates=0;
foreach($blocks as $block){
 if(!preg_match('/^  (\/api\/v1\/[^\n:]+):/',$block,$m))continue;
 $path=$m[1];
 foreach(['get','post','put','patch','delete'] as $method){
  if(preg_match('/^    '.$method.':/m',$block)){
   $key=strtoupper($method).' '.$path;
   if(isset($docs[$key]))$duplicates++;
   $docs[$key]=true;
  }
 }
}
$expected=[];
foreach($routes as $route){
 $uri='/'.ltrim($route['uri'],'/');
 foreach(explode('|',$route['method']) as $method){
  if($method==='HEAD')continue;
  $expected[$method.' '.$uri]=true;
 }
}
$missing=array_diff_key($expected,$docs);
$extra=array_diff_key($docs,$expected);
foreach($missing as $key=>$_)echo "MISSING: {$key}\n";
foreach($extra as $key=>$_)echo "EXTRA: {$key}\n";
if($duplicates)echo "DUPLICATE operations: {$duplicates}\n";
printf("Routes: %d | Documented operations: %d | Missing: %d | Extra: %d\n",count($expected),count($docs),count($missing),count($extra));
if($missing||$extra||$duplicates)exit(1);
echo "PASS: ALL REGISTERED API ROUTE/METHOD OPERATIONS INVENTORIED\n";
