<?php
/**
 * Read-only route/method coverage check against canonical docs/openapi.json.
 * This verifies inventory, NOT request/response schema correctness.
 */
declare(strict_types=1);
$root=dirname(__DIR__);
$specPath=$root.'/docs/openapi.json';
$raw=@file_get_contents($specPath);
if($raw===false){fwrite(STDERR,"FAIL: docs/openapi.json missing\n");exit(1);}
try{$spec=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
catch(JsonException $e){fwrite(STDERR,"FAIL: invalid OpenAPI JSON: ".$e->getMessage()."\n");exit(1);}
if(!is_array($spec)||!isset($spec['paths'])||!is_array($spec['paths'])){
    fwrite(STDERR,"FAIL: spec.paths missing\n");exit(1);
}
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/artisan').' route:list --json --path=api/v1 2>&1';
exec($command,$output,$exit);
$routes=json_decode(implode("\n",$output),true);
if($exit!==0||!is_array($routes)){
    fwrite(STDERR,"FAIL: Laravel route:list unavailable\n".implode("\n",$output)."\n");
    exit(2);
}
$methods=['get','post','put','patch','delete','options','head'];
$docs=[];
foreach($spec['paths'] as $path=>$operations){
    if(!is_array($operations))continue;
    foreach($methods as $method){
        if(!isset($operations[$method]))continue;
        $key=strtoupper($method).' '.$path;
        $docs[$key]=true;
    }
}
$expected=[];
foreach($routes as $route){
    $uri='/'.ltrim((string)$route['uri'],'/');
    foreach(explode('|',(string)$route['method']) as $method){
        if($method==='HEAD')continue;
        $expected[$method.' '.$uri]=true;
    }
}
$missing=array_diff_key($expected,$docs);
$extra=array_diff_key($docs,$expected);
foreach($missing as $key=>$_)echo "MISSING: {$key}\n";
foreach($extra as $key=>$_)echo "EXTRA: {$key}\n";
printf("Routes: %d | Documented operations: %d | Missing: %d | Extra: %d\n",
    count($expected),count($docs),count($missing),count($extra));
if($missing||$extra)exit(1);
echo "PASS: ALL REGISTERED API ROUTE/METHOD OPERATIONS INVENTORIED\n";
