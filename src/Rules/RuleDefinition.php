<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Rules;

use InvalidArgumentException;

final readonly class RuleDefinition
{
    public function __construct(
        public string $key,
        public int $version,
        public string $event,
        public FixedAward $award,
        public AllOf $conditions = new AllOf,
        public bool $enabled = true,
    ) {
        throw_unless(preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $key) === 1, InvalidArgumentException::class, 'Rule keys must contain 1-64 lowercase letters, digits, dots, underscores or hyphens and start with a letter or digit.');
        throw_if($version <= 0 || $version > 2147483647, InvalidArgumentException::class, 'Rule versions must be positive 32-bit integers.');
        throw_if(mb_trim($event) === '' || mb_strlen($event) > 255, InvalidArgumentException::class, 'Rules require an event name of 1-255 characters.');
    }

    /** @param array<string, mixed> $context */
    public function matches(string $event, array $context): bool
    {
        return $this->enabled && $this->event === $event && $this->conditions->matches($context);
    }
}
