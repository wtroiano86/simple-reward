<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Wtroiano\SimpleReward\Events\RewardsCredited;
use Wtroiano\SimpleReward\Models\Reward;
use Wtroiano\SimpleReward\RewardRules;
use Wtroiano\SimpleReward\Rules\AllOf;
use Wtroiano\SimpleReward\Rules\Condition;
use Wtroiano\SimpleReward\Rules\FixedAward;
use Wtroiano\SimpleReward\Rules\Operator;
use Wtroiano\SimpleReward\Rules\RuleDefinition;
use Wtroiano\SimpleReward\Tests\Support\Account;
use Wtroiano\SimpleReward\Tests\Support\AdditionalRule;
use Wtroiano\SimpleReward\Tests\Support\PurchaseRule;

function purchaseRule(
    int $version = 1,
    string $event = 'order.completed',
    bool $enabled = true,
    ?AllOf $conditions = null,
    ?FixedAward $award = null,
): RuleDefinition {
    return new RuleDefinition(
        key: 'qualifying_purchase',
        version: $version,
        event: $event,
        award: $award ?? new FixedAward('25.00', 'Purchase bonus'),
        conditions: $conditions ?? new AllOf(new Condition('order.total_minor', Operator::GreaterThanOrEqual, 5000)),
        enabled: $enabled,
    );
}

beforeEach(function (): void {
    app()->instance(RuleDefinition::class, purchaseRule());
    config(['simple-reward.rules' => [PurchaseRule::class]]);
});

it('credits eligible rules and stores their audit trail before dispatching events after the outer commit', function (): void {
    $owner = Account::query()->create();
    $keysAtCreation = [];
    Event::listen('eloquent.creating: '.Reward::class, function (Reward $reward) use (&$keysAtCreation): void {
        $keysAtCreation[] = $reward->rule_application_key;
    });
    $events = [];
    Event::listen(RewardsCredited::class, function (RewardsCredited $event) use (&$events): void {
        $events[] = [$event, Reward::query()->whereNotNull('rule_application_key')->count()];
    });

    DB::beginTransaction();
    $entries = resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]);
    expect($events)->toBe([])->and($entries)->toHaveCount(1);
    DB::commit();

    $entry = $entries[0];
    expect($owner->rewardBalance())->toBe(25.0)
        ->and($entry->amount)->toBe('25.00')
        ->and($entry->description)->toBe('Purchase bonus')
        ->and($entry->metadata)->toBe([
            'rule' => ['key' => 'qualifying_purchase', 'version' => 1],
            'source' => ['event' => 'order.completed', 'id' => 'order:123'],
        ])
        ->and($entry->fresh()->rule_application_key)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($entry->rewardable_id)->toBe($owner->id)
        ->and($entry->rewardable_type)->toBe(Account::class)
        ->and($keysAtCreation)->toBe([$entry->rule_application_key])
        ->and($events)->toHaveCount(1)
        ->and($events[0][0]->transactionId)->toBe($entry->id)
        ->and($events[0][1])->toBe(1);
});

it('skips duplicate awards even after the rule version amount and event change', function (): void {
    $owner = Account::query()->create();
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];
    $engine->apply($owner, 'order.completed', 'order:123', $context);
    $events = [];
    Event::listen(RewardsCredited::class, function (RewardsCredited $event) use (&$events): void {
        $events[] = $event;
    });
    expect($engine->apply($owner, 'order.completed', 'order:123', $context))->toBe([]);

    app()->instance(RuleDefinition::class, purchaseRule(version: 2, event: 'order.updated', award: new FixedAward('50.00')));
    expect($engine->apply($owner, 'order.updated', 'order:123', $context))->toBe([])
        ->and($owner->rewardBalance())->toBe(25.0)
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(1)
        ->and($events)->toBe([]);
});

