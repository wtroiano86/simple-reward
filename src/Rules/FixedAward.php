<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Rules;

use InvalidArgumentException;

final readonly class FixedAward
{
    public float $amount;

    public function __construct(string $amount, public ?string $description = null)
    {
        throw_unless(preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $amount) === 1 && (float) $amount > 0, InvalidArgumentException::class, 'Fixed amounts must be decimal strings between 0.01 and 99999999.99 with at most two decimal places.');
        throw_if($description !== null && mb_strlen($description) > 255, InvalidArgumentException::class, 'Award descriptions must contain at most 255 characters.');

        $this->amount = (float) $amount;
    }
}
