<?php
namespace Database\Seeders;
use App\Models\{Currency,Market,User,Warehouse};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\{Permission,Role};
class DatabaseSeeder extends Seeder {
 public function run():void {
  $egp=Currency::updateOrCreate(['code'=>'EGP'],['name'=>'Egyptian Pound','symbol'=>'E£','decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true]);
  $aed=Currency::updateOrCreate(['code'=>'AED'],['name'=>'UAE Dirham','symbol'=>'AED','decimal_places'=>2,'rate_to_base'=>1,'is_base'=>false,'is_active'=>true]);
  $sar=Currency::updateOrCreate(['code'=>'SAR'],['name'=>'Saudi Riyal','symbol'=>'SAR','decimal_places'=>2,'rate_to_base'=>1,'is_base'=>false,'is_active'=>true]);
  $egypt=Market::updateOrCreate(['code'=>'EG'],['name'=>'Egypt','country_code'=>'EG','currency_id'=>$egp->id,'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true]);
  $uae=Market::updateOrCreate(['code'=>'AE'],['name'=>'United Arab Emirates','country_code'=>'AE','currency_id'=>$aed->id,'timezone'=>'Asia/Dubai','is_default'=>false,'is_active'=>true]);
  $ksa=Market::updateOrCreate(['code'=>'SA'],['name'=>'Saudi Arabia','country_code'=>'SA','currency_id'=>$sar->id,'timezone'=>'Asia/Riyadh','is_default'=>false,'is_active'=>true]);
  Warehouse::updateOrCreate(['code'=>'EG-01'],['market_id'=>$egypt->id,'name'=>'Egypt Main Warehouse','is_active'=>true]);Warehouse::updateOrCreate(['code'=>'AE-01'],['market_id'=>$uae->id,'name'=>'UAE Main Warehouse','is_active'=>true]);Warehouse::updateOrCreate(['code'=>'SA-01'],['market_id'=>$ksa->id,'name'=>'KSA Main Warehouse','is_active'=>true]);
  $permissions=['dashboard.view','products.view','products.manage','pricing.manage','inventory.view','inventory.manage','customers.view','customers.manage','orders.view','orders.manage','coupons.manage','settings.manage'];foreach($permissions as $name)Permission::firstOrCreate(['name'=>$name,'guard_name'=>'web']);
  $admin=Role::firstOrCreate(['name'=>'Super Admin','guard_name'=>'web']);$admin->syncPermissions(Permission::all());
  $user=User::updateOrCreate(['email'=>env('ADMIN_EMAIL','admin@example.com')],['name'=>env('ADMIN_NAME','KHAM Admin'),'password'=>Hash::make(env('ADMIN_PASSWORD','ChangeMe123!'))]);$user->syncRoles([$admin]);
 }
}
