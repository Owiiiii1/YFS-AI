<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'instagram_username',
        'instagram_user_id',
        'facebook_psid',
        'address',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
        ];
    }

    public function scopeRegular($query)
    {
        return $query->where('is_staff', false);
    }

    public static function staffClient(): self
    {
        $existing = static::query()->where('is_staff', true)->first();
        if ($existing !== null) {
            return $existing;
        }

        $customer = new static([
            'name' => 'Staff',
            'status' => 'active',
            'notes' => 'Virtual client for internal staff.',
        ]);
        $customer->is_staff = true;
        $customer->save();

        return $customer;
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
