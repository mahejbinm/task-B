<?php

namespace UserDiscounts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use UserDiscounts\Models\Discount;
use UserDiscounts\Models\UserDiscount;
use UserDiscounts\Models\DiscountAudit;
use UserDiscounts\Events\DiscountAssigned;
use UserDiscounts\Events\DiscountRevoked;
use UserDiscounts\Events\DiscountApplied;
use Carbon\Carbon;

class DiscountService
{
    /**
     * Assign a discount to a user.
     *
     * @param int $userId
     * @param int $discountId
     * @return UserDiscount
     * @throws \Exception
     */
    public function assign(int $userId, int $discountId): UserDiscount
    {
        $discount = Discount::findOrFail($discountId);

        if (!$discount->isValid()) {
            throw new \Exception("Discount is not valid (inactive or expired)");
        }

        return DB::transaction(function () use ($userId, $discount) {
            // Check if already assigned and not revoked
            $existing = UserDiscount::where('user_id', $userId)
                ->where('discount_id', $discount->id)
                ->whereNull('revoked_at')
                ->first();

            if ($existing) {
                return $existing;
            }

            // Create or restore user discount
            $userDiscount = UserDiscount::updateOrCreate(
                [
                    'user_id' => $userId,
                    'discount_id' => $discount->id,
                ],
                [
                    'usage_count' => 0,
                    'assigned_at' => Carbon::now(),
                    'revoked_at' => null,
                ]
            );

            // Create audit record
            DiscountAudit::create([
                'user_id' => $userId,
                'discount_id' => $discount->id,
                'user_discount_id' => $userDiscount->id,
                'action' => 'assigned',
                'metadata' => [],
            ]);

            Event::dispatch(new DiscountAssigned($userDiscount));

            return $userDiscount;
        });
    }

    /**
     * Revoke a discount from a user.
     *
     * @param int $userId
     * @param int $discountId
     * @return UserDiscount
     */
    public function revoke(int $userId, int $discountId): UserDiscount
    {
        return DB::transaction(function () use ($userId, $discountId) {
            $userDiscount = UserDiscount::where('user_id', $userId)
                ->where('discount_id', $discountId)
                ->whereNull('revoked_at')
                ->firstOrFail();

            $userDiscount->update([
                'revoked_at' => Carbon::now(),
            ]);

            // Create audit record
            DiscountAudit::create([
                'user_id' => $userId,
                'discount_id' => $discountId,
                'user_discount_id' => $userDiscount->id,
                'action' => 'revoked',
                'metadata' => [],
            ]);

            Event::dispatch(new DiscountRevoked($userDiscount));

            return $userDiscount->fresh();
        });
    }

