<?php

namespace UserDiscounts\Tests;

use App\Models\User;
use UserDiscounts\DiscountService;
use UserDiscounts\Models\Discount;
use UserDiscounts\Models\UserDiscount;
use UserDiscounts\Models\DiscountAudit;
use Carbon\Carbon;

class DiscountServiceTest extends TestCase
{

    protected DiscountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DiscountService::class);
    }

    /** @test */
    public function it_can_assign_a_discount_to_a_user()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'TEST10',
            'name' => 'Test Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $userDiscount = $this->service->assign($user->id, $discount->id);

        $this->assertInstanceOf(UserDiscount::class, $userDiscount);
        $this->assertEquals($user->id, $userDiscount->user_id);
        $this->assertEquals($discount->id, $userDiscount->discount_id);
        $this->assertEquals(0, $userDiscount->usage_count);
        $this->assertNull($userDiscount->revoked_at);

        // Check audit record
        $audit = DiscountAudit::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->where('action', 'assigned')
            ->first();

        $this->assertNotNull($audit);
    }

    /** @test */
    public function it_cannot_assign_inactive_discount()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'INACTIVE',
            'name' => 'Inactive Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Discount is not valid (inactive or expired)");

        $this->service->assign($user->id, $discount->id);
    }

    /** @test */
    public function it_cannot_assign_expired_discount()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'EXPIRED',
            'name' => 'Expired Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Discount is not valid (inactive or expired)");

        $this->service->assign($user->id, $discount->id);
    }

    /** @test */
    public function it_can_revoke_a_discount_from_a_user()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'REVOKE',
            'name' => 'Revokable Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $userDiscount = $this->service->assign($user->id, $discount->id);
        $this->assertNull($userDiscount->revoked_at);

        $revoked = $this->service->revoke($user->id, $discount->id);

        $this->assertNotNull($revoked->revoked_at);

        // Check audit record
        $audit = DiscountAudit::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->where('action', 'revoked')
            ->first();

        $this->assertNotNull($audit);
    }

    /** @test */
    public function it_returns_only_eligible_discounts()
    {
        $user = User::factory()->create();

        // Active discount
        $activeDiscount = Discount::create([
            'code' => 'ACTIVE',
            'name' => 'Active Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        // Inactive discount
        $inactiveDiscount = Discount::create([
            'code' => 'INACTIVE',
            'name' => 'Inactive Discount',
            'type' => 'percentage',
            'value' => 20,
            'is_active' => false,
        ]);

        // Expired discount
        $expiredDiscount = Discount::create([
            'code' => 'EXPIRED',
            'name' => 'Expired Discount',
            'type' => 'percentage',
            'value' => 15,
            'is_active' => true,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        // Assign active discount
        $this->service->assign($user->id, $activeDiscount->id);
        
        // Try to assign inactive discount (should fail, but create user_discount manually for test)
        try {
            $this->service->assign($user->id, $inactiveDiscount->id);
        } catch (\Exception $e) {
            // Expected - inactive discount cannot be assigned
            // Manually create user_discount record to test filtering
            \UserDiscounts\Models\UserDiscount::create([
                'user_id' => $user->id,
                'discount_id' => $inactiveDiscount->id,
                'assigned_at' => Carbon::now(),
                'usage_count' => 0,
            ]);
        }
        
        // Try to assign expired discount (should fail, but create user_discount manually for test)
        try {
            $this->service->assign($user->id, $expiredDiscount->id);
        } catch (\Exception $e) {
            // Expected - expired discount cannot be assigned
            // Manually create user_discount record to test filtering
            \UserDiscounts\Models\UserDiscount::create([
                'user_id' => $user->id,
                'discount_id' => $expiredDiscount->id,
                'assigned_at' => Carbon::now(),
                'usage_count' => 0,
            ]);
        }

        // Revoke one
        $this->service->revoke($user->id, $inactiveDiscount->id);

        $eligible = $this->service->eligibleFor($user->id);

        $this->assertCount(1, $eligible);
        $this->assertEquals($activeDiscount->id, $eligible->first()->discount_id);
    }

    /** @test */
    public function it_excludes_discounts_where_user_reached_usage_limit()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'LIMITED',
            'name' => 'Limited Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'max_usage_per_user' => 2,
        ]);

        $userDiscount = $this->service->assign($user->id, $discount->id);

        // Apply discount twice to reach limit
        $this->service->apply($user->id, 100.00);
        $this->service->apply($user->id, 100.00);

        $eligible = $this->service->eligibleFor($user->id);

        $this->assertCount(0, $eligible);
    }

    /** @test */
    public function it_applies_discounts_correctly()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'TEST10',
            'name' => 'Test 10%',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(90.00, $result['final_amount']);
        $this->assertEquals(10.00, $result['discount_amount']);
        $this->assertCount(1, $result['applied_discounts']);
        $this->assertEquals($discount->id, $result['applied_discounts'][0]['discount_id']);

        // Check usage count incremented
        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        $this->assertEquals(1, $userDiscount->usage_count);
    }

    /** @test */
    public function it_applies_discounts_deterministically_with_stacking()
    {
        $user = User::factory()->create();

        // Create discounts with different priorities
        $discount1 = Discount::create([
            'code' => 'LOW',
            'name' => 'Low Priority',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'priority' => 10,
        ]);

        $discount2 = Discount::create([
            'code' => 'HIGH',
            'name' => 'High Priority',
            'type' => 'percentage',
            'value' => 20,
            'is_active' => true,
            'priority' => 5,
        ]);

        $this->service->assign($user->id, $discount1->id);
        $this->service->assign($user->id, $discount2->id);

        $result = $this->service->apply($user->id, 100.00);

        // High priority (5) should be applied first
        $this->assertEquals($discount2->id, $result['applied_discounts'][0]['discount_id']);
        $this->assertEquals($discount1->id, $result['applied_discounts'][1]['discount_id']);

        // 20% of 100 = 20, then 10% of 80 = 8, total = 28
        $this->assertEquals(72.00, $result['final_amount']);
        $this->assertEquals(28.00, $result['discount_amount']);
    }

    /** @test */
    public function it_respects_max_percentage_cap()
    {
        config(['discounts.max_percentage_cap' => 50]);

        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'HIGH',
            'name' => 'High Discount',
            'type' => 'percentage',
            'value' => 60, // Exceeds cap
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);

        $result = $this->service->apply($user->id, 100.00);

        // Should cap at 50%
        $this->assertEquals(50.00, $result['final_amount']);
        $this->assertEquals(50.00, $result['discount_amount']);
    }

    /** @test */
    public function it_applies_rounding_correctly()
    {
        config(['discounts.rounding' => 'nearest', 'discounts.rounding_precision' => 2]);

        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'ROUND',
            'name' => 'Round Test',
            'type' => 'percentage',
            'value' => 33.333,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);

        $result = $this->service->apply($user->id, 100.00);

        // Should round to 2 decimal places
        $this->assertEquals(66.67, $result['final_amount'], 'Final amount should be rounded');
    }

    /** @test */
    public function it_does_not_apply_revoked_discounts()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'REVOKED',
            'name' => 'Revoked Discount',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);
        $this->service->revoke($user->id, $discount->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(100.00, $result['final_amount']);
        $this->assertEquals(0.00, $result['discount_amount']);
        $this->assertCount(0, $result['applied_discounts']);
    }

    /** @test */
    public function it_is_idempotent_with_transaction_id()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'IDEMPOTENT',
            'name' => 'Idempotent Test',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);

        $transactionId = 'test_transaction_123';

        $result1 = $this->service->apply($user->id, 100.00, $transactionId);
        $result2 = $this->service->apply($user->id, 100.00, $transactionId);

        // Results should be identical
        $this->assertEquals($result1['final_amount'], $result2['final_amount']);
        $this->assertEquals($result1['discount_amount'], $result2['discount_amount']);
        $this->assertEquals($transactionId, $result1['transaction_id']);
        $this->assertEquals($transactionId, $result2['transaction_id']);

        // Usage count should only increment once
        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        $this->assertEquals(1, $userDiscount->usage_count);
    }

    /** @test */
    public function it_prevents_concurrent_double_increment()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'CONCURRENT',
            'name' => 'Concurrent Test',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);

        // Simulate concurrent applications
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->service->apply($user->id, 100.00);
        }

        // Check usage count
        $userDiscount = UserDiscount::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->first();

        // Should be exactly 5, not more
        $this->assertEquals(5, $userDiscount->usage_count);

        // All should have applied discount
        foreach ($results as $result) {
            $this->assertEquals(90.00, $result['final_amount']);
            $this->assertCount(1, $result['applied_discounts']);
        }
    }

    /** @test */
    public function it_handles_fixed_amount_discounts()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'FIXED',
            'name' => 'Fixed Amount',
            'type' => 'fixed',
            'value' => 15.50,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);

        $result = $this->service->apply($user->id, 100.00);

        $this->assertEquals(84.50, $result['final_amount']);
        $this->assertEquals(15.50, $result['discount_amount']);
    }

    /** @test */
    public function it_creates_audit_records_for_applied_discounts()
    {
        $user = User::factory()->create();
        $discount = Discount::create([
            'code' => 'AUDIT',
            'name' => 'Audit Test',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->service->assign($user->id, $discount->id);
        $result = $this->service->apply($user->id, 100.00);

        $audit = DiscountAudit::where('user_id', $user->id)
            ->where('discount_id', $discount->id)
            ->where('action', 'applied')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals(100.00, $audit->original_amount);
        $this->assertEquals(10.00, $audit->discount_amount);
        $this->assertEquals(90.00, $audit->final_amount);
        $this->assertEquals($result['transaction_id'], $audit->transaction_id);
    }
}

