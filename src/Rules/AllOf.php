<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Rules;

final readonly class AllOf
{
    /** @var list<Condition> */
    private array $conditions;

    public function __construct(Condition ...$conditions)
    {
        $this->conditions = array_values($conditions);
    }

    /** @param array<string, mixed> $context */
    public function matches(array $context): bool
    {
        return array_all($this->conditions, fn (Condition $condition): bool => $condition->matches($context));
    }
}
