<?php

namespace UserDiscounts\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use UserDiscounts\Models\UserDiscount;

class DiscountAssigned
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public UserDiscount $userDiscount
    ) {
    }
}

