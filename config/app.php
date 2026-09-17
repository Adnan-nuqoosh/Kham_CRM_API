<?php
return ['name'=>env('APP_NAME','KHAM Commerce API'),'env'=>env('APP_ENV','production'),'debug'=>(bool)env('APP_DEBUG',false),'url'=>env('APP_URL','http://localhost'),'timezone'=>'UTC','locale'=>env('APP_LOCALE','en'),'fallback_locale'=>env('APP_FALLBACK_LOCALE','en'),'cipher'=>'AES-256-CBC','key'=>env('APP_KEY')];
