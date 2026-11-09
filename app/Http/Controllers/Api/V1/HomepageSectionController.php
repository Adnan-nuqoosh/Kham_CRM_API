<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\HomepageSection;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class HomepageSectionController extends Controller {
 private function rules(bool $create):array {
  $required=$create?'required':'sometimes';
  return [
   'key'=>[$required,'string','max:100','regex:/^[a-z0-9][a-z0-9_-]*$/'],
   'type'=>[$required,Rule::in(['hero','banner','featured','promotion','editorial','custom'])],
   'title_en'=>[$required,'string','max:255'],
   'title_ar'=>['sometimes','nullable','string','max:255'],
   'subtitle_en'=>['sometimes','nullable','string','max:5000'],
   'subtitle_ar'=>['sometimes','nullable','string','max:5000'],
   'image_url'=>['sometimes','nullable','url','max:1000'],
   'link_url'=>['sometimes','nullable','string','max:1000','regex:/^(\/(?!\/)|https:\/\/)/'],
   'content'=>['sometimes','nullable','array'],
   'sort_order'=>['sometimes','integer','min:0','max:1000000'],
   'is_published'=>['sometimes','boolean'],
  ];
 }
 public function publicIndex(Request $request) {
  $data=$request->validate(['locale'=>['sometimes',Rule::in(['en','ar'])]]);
  $locale=$data['locale']??'en';
  $sections=HomepageSection::where('is_published',true)->orderBy('sort_order')->orderBy('id')
   ->get()->map(static function(HomepageSection $section)use($locale){
    return [
     'key'=>$section->key,'type'=>$section->type,
     'title'=>$locale==='ar'?($section->title_ar?:$section->title_en):$section->title_en,
     'subtitle'=>$locale==='ar'?($section->subtitle_ar?:$section->subtitle_en):$section->subtitle_en,
     'image_url'=>$section->image_url,'link_url'=>$section->link_url,
     'content'=>$section->content,'sort_order'=>$section->sort_order,
    ];
   });
  return ApiResponse::success($sections,'Homepage sections fetched.','HOMEPAGE_SECTIONS')
   ->header('Cache-Control','public, max-age=60');
 }
 public function index(Request $request) {
  $data=$request->validate(['type'=>['sometimes',Rule::in(['hero','banner','featured','promotion','editorial','custom'])]]);
  $query=HomepageSection::query();
  if(isset($data['type']))$query->where('type',$data['type']);
  $page=$query->orderBy('sort_order')->orderBy('id')->paginate(PaginationMeta::perPage($request));
  return ApiResponse::success($page->items(),'Homepage CMS sections fetched.','HOMEPAGE_CMS_SECTIONS',200,PaginationMeta::from($page))
   ->header('Cache-Control','no-store');
 }
 public function store(Request $request) {
  $data=$request->validate($this->rules(true));
  $request->validate(['key'=>['unique:homepage_sections,key']]);
  $section=HomepageSection::create($data);
  return ApiResponse::success($section,'Homepage section created.','HOMEPAGE_SECTION_CREATED',201)
   ->header('Cache-Control','no-store');
 }
 public function update(Request $request,HomepageSection $section) {
  $data=$request->validate($this->rules(false));
  if(isset($data['key'])){
   $request->validate(['key'=>[Rule::unique('homepage_sections','key')->ignore($section->id)]]);
  }
  $section->update($data);
  return ApiResponse::success($section->refresh(),'Homepage section updated.','HOMEPAGE_SECTION_UPDATED')
   ->header('Cache-Control','no-store');
 }
 public function destroy(HomepageSection $section) {
  $section->delete();
  return ApiResponse::success(null,'Homepage section deleted.','HOMEPAGE_SECTION_DELETED')
   ->header('Cache-Control','no-store');
 }
}
