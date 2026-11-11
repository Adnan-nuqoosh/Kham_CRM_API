<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ritual;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use App\Support\StorefrontLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RitualContentController extends Controller
{
    private function rules(?Ritual $ritual = null): array
    {
        return [
            'slug' => [$ritual ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('rituals','slug')->ignore($ritual?->id)],
            'title' => [$ritual ? 'sometimes' : 'required', 'string', 'max:255'],
            'title_ar' => ['sometimes','nullable','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'description_ar' => ['sometimes','nullable','string'],
            'image_url' => ['sometimes','nullable','string','max:2048'],
            'is_published' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer','min:0'],
            'steps' => ['sometimes','array','max:50'],
            'steps.*.title' => ['required','string','max:255'],
            'steps.*.title_ar' => ['nullable','string','max:255'],
            'steps.*.instructions' => ['required','string'],
            'steps.*.instructions_ar' => ['nullable','string'],
            'steps.*.sort_order' => ['sometimes','integer','min:0'],
            'products' => ['sometimes','array','max:100'],
            'products.*.product_id' => ['required','integer','distinct','exists:products,id'],
            'products.*.sort_order' => ['sometimes','integer','min:0'],
        ];
    }

    public function adminIndex(Request $request)
    {
        $page = Ritual::query()->orderBy('sort_order')->orderBy('id')
            ->paginate(PaginationMeta::perPage($request,20));
        return ApiResponse::success($page->items(),'Rituals fetched.','RITUAL_ADMIN_LIST',200,PaginationMeta::from($page));
    }

    public function adminShow(Ritual $ritual)
    {
        return ApiResponse::success($ritual->load(['steps','products:id,name,slug']),'Ritual fetched.','RITUAL_ADMIN_DETAIL');
    }

    public function store(Request $request)
    {
        $data=$request->validate($this->rules());
        $ritual=DB::transaction(function () use ($data) {
            $ritual=Ritual::create(collect($data)->except(['steps','products'])->all());
            $this->syncRelations($ritual,$data);
            return $ritual->load(['steps','products:id,name,slug']);
        });
        return ApiResponse::success($ritual,'Ritual created.','RITUAL_CREATED',201);
    }

    public function update(Request $request,Ritual $ritual)
    {
        $data=$request->validate($this->rules($ritual));
        $ritual=DB::transaction(function () use ($ritual,$data) {
            $ritual->update(collect($data)->except(['steps','products'])->all());
            $this->syncRelations($ritual,$data);
            return $ritual->refresh()->load(['steps','products:id,name,slug']);
        });
        return ApiResponse::success($ritual,'Ritual updated.','RITUAL_UPDATED');
    }

    public function destroy(Ritual $ritual)
    {
        $ritual->delete();
        return ApiResponse::success(null,'Ritual deleted.','RITUAL_DELETED');
    }

    private function syncRelations(Ritual $ritual,array $data): void
    {
        if(array_key_exists('steps',$data)){
            $ritual->steps()->delete();
            foreach($data['steps'] as $i=>$step){
                $ritual->steps()->create($step+['sort_order'=>$i]);
            }
        }
        if(array_key_exists('products',$data)){
            $ritual->products()->sync(collect($data['products'])->mapWithKeys(
                fn($product,$i)=>[$product['product_id']=>['sort_order'=>$product['sort_order']??$i]]
            )->all());
        }
    }

    public function publicIndex(Request $request)
    {
        $page=Ritual::query()->where('is_published',true)
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(PaginationMeta::perPage($request,20));
        $locale=StorefrontLocale::resolve($request);
        return ApiResponse::success(
            collect($page->items())->map(fn(Ritual $ritual)=>$this->display($ritual,$locale))->all(),
            'Public rituals fetched.','STOREFRONT_RITUALS',200,PaginationMeta::from($page)
        )->header('Cache-Control','no-store');
    }

    public function publicShow(Request $request,string $slug)
    {
        $ritual=Ritual::query()->where('slug',$slug)->where('is_published',true)
            ->with(['steps','products'=>fn($q)=>$q->where('status',\App\Enums\ProductStatus::Active)])
            ->firstOrFail();
        return ApiResponse::success($this->display($ritual,StorefrontLocale::resolve($request),true),
            'Public ritual fetched.','STOREFRONT_RITUAL')->header('Cache-Control','no-store');
    }

    private function display(Ritual $ritual,string $locale,bool $detail=false): array
    {
        $result=[
            'id'=>$ritual->id,'slug'=>$ritual->slug,
            'title'=>StorefrontLocale::text($ritual->title,$ritual->title_ar,$locale),
            'description'=>StorefrontLocale::text($ritual->description,$ritual->description_ar,$locale),
            'image_url'=>$ritual->image_url,
        ];
        if($detail){
            $result['steps']=$ritual->steps->map(fn($step)=>[
                'id'=>$step->id,
                'title'=>StorefrontLocale::text($step->title,$step->title_ar,$locale),
                'instructions'=>StorefrontLocale::text($step->instructions,$step->instructions_ar,$locale),
                'sort_order'=>$step->sort_order,
            ])->all();
            $result['products']=$ritual->products->map(fn($product)=>[
                'id'=>$product->id,'slug'=>$product->slug,
                'name'=>StorefrontLocale::text($product->name,$product->name_ar,$locale),
            ])->all();
        }
        return $result;
    }
}
