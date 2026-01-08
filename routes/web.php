<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use UserDiscounts\DiscountService;
use UserDiscounts\Models\Discount;



Route::get('/', function () {
    $service = app(DiscountService::class);
    
    // Create test user
    $user = User::firstOrCreate(
        ['email' => 'test@example.com'],
        ['name' => 'Test User', 'password' => bcrypt('password')]
    );

    
    // Create discount
    $discount = Discount::create([
        'code' => 'TEST10',
        'name' => 'Test 10% Off',
        'type' => 'percentage',
        'value' => 10,
        'is_active' => true,
    ]);
    
    // Assign and apply
    $service->assign($user->id, $discount->id);
    $result = $service->apply($user->id, 100.00);
    
    return [
        'original' => 100.00,
        'final' => $result['final_amount'],
        'discount' => $result['discount_amount'],
        'applied' => $result['applied_discounts'],
    ];
});