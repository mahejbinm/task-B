<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stacking Order
    |--------------------------------------------------------------------------
    |
    | Defines the order in which discounts are applied. Discounts are sorted
    | by priority (lower number = higher priority) and then by ID for
    | deterministic ordering.
    |
    */
    'stacking_order' => [
        'priority' => 'asc', // 'asc' or 'desc' - lower priority numbers applied first
        'id' => 'asc', // 'asc' or 'desc' - for deterministic ordering when priorities are equal
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Percentage Cap
    |--------------------------------------------------------------------------
    |
    | The maximum total discount percentage that can be applied. If stacking
    | would exceed this value, discounts are capped at this percentage.
    |
    */
    'max_percentage_cap' => 100, // Maximum total discount percentage

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    |
    | Defines how discount amounts are rounded.
    | Options: 'up', 'down', 'nearest', 'none'
    |
    */
    'rounding' => 'nearest', // 'up', 'down', 'nearest', 'none'

    /*
    |--------------------------------------------------------------------------
    | Rounding Precision
    |--------------------------------------------------------------------------
    |
    | Number of decimal places for rounding.
    |
    */
    'rounding_precision' => 2,
];

