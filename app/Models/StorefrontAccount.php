<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

final class StorefrontAccount extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['customer_id', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['password' => 'hashed']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
