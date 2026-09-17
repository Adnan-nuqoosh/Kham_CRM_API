<?php
namespace App\Enums;
enum OrderStatus: string { case Pending='pending'; case Confirmed='confirmed'; case Processing='processing'; case Packed='packed'; case Shipped='shipped'; case Delivered='delivered'; case Cancelled='cancelled'; case Returned='returned'; }
