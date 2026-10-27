<?php

declare(strict_types=1);

return [
    'allow_negative_balance' => false,

    // Set before publishing and running the migration.
    'table_name' => 'rewards',

    // List of class names implementing Contracts\RewardRule; resolved through Laravel.
    'rules' => [],
];
