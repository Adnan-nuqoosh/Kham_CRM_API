<?php
/** Run only after explicitly approved local migration. All fixture writes roll back. */
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
if(!Schema::hasTable('ingredients')||!Schema::hasTable('ingredient_product')){
    fwrite(STDERR,"STOP: ingredient migration not applied. Obtain approval before migrating.\n");exit(2);
}
$check=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo "PASS: ".$label."\n";
};
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $path)use($kernel):array{
    $request=Request::create('/api/v1/'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
    $response=$kernel->handle($request);
    try{return [$response->getStatusCode(),json_decode($response->getContent(),true)];}
    finally{$kernel->terminate($request,$response);}
};
DB::beginTransaction();
try{
    $ingredient=\App\Models\Ingredient::create([
        'slug'=>'ingredient-test-'.\Illuminate\Support\Str::random(12),
        'name'=>'Rose extract','name_ar'=>'مستخلص الورد',
        'description'=>'Gentle botanical','description_ar'=>'مستخلص نباتي لطيف',
        'is_active'=>true,
    ]);
    [$status,$en]=$call('storefront/ingredients/'.$ingredient->slug.'?locale=en');
    $check($status===200&&($en['data']['name']??null)==='Rose extract','public English ingredient detail');
    [$status,$ar]=$call('storefront/ingredients/'.$ingredient->slug.'?locale=ar');
    $check($status===200&&($ar['data']['name']??null)==='مستخلص الورد','public Arabic ingredient detail');
    $ingredient->update(['name_ar'=>null]);
    [$status,$fallback]=$call('storefront/ingredients/'.$ingredient->slug.'?locale=ar');
    $check($status===200&&($fallback['data']['name']??null)==='Rose extract','Arabic ingredient fallback');
    $ingredient->update(['is_active'=>false]);
    [$status]=$call('storefront/ingredients/'.$ingredient->slug);
    $check($status===404,'inactive ingredient detail hidden');
    $ingredient->update(['is_active'=>true]);
    $product=\App\Models\Product::where('status',\App\Enums\ProductStatus::Active)->firstOrFail();
    $product->linkedIngredients()->attach($ingredient->id,['sort_order'=>1]);
    $check($product->linkedIngredients()->whereKey($ingredient->id)->exists(),'product ingredient relation persisted');
    $product->linkedIngredients()->detach($ingredient->id);
    echo "ALL LINKED INGREDIENT TESTS PASSED (rolled back)\n";
}finally{DB::rollBack();}
