<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /* ---- Relationships ---- */

    public function jobs()
    {
        return $this->hasMany(Job::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /* ---- Helpers ---- */

    public function hasActiveSubscription(): bool
    {
        $sub = $this->subscription;
        return $sub && $sub->status === 'active' && $sub->expires_at->isFuture();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
