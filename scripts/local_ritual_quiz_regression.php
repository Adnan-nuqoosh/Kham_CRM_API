<?php
/** Local-only transaction rollback regression. Requires approved quiz migration. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
    ||!in_array($host,['localhost','127.0.0.1'],true)
    ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
    fwrite(STDERR,"STOP: local kham_crm_api only.\n");exit(2);
}
foreach(['ritual_quiz_questions','ritual_quiz_options','ritual_quiz_option_product'] as $table){
    if(!Schema::hasTable($table)){fwrite(STDERR,"STOP: approved quiz migration required.\n");exit(2);}
}
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $method,string $path,array $data=[])use($kernel):array{
    $request=Request::create('/api/v1/'.$path,$method,$data,[],[],['HTTP_ACCEPT'=>'application/json']);
    $response=$kernel->handle($request);
    try{return [$response->getStatusCode(),json_decode($response->getContent(),true)];}
    finally{$kernel->terminate($request,$response);}
};
$check=static function(bool $ok,string $name):void{
    if(!$ok)throw new RuntimeException("FAIL: ".$name);
    echo "PASS: ".$name."\n";
};
DB::beginTransaction();
try{
    $question=\App\Models\RitualQuizQuestion::create([
        'question'=>'Skin type?','question_ar'=>'نوع البشرة؟','is_active'=>true,
    ]);
    $option=$question->options()->create(['label'=>'Dry','label_ar'=>'جافة','is_active'=>true]);
    [$status,$ar]=$call('GET','storefront/ritual-quiz/questions?locale=ar');
    $row=collect($ar['data']??[])->firstWhere('id',$question->id);
    $check($status===200&&($row['question']??null)==='نوع البشرة؟'
        &&($row['options'][0]['label']??null)==='جافة','Arabic question and option localized');
    $question->update(['question_ar'=>null]);
    $option->update(['label_ar'=>null]);
    [$status,$fallback]=$call('GET','storefront/ritual-quiz/questions?locale=ar');
    $row=collect($fallback['data']??[])->firstWhere('id',$question->id);
    $check($status===200&&($row['question']??null)==='Skin type?'
        &&($row['options'][0]['label']??null)==='Dry','Arabic fallback');
    [$status]=$call('POST','storefront/ritual-quiz/recommendations',['market_id'=>99999999,'option_ids'=>[$option->id]]);
    $check($status===422,'invalid market rejected');
    [$status]=$call('POST','storefront/ritual-quiz/recommendations',['option_ids'=>[$option->id]]);
    $check($status===422,'missing market rejected');
    $question->update(['is_active'=>false]);
    [$status,$hidden]=$call('GET','storefront/ritual-quiz/questions');
    $check($status===200&&!collect($hidden['data']??[])->contains('id',$question->id),'inactive question hidden');
    echo "ALL RITUAL QUIZ CHECKS PASSED (rolled back)\n";
}finally{DB::rollBack();}
