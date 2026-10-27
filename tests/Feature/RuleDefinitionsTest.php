<?php

declare(strict_types=1);

use Wtroiano\SimpleReward\Rules\AllOf;
use Wtroiano\SimpleReward\Rules\Condition;
use Wtroiano\SimpleReward\Rules\FixedAward;
use Wtroiano\SimpleReward\Rules\Operator;
use Wtroiano\SimpleReward\Rules\RuleDefinition;

it('validates rule identity and event boundaries', function (string $key, int $version, string $event): void {
    expect(fn (): RuleDefinition => new RuleDefinition($key, $version, $event, new FixedAward('1')))->toThrow(InvalidArgumentException::class);
})->with([
    ['', 1, 'signup'], ['Uppercase', 1, 'signup'], [str_repeat('a', 65), 1, 'signup'],
    ['welcome', 0, 'signup'], ['welcome', 2147483648, 'signup'],
    ['welcome', 1, ''], ['welcome', 1, ' '], ['welcome', 1, str_repeat('e', 256)],
]);

it('rejects invalid fixed award amounts', function (string $amount): void {
    expect(fn (): FixedAward => new FixedAward($amount))->toThrow(InvalidArgumentException::class);
})->with(['0', '-1', '0.001', '1e2', '100000000', 'NaN', ' 25.00', '01', '', '25.000']);

it('rejects oversized award descriptions', function (): void {
    expect(fn (): FixedAward => new FixedAward('25.00', str_repeat('x', 256)))->toThrow(InvalidArgumentException::class);
});

it('accepts exact decimal amount boundaries', function (string $amount, float $expected): void {
    expect((new FixedAward($amount))->amount)->toBe($expected);
})->with([['0.01', 0.01], ['99999999.99', 99999999.99], ['25', 25.0], ['25.1', 25.1]]);

it('validates condition paths and comparison values', function (string $field, Operator $operator, bool|float|int|string|null $value): void {
    expect(fn (): Condition => new Condition($field, $operator, $value))->toThrow(InvalidArgumentException::class);
})->with([
    ['', Operator::Equal, 1], ['order..total', Operator::Equal, 1], ['order.*', Operator::Equal, 1],
    ['a', Operator::Equal, INF], ['a', Operator::Equal, NAN],
    ['a', Operator::GreaterThan, '1'], ['a', Operator::LessThan, null],
]);

it('evaluates typed operators without coercion or executable expressions', function (Operator $operator, bool|float|int|string|null $expected, array $context, bool $matches): void {
    $condition = new Condition('order.value', $operator, $expected);
    expect($condition->matches($context))->toBe($matches);
})->with([
    [Operator::Equal, 'gold', ['order' => ['value' => 'gold']], true],
    [Operator::Equal, 1, ['order' => ['value' => '1']], false],
    [Operator::Equal, 1, ['order' => ['value' => 1.0]], false],
    [Operator::Equal, true, ['order' => ['value' => true]], true],
    [Operator::Equal, null, ['order' => ['value' => null]], true],
    [Operator::Equal, null, [], false],
    [Operator::NotEqual, null, [], false],
    [Operator::NotEqual, null, ['order' => ['value' => 'present']], true],
    [Operator::NotEqual, 1, ['order' => ['value' => 1]], false],
    [Operator::NotEqual, 1, ['order' => ['value' => []]], false],
    [Operator::NotEqual, 1, ['order' => ['value' => new stdClass]], false],
    [Operator::NotEqual, 1, ['order' => ['value' => INF]], false],
    [Operator::NotEqual, 1, ['order' => ['value' => NAN]], false],
    [Operator::NotEqual, 1, ['order' => 'invalid'], false],
    [Operator::GreaterThan, 1, ['order' => ['value' => '2']], false],
    [Operator::GreaterThan, 1, ['order' => ['value' => true]], false],
    [Operator::GreaterThan, 1, ['order' => ['value' => 2]], true],
    [Operator::GreaterThan, 1, ['order' => ['value' => 1]], false],
    [Operator::GreaterThanOrEqual, 1, ['order' => ['value' => 1]], true],
    [Operator::GreaterThanOrEqual, 1, ['order' => ['value' => 0]], false],
    [Operator::LessThan, 1.5, ['order' => ['value' => 1]], true],
    [Operator::LessThan, 1.5, ['order' => ['value' => 1.5]], false],
    [Operator::LessThanOrEqual, 1.5, ['order' => ['value' => 1.5]], true],
    [Operator::LessThanOrEqual, 1.5, ['order' => ['value' => 2]], false],
]);

it('traverses numeric array segments and requires all typed conditions', function (): void {
    $conditions = new AllOf(
        new Condition('items.0.sku', Operator::Equal, 'SKU-1'),
        new Condition('paid', Operator::Equal, true),
    );
    expect($conditions->matches(['items' => [['sku' => 'SKU-1']], 'paid' => true]))->toBeTrue()
        ->and($conditions->matches(['items' => [['sku' => 'SKU-1']], 'paid' => false]))->toBeFalse()
        ->and((new AllOf)->matches([]))->toBeTrue();
});

it('uses native types to reject raw conditions and operator strings', function (): void {
    expect(fn (): AllOf => new AllOf(['field' => 'total']))->toThrow(TypeError::class)
        ->and(fn (): Condition => new Condition('total', '>=', 5000))->toThrow(TypeError::class);
});
