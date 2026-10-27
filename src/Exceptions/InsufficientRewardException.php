<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Exceptions;

use Exception;

final class InsufficientRewardException extends Exception
{
    public function __construct(float $requested, float $available)
    {
        parent::__construct(
            sprintf(
                'Insufficient rewards. Requested: %s, Available: %s',
                $requested,
                $available
            )
        );
    }
}