it('scopes duplicate prevention to each owner rule and exact source ID', function (): void {
    $owner = Account::query()->create();
    $other = Account::query()->create();
    config(['simple-reward.rules' => [PurchaseRule::class, AdditionalRule::class]]);
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];

    $first = $engine->apply($owner, 'order.completed', 'order:ABC', $context);
    expect(array_column(array_map(fn (Reward $entry): array => $entry->metadata['rule'], $first), 'key'))->toBe(['qualifying_purchase', 'additional_bonus']);
    $engine->apply($other, 'order.completed', 'order:ABC', $context);
    $engine->apply($owner, 'order.completed', 'order:abc', $context);
    expect($owner->rewardBalance())->toBe(100.0)
        ->and($other->rewardBalance())->toBe(50.0)
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(6);
});

it('checks all matching rules in one query while awarding only unapplied rules', function (): void {
    $owner = Account::query()->create();
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];
    $engine->apply($owner, 'order.completed', 'order:123', $context);
    config(['simple-reward.rules' => [PurchaseRule::class, AdditionalRule::class]]);

    $lookups = 0;
    DB::listen(function (QueryExecuted $query) use (&$lookups): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'rule_application_key')) {
            $lookups++;
        }
    });

    $entries = $engine->apply($owner, 'order.completed', 'order:123', $context);
    expect($lookups)->toBe(1)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->metadata['rule']['key'])->toBe('additional_bonus')
        ->and($owner->rewardBalance())->toBe(50.0);

    expect($engine->apply($owner, 'order.completed', 'order:123', $context))->toBe([])
        ->and($lookups)->toBe(2)
        ->and($owner->rewards()->count())->toBe(2);
});

it('enforces rule application uniqueness on the ledger and rolls back duplicate credits', function (): void {
    $owner = Account::query()->create();
    $entries = resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]);
    $events = [];
    Event::listen(RewardsCredited::class, function (RewardsCredited $event) use (&$events): void {
        $events[] = $event;
    });

    expect(fn () => $owner->rewardCredit(1, ruleApplicationKey: $entries[0]->rule_application_key))->toThrow(QueryException::class);
    expect($owner->rewards()->count())->toBe(1)
        ->and($owner->rewardBalance())->toBe(25.0)
        ->and($events)->toBe([]);
});

it('does not consume sources for ineligible disabled or unrelated rules', function (): void {
    $owner = Account::query()->create();
    $engine = resolve(RewardRules::class);
    expect($engine->apply($owner, 'order.completed', 'order:123'))->toBe([])
        ->and($engine->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 4999]]))->toBe([])
        ->and($engine->apply($owner, 'order.cancelled', 'order:123', ['order' => ['total_minor' => 7500]]))->toBe([]);
    app()->instance(RuleDefinition::class, purchaseRule(enabled: false));
    expect($engine->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]))->toBe([])
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(0);
    app()->instance(RuleDefinition::class, purchaseRule());
    config(['simple-reward.rules' => [PurchaseRule::class]]);
    expect($engine->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 5000]]))->toHaveCount(1);
});

it('supports unconditional rules with default enabled and nullable description', function (): void {
    app()->instance(RuleDefinition::class, new RuleDefinition('welcome', 1, 'order.completed', new FixedAward('25.00')));
    $owner = Account::query()->create();
    $entries = resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123');
    expect($entries)->toHaveCount(1)->and($entries[0]->description)->toBeNull();
});

it('rolls back all matching rule credits keys and events when a later credit overflows', function (): void {
    $owner = Account::query()->create();
    $owner->rewardCredit(99999960.0);

    config(['simple-reward.rules' => [PurchaseRule::class, AdditionalRule::class]]);
    $events = [];
    Event::listen(RewardsCredited::class, function (RewardsCredited $event) use (&$events): void {
        $events[] = $event;
    });

    expect(fn () => resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]))->toThrow(InvalidArgumentException::class);
    expect($owner->rewardBalance())->toBe(99999960.0)
        ->and($owner->rewards()->count())->toBe(1)
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(0)
        ->and($events)->toBe([]);

    app()->instance(RuleDefinition::class, purchaseRule());
    config(['simple-reward.rules' => [PurchaseRule::class]]);
    expect(resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]))->toHaveCount(1);
});

