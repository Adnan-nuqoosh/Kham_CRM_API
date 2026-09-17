<?php
namespace App\Models;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Order extends Model {
 protected $fillable=['order_number','customer_id','market_id','warehouse_id','currency_code','status','payment_status','fulfillment_status','subtotal','discount_total','shipping_total','tax_total','grand_total','coupon_code','notes','placed_at'];
 protected $casts=['status'=>OrderStatus::class,'payment_status'=>PaymentStatus::class,'subtotal'=>'decimal:4','discount_total'=>'decimal:4','shipping_total'=>'decimal:4','tax_total'=>'decimal:4','grand_total'=>'decimal:4','placed_at'=>'datetime'];
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);} public function market():BelongsTo{return $this->belongsTo(Market::class);} public function warehouse():BelongsTo{return $this->belongsTo(Warehouse::class);} public function items():HasMany{return $this->hasMany(OrderItem::class);} public function addresses():HasMany{return $this->hasMany(OrderAddress::class);} public function payments():HasMany{return $this->hasMany(Payment::class);} public function shipments():HasMany{return $this->hasMany(Shipment::class);}
}
