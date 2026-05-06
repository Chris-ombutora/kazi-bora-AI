<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'phone_number',
        'amount',
        'mpesa_receipt_number',
        'merchant_request_id',
        'checkout_request_id',
        'transaction_date',
        'status',
        'result_code',
        'result_description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'transaction_date' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }
}
