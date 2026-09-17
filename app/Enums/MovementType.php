<?php
namespace App\Enums;
enum MovementType: string { case Purchase='purchase'; case Sale='sale'; case Return='return'; case Adjustment='adjustment'; case TransferIn='transfer_in'; case TransferOut='transfer_out'; case Damage='damage'; case Reservation='reservation'; case Release='release'; }
