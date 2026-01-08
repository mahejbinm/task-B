<?php

namespace UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Discount extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'priority',
        'is_active',
        'starts_at',
        'expires_at',
        'max_usage_per_user',
        'max_total_usage',
        'current_total_usage',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'decimal:2',
        'priority' => 'integer',
        'max_usage_per_user' => 'integer',
        'max_total_usage' => 'integer',
        'current_total_usage' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get user discounts for this discount.
     */
    public function userDiscounts(): HasMany
    {
        return $this->hasMany(UserDiscount::class);
    }

    /**
     * Check if discount is currently valid (active and not expired).
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = Carbon::now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && $now->gt($this->expires_at)) {
            return false;
        }

        if ($this->max_total_usage !== null && $this->current_total_usage >= $this->max_total_usage) {
            return false;
        }

        return true;
    }

    /**
     * Check if discount has reached total usage limit.
     */
    public function hasReachedTotalLimit(): bool
    {
        if ($this->max_total_usage === null) {
            return false;
        }

        return $this->current_total_usage >= $this->max_total_usage;
    }
}