it('rolls back all awards if a later ledger insert fails and permits retry', function (): void {
    $owner = Account::query()->create();
    config(['simple-reward.rules' => [PurchaseRule::class, AdditionalRule::class]]);
    $events = [];
    Event::listen(RewardsCredited::class, function (RewardsCredited $event) use (&$events): void {
        $events[] = $event;
    });
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts): void {
        if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'rewards')) {
            $inserts++;
            throw_if($inserts === 2, RuntimeException::class, 'Ledger persistence failed.');
        }
    });

    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];
    expect(fn () => $engine->apply($owner, 'order.completed', 'order:123', $context))->toThrow(RuntimeException::class, 'Ledger persistence failed.');
    expect($owner->rewardBalance())->toBe(0.0)
        ->and($owner->rewards()->count())->toBe(0)
        ->and($events)->toBe([]);
    expect($engine->apply($owner, 'order.completed', 'order:123', $context))->toHaveCount(2)
        ->and($owner->rewardBalance())->toBe(50.0)
        ->and($events)->toHaveCount(2);
});

it('allows retry after an outer rollback without dispatching rolled back events', function (): void {
    $owner = Account::query()->create();
    $events = [];
    Event::listen(RewardsCredited::class, function (RewardsCredited $event) use (&$events): void {
        $events[] = $event;
    });
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];
    DB::beginTransaction();
    $engine->apply($owner, 'order.completed', 'order:123', $context);
    DB::rollBack();
    expect($owner->rewardBalance())->toBe(0.0)
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(0)
        ->and($events)->toBe([]);
    expect($engine->apply($owner, 'order.completed', 'order:123', $context))->toHaveCount(1)
        ->and($events)->toHaveCount(1);
});

it('uses the configured ledger table owner connection and morph map', function (): void {
    config(['simple-reward.table_name' => 'custom_rewards']);
    $ledger = require __DIR__.'/../../database/migrations/create_rewards_table.php.stub';
    $ledger->up();

    Relation::enforceMorphMap(['account' => Account::class]);
    $owner = Account::query()->create();
    config(['database.default' => 'unconfigured']);

    try {
        $engine = resolve(RewardRules::class);
        $context = ['order' => ['total_minor' => 7500]];
        $entries = $engine->apply($owner, 'order.completed', 'order:123', $context);
        expect($entries)->toHaveCount(1)
            ->and($owner->rewardBalance())->toBe(25.0)
            ->and($entries[0]->rewardable_type)->toBe('account')
            ->and($entries[0]->fresh()->rule_application_key)->not->toBeNull()
            ->and($engine->apply($owner, 'order.completed', 'order:123', $context))->toBe([]);
    } finally {
        config(['database.default' => 'testing']);
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }

    $ledger->down();
    expect(Schema::hasTable('custom_rewards'))->toBeFalse();
});

it('keeps committed rule credits when an after-commit listener fails and skips them on retry', function (): void {
    $owner = Account::query()->create();
    $calls = 0;
    Event::listen(RewardsCredited::class, function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('Listener failed.');
    });
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];
    expect(fn () => $engine->apply($owner, 'order.completed', 'order:123', $context))->toThrow(RuntimeException::class, 'Listener failed.');
    expect($owner->rewardBalance())->toBe(25.0)
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(1)
        ->and($engine->apply($owner, 'order.completed', 'order:123', $context))->toBe([])
        ->and($calls)->toBe(1);
});

it('requires every condition and handles repeat calls within the same outer transaction', function (): void {
    app()->instance(RuleDefinition::class, purchaseRule(conditions: new AllOf(
        new Condition('order.total_minor', Operator::GreaterThanOrEqual, 5000),
        new Condition('order.currency', Operator::Equal, 'USD'),
    )));
    $owner = Account::query()->create();
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500, 'currency' => 'USD']];

    expect($engine->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]))->toBe([]);
    DB::beginTransaction();
    expect($engine->apply($owner, 'order.completed', 'order:123', $context))->toHaveCount(1)
        ->and($engine->apply($owner, 'order.completed', 'order:123', $context))->toBe([]);
    DB::commit();
    expect($owner->rewardBalance())->toBe(25.0)->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(1);
});

