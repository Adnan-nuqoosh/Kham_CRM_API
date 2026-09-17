<?php
use Illuminate\Support\Facades\Route;
Route::get('/',fn()=>response()->json(['name'=>'KHAM Commerce API','version'=>'v1','status'=>'ok']));
