<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Tests\Support;

use Wtroiano\SimpleReward\Contracts\RewardRule;
use Wtroiano\SimpleReward\Rules\RuleDefinition;

final readonly class PurchaseRule implements RewardRule
{
    public function __construct(private RuleDefinition $rule) {}

    public function definition(): RuleDefinition
    {
        return $this->rule;
    }
}
