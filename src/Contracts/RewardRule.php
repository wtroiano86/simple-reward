<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Contracts;

use Wtroiano\SimpleReward\Rules\RuleDefinition;

interface RewardRule
{
    public function definition(): RuleDefinition;
}
