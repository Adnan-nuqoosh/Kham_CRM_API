<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class StoreCmsAdminController extends Controller {
 private const TABLES=['ingredients'=>'store_ingredients','rituals'=>'store_rituals','blocks'=>'store_content_blocks'];
 private function table(string $type):string {abort_unless(isset(self::TABLES[$type]),404);return self::TABLES[$type];}
 public function index(string $type){return ApiResponse::success(DB::table($this->table($type))->orderBy('id')->get());}
 public function save(Request $request,string $type){
  $table=$this->table($type);$isBlock=$type==='blocks';
  $v=$request->validate($isBlock?[
   'key'=>'required|string|max:100|unique:store_content_blocks,key','title'=>'nullable|string|max:255','title_ar'=>'nullable|string|max:255','body'=>'nullable|string','body_ar'=>'nullable|string','image_url'=>'nullable|url|max:2048','link_url'=>'nullable|url|max:2048','sort_order'=>'integer|min:0','is_active'=>'boolean',
  ]:[
   'slug'=>['required','string','max:180',Rule::unique($table,'slug')],'name'=>'required|string|max:255','name_ar'=>'nullable|string|max:255','description'=>'nullable|string','description_ar'=>'nullable|string','image_url'=>'nullable|url|max:2048','is_active'=>'boolean',
  ]);
  $id=DB::table($table)->insertGetId(array_merge($v,['created_at'=>now(),'updated_at'=>now()]));
  return ApiResponse::success(DB::table($table)->find($id),'Content created.','CMS_CREATED',201);
 }
 public function update(Request $request,string $type,int $id){
  $table=$this->table($type);abort_unless(DB::table($table)->where('id',$id)->exists(),404);
  $allowed=$type==='blocks'?['title','title_ar','body','body_ar','image_url','link_url','sort_order','is_active']:['name','name_ar','description','description_ar','image_url','is_active'];
  $rules=[];foreach($allowed as $field)$rules[$field]=match($field){'sort_order'=>'sometimes|integer|min:0','is_active'=>'sometimes|boolean','image_url','link_url'=>'sometimes|nullable|url|max:2048',default=>'sometimes|nullable|string|max:10000'};
  $v=$request->validate($rules);DB::table($table)->where('id',$id)->update(array_merge($v,['updated_at'=>now()]));
  return ApiResponse::success(DB::table($table)->find($id),'Content updated.','CMS_UPDATED');
 }
 public function delete(string $type,int $id){$n=DB::table($this->table($type))->where('id',$id)->update(['is_active'=>false,'updated_at'=>now()]);abort_unless($n,404);return ApiResponse::success(null,'Content unpublished.','CMS_UNPUBLISHED');}
}
