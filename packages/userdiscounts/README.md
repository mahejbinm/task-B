# User Discounts Package

A reusable Laravel package for user-level discounts with deterministic stacking and full audit trail.

## Features

- ✅ Assign and revoke discounts to users
- ✅ Deterministic discount stacking with configurable priority
- ✅ Per-user usage caps
- ✅ Global discount usage limits
- ✅ Idempotent discount application
- ✅ Concurrent-safe usage tracking
- ✅ Full audit trail
- ✅ Configurable rounding and max percentage cap
- ✅ Event-driven architecture

## Installation

The package is already integrated into this project. To use it in another project:

1. Copy the `packages/userdiscounts` directory
2. Add to `composer.json`:
```json
{
    "autoload": {
        "psr-4": {
            "UserDiscounts\\": "packages/userdiscounts/src/"
        }
    }
}
```
3. Register the service provider in `bootstrap/providers.php`
4. Run migrations: `php artisan migrate`
5. Publish config: `php artisan vendor:publish --tag=discounts-config`

## Usage

### Assign a Discount

```php
use UserDiscounts\DiscountService;
use UserDiscounts\Models\Discount;

$service = app(DiscountService::class);

// Create a discount
$discount = Discount::create([
    'code' => 'SAVE10',
    'name' => '10% Off',
    'type' => 'percentage',
    'value' => 10,
    'is_active' => true,
    'priority' => 1,
    'max_usage_per_user' => 5,
]);

// Assign to user
$userDiscount = $service->assign($userId, $discount->id);
```

### Revoke a Discount

```php
$service->revoke($userId, $discount->id);
```

### Get Eligible Discounts

```php
$eligible = $service->eligibleFor($userId);
```

### Apply Discounts

```php
$result = $service->apply($userId, 100.00, 'transaction_123');

// Returns:
// [
//     'final_amount' => 90.00,
//     'discount_amount' => 10.00,
//     'applied_discounts' => [...],
//     'transaction_id' => 'transaction_123'
// ]
```

## Configuration

Publish the config file and customize:

```php
// config/discounts.php
'stacking_order' => [
    'priority' => 'asc', // Lower priority numbers applied first
    'id' => 'asc',
],
'max_percentage_cap' => 100,
'rounding' => 'nearest', // 'up', 'down', 'nearest', 'none'
'rounding_precision' => 2,
```

## Events

- `DiscountAssigned` - Fired when a discount is assigned
- `DiscountRevoked` - Fired when a discount is revoked
- `DiscountApplied` - Fired when a discount is applied

## Testing

Run the test suite:

```bash
php artisan test --testsuite=Package
```

## Requirements

- PHP ^8.2
- Laravel ^12.0

