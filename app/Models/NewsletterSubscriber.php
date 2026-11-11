<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NewsletterSubscriber extends Model {
 protected $fillable=['email','status','subscribed_at','unsubscribed_at','unsubscribe_token'];
 protected $hidden=['unsubscribe_token'];
 protected $casts=['subscribed_at'=>'datetime','unsubscribed_at'=>'datetime'];
}
