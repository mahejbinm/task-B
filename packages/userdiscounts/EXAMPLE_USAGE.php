<?php

/**
 * Example Usage of User Discounts Package
 * 
 * This file demonstrates how to use the discount package.
 * You can run this via: php artisan tinker
 * Then copy-paste the code below.
 */

use App\Models\User;
use UserDiscounts\DiscountService;
use UserDiscounts\Models\Discount;
use UserDiscounts\Models\UserDiscount;
use UserDiscounts\Models\DiscountAudit;
use Carbon\Carbon;

// Get the discount service
$service = app(DiscountService::class);

// Create a test user (or use existing)
$user = User::firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'Test User', 'password' => bcrypt('password')]
);

echo "=== Creating Discounts ===\n";

// Create a percentage discount
$discount1 = Discount::create([
    'code' => 'SAVE10',
    'name' => '10% Off',
    'type' => 'percentage',
    'value' => 10,
    'is_active' => true,
    'priority' => 1,
    'max_usage_per_user' => 5,
]);

echo "Created discount: {$discount1->code} ({$discount1->value}%)\n";

// Create another discount with higher priority
$discount2 = Discount::create([
    'code' => 'SAVE20',
    'name' => '20% Off',
    'type' => 'percentage',
    'value' => 20,
    'is_active' => true,
    'priority' => 0, // Lower number = higher priority
    'max_usage_per_user' => 3,
]);

echo "Created discount: {$discount2->code} ({$discount2->value}%)\n";

// Create a fixed amount discount
$discount3 = Discount::create([
    'code' => 'FIXED5',
    'name' => '$5 Off',
    'type' => 'fixed',
    'value' => 5.00,
    'is_active' => true,
    'priority' => 2,
]);

echo "Created discount: {$discount3->code} (\${$discount3->value})\n\n";

echo "=== Assigning Discounts ===\n";

// Assign discounts to user
$userDiscount1 = $service->assign($user->id, $discount1->id);
echo "Assigned {$discount1->code} to user\n";

$userDiscount2 = $service->assign($user->id, $discount2->id);
echo "Assigned {$discount2->code} to user\n";

$userDiscount3 = $service->assign($user->id, $discount3->id);
echo "Assigned {$discount3->code} to user\n\n";

echo "=== Checking Eligible Discounts ===\n";
$eligible = $service->eligibleFor($user->id);
echo "Eligible discounts: " . $eligible->count() . "\n";
foreach ($eligible as $ud) {
    echo "  - {$ud->discount->code} ({$ud->discount->type}: {$ud->discount->value})\n";
}
echo "\n";

echo "=== Applying Discounts ===\n";
$originalAmount = 100.00;
echo "Original amount: \${$originalAmount}\n";

$result = $service->apply($user->id, $originalAmount, 'test_transaction_1');

echo "Final amount: \${$result['final_amount']}\n";
echo "Total discount: \${$result['discount_amount']}\n";
echo "Applied discounts:\n";
foreach ($result['applied_discounts'] as $applied) {
    echo "  - {$applied['code']}: \${$applied['amount']}\n";
}
echo "Transaction ID: {$result['transaction_id']}\n\n";

echo "=== Testing Idempotency ===\n";
$result2 = $service->apply($user->id, $originalAmount, 'test_transaction_1');
echo "Applied same transaction again - should return same result\n";
echo "Final amount (should be same): \${$result2['final_amount']}\n";
echo "Usage count should not increment twice\n\n";

echo "=== Checking Usage Counts ===\n";
$ud1 = UserDiscount::where('user_id', $user->id)->where('discount_id', $discount1->id)->first();
$ud2 = UserDiscount::where('user_id', $user->id)->where('discount_id', $discount2->id)->first();
echo "{$discount1->code} usage: {$ud1->usage_count}\n";
echo "{$discount2->code} usage: {$ud2->usage_count}\n\n";

echo "=== Viewing Audit Trail ===\n";
$audits = DiscountAudit::where('user_id', $user->id)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($audits as $audit) {
    echo "Action: {$audit->action} | ";
    echo "Discount: {$audit->discount->code} | ";
    if ($audit->action === 'applied') {
        echo "Amount: \${$audit->original_amount} -> \${$audit->final_amount} (discount: \${$audit->discount_amount})";
    }
    echo "\n";
}
echo "\n";

echo "=== Testing Revocation ===\n";
$service->revoke($user->id, $discount1->id);
echo "Revoked {$discount1->code}\n";

$eligibleAfterRevoke = $service->eligibleFor($user->id);
echo "Eligible discounts after revocation: " . $eligibleAfterRevoke->count() . "\n";

$result3 = $service->apply($user->id, 100.00, 'test_transaction_2');
echo "Applied discounts after revocation: " . count($result3['applied_discounts']) . "\n";
echo "Final amount: \${$result3['final_amount']}\n\n";

echo "=== Testing Expired Discount ===\n";
$expiredDiscount = Discount::create([
    'code' => 'EXPIRED',
    'name' => 'Expired Discount',
    'type' => 'percentage',
    'value' => 15,
    'is_active' => true,
    'expires_at' => Carbon::now()->subDay(), // Expired yesterday
]);

try {
    $service->assign($user->id, $expiredDiscount->id);
    echo "ERROR: Should not be able to assign expired discount!\n";
} catch (\Exception $e) {
    echo "Correctly prevented assigning expired discount: {$e->getMessage()}\n";
}

echo "\n=== All tests completed! ===\n";

