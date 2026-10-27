<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Rules;

use InvalidArgumentException;

final readonly class Condition
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public bool|float|int|string|null $value,
    ) {
        throw_unless(preg_match('/\A[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*\z/', $field) === 1, InvalidArgumentException::class, 'Condition fields must be dot-separated array paths.');
        throw_if(is_float($value) && ! is_finite($value), InvalidArgumentException::class, 'Condition numbers must be finite.');
        throw_if(! in_array($operator, [Operator::Equal, Operator::NotEqual], true) && ! is_int($value) && ! is_float($value), InvalidArgumentException::class, 'Ordered comparisons require numeric values.');
    }

    /** @param array<string, mixed> $context */
    public function matches(array $context): bool
    {
        $actual = $context;

        foreach (explode('.', $this->field) as $segment) {
            if (! is_array($actual) || ! array_key_exists($segment, $actual)) {
                return false;
            }

            $actual = $actual[$segment];
        }

        if ((! is_scalar($actual) && $actual !== null) || (is_float($actual) && ! is_finite($actual))) {
            return false;
        }

        if ($this->operator === Operator::Equal) {
            return $actual === $this->value;
        }

        if ($this->operator === Operator::NotEqual) {
            return $actual !== $this->value;
        }

        if (! is_int($actual) && ! is_float($actual)) {
            return false;
        }

        return match ($this->operator) {
            Operator::GreaterThan => $actual > $this->value,
            Operator::GreaterThanOrEqual => $actual >= $this->value,
            Operator::LessThan => $actual < $this->value,
            Operator::LessThanOrEqual => $actual <= $this->value,
        };
    }
}
