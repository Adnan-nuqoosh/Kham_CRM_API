<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PartnerApplication extends Model {
 protected $fillable=['contact_name','email','phone','company_name','website','partner_type','message',
  'status','internal_notes','reviewed_by','reviewed_at'];
 protected $casts=['reviewed_at'=>'datetime'];
}