it('rejects unsaved deleted or keyless owners', function (): void {
    $engine = resolve(RewardRules::class);
    expect(fn () => $engine->apply(new Account, 'order.completed', 'order:123'))->toThrow(LogicException::class);
    $owner = Account::query()->create();
    Account::query()->whereKey($owner->id)->delete();
    expect(fn () => $engine->apply($owner, 'order.completed', 'order:123'))->toThrow(ModelNotFoundException::class);
    $owner->setAttribute('id', null);
    expect(fn () => $engine->apply($owner, 'order.completed', 'order:123'))->toThrow(LogicException::class);
});

it('rejects invalid event and source identifiers', function (string $event, string $source): void {
    expect(fn () => resolve(RewardRules::class)->apply(Account::query()->create(), $event, $source))->toThrow(InvalidArgumentException::class);
})->with([
    ['', 'order:1'], [' ', 'order:1'], [str_repeat('e', 256), 'order:1'],
    ['order.completed', ''], ['order.completed', ' '], ['order.completed', str_repeat('s', 256)],
]);

it('rejects invalid configuration before writing any awards', function (mixed $rules): void {
    config(['simple-reward.rules' => $rules]);
    $owner = Account::query()->create();
    expect(fn () => resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123', ['order' => ['total_minor' => 7500]]))->toThrow(InvalidArgumentException::class);
    expect($owner->rewardBalance())->toBe(0.0)->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(0);
})->with([
    'not a list' => 'bad',
    'null list' => [null],
    'associative list' => [['rule' => []]],
    'invalid entry' => [[false]],
    'duplicate keys' => [[PurchaseRule::class, PurchaseRule::class]],
    'later invalid rule' => [[PurchaseRule::class, []]],
    'legacy array definition' => [[['key' => 'welcome', 'version' => 1]]],
    'unrelated class' => [[stdClass::class]],
    'unknown class' => [['MissingRewardRule']],
]);

it('supports an empty rule list', function (): void {
    config(['simple-reward.rules' => []]);
    expect(resolve(RewardRules::class)->apply(Account::query()->create(), 'order.completed', 'order:123'))->toBe([]);
});

it('leaves direct credits debits and transfers unkeyed even with rule metadata', function (): void {
    $owner = Account::query()->create();
    $recipient = Account::query()->create();
    $metadata = [
        'rule' => ['key' => 'qualifying_purchase', 'version' => 1],
        'source' => ['event' => 'order.completed', 'id' => 'order:123'],
    ];
    $owner->rewardCredit(10, metadata: $metadata);
    $owner->rewardCredit(10, metadata: $metadata);
    $owner->rewardDebit(1, metadata: $metadata);
    $owner->rewardTransfer($recipient, 2, metadata: $metadata);

    expect(Reward::query()->whereNull('rule_application_key')->count())->toBe(5);
    $engine = resolve(RewardRules::class);
    $context = ['order' => ['total_minor' => 7500]];
    expect($engine->apply($owner, 'order.completed', 'order:123', $context))->toHaveCount(1)
        ->and($engine->apply($owner, 'order.completed', 'order:123', $context))->toBe([])
        ->and(Reward::query()->whereNotNull('rule_application_key')->count())->toBe(1)
        ->and($owner->rewardBalance())->toBe(42.0)
        ->and($recipient->rewardBalance())->toBe(2.0);
});

it('rejects malformed rule application keys before writing credits', function (string $key): void {
    $owner = Account::query()->create();
    expect(fn () => $owner->rewardCredit(1, ruleApplicationKey: $key))->toThrow(InvalidArgumentException::class);
    expect($owner->rewards()->count())->toBe(0);
})->with(['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64)]);

it('rejects a container binding that does not resolve to a rule', function (): void {
    app()->bind(PurchaseRule::class, fn (): stdClass => new stdClass);
    $owner = Account::query()->create();
    expect(fn () => resolve(RewardRules::class)->apply($owner, 'order.completed', 'order:123'))->toThrow(InvalidArgumentException::class);
    expect($owner->rewardBalance())->toBe(0.0);
});