    /**
     * Get eligible discounts for a user.
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function eligibleFor(int $userId)
    {
        $now = Carbon::now();

        return UserDiscount::with('discount')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->whereHas('discount', function ($query) use ($now) {
                $query->where('is_active', true)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('starts_at')
                            ->orWhere('starts_at', '<=', $now);
                    })
                    ->where(function ($q) use ($now) {
                        $q->whereNull('expires_at')
                            ->orWhere('expires_at', '>=', $now);
                    })
                    ->where(function ($q) {
                        $q->whereNull('max_total_usage')
                            ->orWhereColumn('current_total_usage', '<', 'max_total_usage');
                    });
            })
            ->get()
            ->filter(function ($userDiscount) {
                // Filter out discounts where user has reached usage limit
                return !$userDiscount->hasReachedUsageLimit();
            })
            ->sortBy(function ($userDiscount) {
                $config = config('discounts.stacking_order', []);
                $priorityOrder = $config['priority'] ?? 'asc';
                $idOrder = $config['id'] ?? 'asc';

                $priority = $userDiscount->discount->priority ?? 0;
                $id = $userDiscount->discount->id ?? 0;

                // Create sort key: priority first, then ID
                if ($priorityOrder === 'desc') {
                    $priority = -$priority;
                }
                if ($idOrder === 'desc') {
                    $id = -$id;
                }

                return [$priority, $id];
            })
            ->values();
    }

    /**
     * Apply eligible discounts to an amount.
     *
     * @param int $userId
     * @param float $originalAmount
     * @param string|null $transactionId For idempotency
     * @return array{final_amount: float, discount_amount: float, applied_discounts: array}
     */
    public function apply(int $userId, float $originalAmount, ?string $transactionId = null): array
    {
        // Generate transaction ID if not provided
        if ($transactionId === null) {
            $transactionId = uniqid('discount_', true);
        }

        // Check if this transaction was already processed (idempotency)
        $existingAudits = DiscountAudit::where('transaction_id', $transactionId)
            ->where('action', 'applied')
            ->where('user_id', $userId)
            ->get();

        if ($existingAudits->isNotEmpty()) {
            // Return cached result for idempotency
            $appliedDiscounts = [];
            $totalDiscountAmount = 0;
            $finalAmount = $originalAmount;

            foreach ($existingAudits as $audit) {
                $userDiscount = UserDiscount::find($audit->user_discount_id);
                if ($userDiscount) {
                    $appliedDiscounts[] = [
                        'discount_id' => $userDiscount->discount_id,
                        'code' => $userDiscount->discount->code,
                        'amount' => (float) $audit->discount_amount,
                    ];
                    $totalDiscountAmount += (float) $audit->discount_amount;
                }
                // Use the last audit's final amount (should be the same for all in a transaction)
                $finalAmount = (float) $audit->final_amount;
            }

            return [
                'final_amount' => $finalAmount,
                'discount_amount' => $totalDiscountAmount,
                'applied_discounts' => $appliedDiscounts,
                'transaction_id' => $transactionId,
            ];
        }

        return DB::transaction(function () use ($userId, $originalAmount, $transactionId) {
            $eligibleDiscounts = $this->eligibleFor($userId);
            $remainingAmount = $originalAmount;
            $totalDiscountAmount = 0;
            $appliedDiscounts = [];
            $maxPercentageCap = config('discounts.max_percentage_cap', 100);
            $currentTotalPercentage = 0;

            foreach ($eligibleDiscounts as $userDiscount) {
                $discount = $userDiscount->discount;

                // Calculate discount amount
                $discountAmount = 0;
                if ($discount->type === 'percentage') {
                    $percentage = min($discount->value, $maxPercentageCap - $currentTotalPercentage);
                    if ($percentage > 0) {
                        $discountAmount = ($remainingAmount * $percentage) / 100;
                        $currentTotalPercentage += $percentage;
                    }
                } else {
                    // Fixed amount
                    $discountAmount = min($discount->value, $remainingAmount);
                }

                if ($discountAmount > 0) {
                    // Use pessimistic locking to prevent concurrent double-increment
                    $lockedUserDiscount = UserDiscount::lockForUpdate()
                        ->find($userDiscount->id);

                    // Lock discount model as well
                    $lockedDiscount = Discount::lockForUpdate()
                        ->find($discount->id);

                    // Double-check usage limit after lock
                    if (!$lockedUserDiscount->hasReachedUsageLimit() && !$lockedDiscount->hasReachedTotalLimit()) {
                        // Increment usage count atomically
                        $lockedUserDiscount->increment('usage_count');
                        $lockedDiscount->increment('current_total_usage');

                        $remainingAmount -= $discountAmount;
                        $totalDiscountAmount += $discountAmount;

                        $appliedDiscounts[] = [
                            'discount_id' => $lockedDiscount->id,
                            'code' => $lockedDiscount->code,
                            'amount' => $discountAmount,
                        ];

                        // Create audit record for each applied discount
                        $audit = DiscountAudit::create([
                            'user_id' => $userId,
                            'discount_id' => $lockedDiscount->id,
                            'user_discount_id' => $lockedUserDiscount->id,
                            'action' => 'applied',
                            'original_amount' => $originalAmount,
                            'discount_amount' => $discountAmount,
                            'final_amount' => $remainingAmount,
                            'transaction_id' => $transactionId,
                            'metadata' => [
                                'stack_position' => count($appliedDiscounts),
                            ],
                        ]);

                        Event::dispatch(new DiscountApplied($lockedUserDiscount, $audit));
                    }

                    // Stop if we've reached max percentage cap
                    if ($currentTotalPercentage >= $maxPercentageCap) {
                        break;
                    }
                }
            }

            // Apply rounding
            $rounding = config('discounts.rounding', 'nearest');
            $precision = config('discounts.rounding_precision', 2);

            $finalAmount = match ($rounding) {
                'up' => ceil($remainingAmount * pow(10, $precision)) / pow(10, $precision),
                'down' => floor($remainingAmount * pow(10, $precision)) / pow(10, $precision),
                'nearest' => round($remainingAmount, $precision),
                'none' => $remainingAmount,
                default => round($remainingAmount, $precision),
            };

            // Recalculate total discount amount after rounding
            $totalDiscountAmount = $originalAmount - $finalAmount;

            return [
                'final_amount' => (float) $finalAmount,
                'discount_amount' => (float) $totalDiscountAmount,
                'applied_discounts' => $appliedDiscounts,
                'transaction_id' => $transactionId,
            ];
        });
    }
}

