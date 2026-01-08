<?php

namespace UserDiscounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountAudit extends Model
{
    protected $fillable = [
        'user_id',
        'discount_id',
        'user_discount_id',
        'action',
        'original_amount',
        'discount_amount',
        'final_amount',
        'metadata',
        'transaction_id',
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    /**
     * Get the user.
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
     * Get the user discount.
     */
    public function userDiscount(): BelongsTo
    {
        return $this->belongsTo(UserDiscount::class);
    }
}

