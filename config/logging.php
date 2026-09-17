<?php
use Monolog\Handler\StreamHandler;
return ['default'=>env('LOG_CHANNEL','stack'),'channels'=>['stack'=>['driver'=>'stack','channels'=>['single'],'ignore_exceptions'=>false],'single'=>['driver'=>'monolog','handler'=>StreamHandler::class,'with'=>['stream'=>storage_path('logs/laravel.log')],'level'=>env('LOG_LEVEL','debug')]]];
