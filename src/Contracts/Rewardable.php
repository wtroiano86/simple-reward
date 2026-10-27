<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Contracts;

use Wtroiano\SimpleReward\Models\Reward;

interface Rewardable
{
    /** @param array<string, mixed> $metadata */
    public function rewardCredit(float $amount, ?string $description = null, array $metadata = [], ?string $ruleApplicationKey = null): Reward;
}
