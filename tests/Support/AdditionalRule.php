<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Tests\Support;

use Wtroiano\SimpleReward\Contracts\RewardRule;
use Wtroiano\SimpleReward\Rules\FixedAward;
use Wtroiano\SimpleReward\Rules\RuleDefinition;

final class AdditionalRule implements RewardRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            key: 'additional_bonus',
            version: 1,
            event: 'order.completed',
            award: new FixedAward('25.00'),
        );
    }
}
