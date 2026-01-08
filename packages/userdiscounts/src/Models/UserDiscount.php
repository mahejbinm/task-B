<?php

namespace UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserDiscount extends Model
{
    protected $fillable = [
        'user_id',
        'discount_id',
        'usage_count',
        'assigned_at',
        'revoked_at',
    ];

    protected $casts = [
        'usage_count' => 'integer',
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Get the user that owns this discount assignment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', \App\Models\User::class));
    }

    /**
     * Get the discount.
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Get audit records for this user discount.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(DiscountAudit::class);
    }

    /**
     * Check if this user discount is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Check if user has reached usage limit for this discount.
     */
    public function hasReachedUsageLimit(): bool
    {
        $maxUsage = $this->discount->max_usage_per_user;

        if ($maxUsage === null) {
            return false;
        }

        return $this->usage_count >= $maxUsage;
    }
}

