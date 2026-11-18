<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\StorefrontAccount;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StorefrontAddressController extends Controller
{
    private function customerId(Request $request): int
    {
        $account=$request->user();
        if (!$account instanceof StorefrontAccount || !$account->tokenCan('storefront')) abort(403);
        return $account->customer_id;
    }

    private function rules(bool $create=false): array
    {
        $required=$create?'required':'sometimes';
        return [
            'type'=>[$required,'in:shipping,billing'],
            'first_name'=>[$required,'string','max:100'],
            'last_name'=>['nullable','string','max:100'],
            'phone'=>['nullable','string','max:30'],
            'line1'=>[$required,'string','max:255'],
            'line2'=>['nullable','string','max:255'],
            'city'=>[$required,'string','max:120'],
            'state'=>['nullable','string','max:120'],
            'postal_code'=>['nullable','string','max:30'],
            'country_code'=>[$required,'string','size:2'],
            'is_default'=>['sometimes','boolean'],
        ];
    }

    public function index(Request $request)
    {
        return ApiResponse::success(CustomerAddress::where('customer_id',$this->customerId($request))
            ->orderByDesc('is_default')->latest('id')->get(), 'Addresses fetched.','STOREFRONT_ADDRESSES')
            ->header('Cache-Control','no-store');
    }

    public function store(Request $request)
    {
        $customerId=$this->customerId($request);
        $data=$request->validate($this->rules(true));
        $address=DB::transaction(function() use($customerId,$data){
            if(($data['is_default']??false)===true){
                CustomerAddress::where('customer_id',$customerId)->update(['is_default'=>false]);
            }
            return CustomerAddress::create($data+['customer_id'=>$customerId]);
        });
        return ApiResponse::success($address,'Address created.','STOREFRONT_ADDRESS_CREATED',201)
            ->header('Cache-Control','no-store');
    }

    public function update(Request $request, int $address)
    {
        $customerId=$this->customerId($request);
        $data=$request->validate($this->rules());
        $updated=DB::transaction(function()use($customerId,$data,$address){
            $row=CustomerAddress::where('customer_id',$customerId)->whereKey($address)->lockForUpdate()->firstOrFail();
            if(($data['is_default']??false)===true){
                CustomerAddress::where('customer_id',$customerId)->update(['is_default'=>false]);
            }
            $row->update($data);
            return $row->fresh();
        });
        return ApiResponse::success($updated,'Address updated.','STOREFRONT_ADDRESS_UPDATED')
            ->header('Cache-Control','no-store');
    }

    public function destroy(Request $request,int $address)
    {
        CustomerAddress::where('customer_id',$this->customerId($request))->whereKey($address)
            ->firstOrFail()->delete();
        return ApiResponse::success(null,'Address removed.','STOREFRONT_ADDRESS_DELETED')
            ->header('Cache-Control','no-store');
    }
}
