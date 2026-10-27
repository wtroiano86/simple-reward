# Reward rule classes

`Wtroiano\SimpleReward\RewardRules` resolves application rule classes and applies their typed conditions and fixed credits through the existing ledger. Rules are optional; direct credits, debits and transfers keep their existing API.

## Setup

Install the [ledger](getting-started.md) and publish configuration to register rule classes. For a fresh installation:

```sh
php artisan vendor:publish --tag=simple-reward-config
php artisan vendor:publish --tag=simple-reward-migrations
php artisan migrate
```

Rules use the same rewards table and migration as direct ledger operations. Each rule credit stores its unique `rule_application_key` alongside its balance and metadata. Ordinary ledger writes leave this nullable column empty. The ledger must use the owner's connection; see [getting started](getting-started.md) for custom table names and UUID/ULID owners.

Models used with the rules service must implement `Rewardable`. `HasRewards` already supplies its method:

```php
declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Wtroiano\SimpleReward\Contracts\Rewardable;
use Wtroiano\SimpleReward\Traits\HasRewards;

class User extends Model implements Rewardable
{
    use HasRewards;
}
```

The interface is required only for the rules service. Existing models using the ledger directly do not need to change.

## Define a rule class

Implement `Contracts\RewardRule` with a `definition()` method returning a readonly `Rules\RuleDefinition`. Conditions, operators and awards have explicit types:

```php
declare(strict_types=1);

namespace App\Rewards;

use Wtroiano\SimpleReward\Contracts\RewardRule;
use Wtroiano\SimpleReward\Rules\AllOf;
use Wtroiano\SimpleReward\Rules\Condition;
use Wtroiano\SimpleReward\Rules\FixedAward;
use Wtroiano\SimpleReward\Rules\Operator;
use Wtroiano\SimpleReward\Rules\RuleDefinition;

final class QualifyingPurchase implements RewardRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            key: 'qualifying_purchase',
            version: 1,
            event: 'order.completed',
            award: new FixedAward('25.00', 'Qualifying purchase bonus'),
            conditions: new AllOf(
                new Condition('order.total_minor', Operator::GreaterThanOrEqual, 5000),
                new Condition('order.currency', Operator::Equal, 'USD'),
            ),
        );
    }
}
```

Register class names in `config/simple-reward.php`:

```php
declare(strict_types=1);

use App\Rewards\QualifyingPurchase;

return [
    'allow_negative_balance' => false,
    'table_name' => 'rewards',
    'rules' => [QualifyingPurchase::class],
];
```

Configuration contains class names, so it works with Laravel's configuration cache. Raw rule arrays and rule instances are rejected. Refresh the cache when changing registered classes. The service resolves each class through Laravel's container on every call, so rule constructors can receive application dependencies. Keep constructors and `definition()` free of writes and external effects: all definitions, including disabled or unrelated rules, are resolved before the award transaction, and calls may be retried.

Rule classes can read application configuration to choose amounts, thresholds or enabled flags, then pass those values to the typed constructors. The objects validate values at construction. Invalid values or duplicate rule keys throw `InvalidArgumentException`; incorrect PHP types raise `TypeError`, and container resolution failures propagate. The business-event context remains a nested array because its fields belong to the application.

| Type / parameter | Accepted values |
| --- | --- |
| `RuleDefinition::key` | Unique across the registered rules; 1–64 lowercase letters, digits, dots, underscores or hyphens; starts with a letter or digit |
| `RuleDefinition::version` | Integer from 1 to 2147483647; recorded for audit |
| `RuleDefinition::event` | Nonblank string, at most 255 characters; exact match |
| `RuleDefinition::enabled` | Boolean; defaults to `true` |
| `RuleDefinition::conditions` | `AllOf` containing typed `Condition` arguments; defaults to an empty group, which always qualifies |
| `RuleDefinition::award` | `FixedAward`; this release supports fixed credits |
| `FixedAward::amount` constructor argument | Decimal string from `0.01` to `99999999.99`, with at most two decimals; no exponents, signs or leading zeroes; normalized to a float for the ledger |
| `FixedAward::description` | Optional null or string of at most 255 characters |

`Condition` requires a field path, an `Operator` enum and a scalar/null comparison value. Fields traverse nested arrays using dot-separated letters, digits and underscores, including numeric array indexes. Wildcards and object traversal are unsupported.

`Operator::Equal` and `Operator::NotEqual` use strict PHP equality: `1`, `1.0` and `'1'` differ. `GreaterThan`, `GreaterThanOrEqual`, `LessThan` and `LessThanOrEqual` require integer or finite float values on both sides. Numeric strings are not coerced. Missing fields never match, including `NotEqual`; explicit null can match `Equal` with null. Arrays, objects and nonfinite context values never match. `AllOf` combines conditions with AND and short-circuits at the first mismatch.

## Apply an event

Call the service from an application listener, job or service after authorizing the action and loading trusted business data:

```php
use Wtroiano\SimpleReward\RewardRules;

$credits = app(RewardRules::class)->apply(
    owner: $user,
    event: 'order.completed',
    sourceId: 'order:123',
    context: [
        'order' => [
            'total_minor' => 7500,
            'currency' => 'USD',
        ],
    ],
);
```

`apply()` returns a list of newly created `Reward` credits in configuration order. Disabled, unrelated, ineligible and already-applied rules are skipped. If none creates a credit, the result is `[]`. The owner must be saved. Event names and source IDs must be nonblank strings of at most 255 characters.

Each credit carries this metadata; the full context is not persisted:

```json
{
  "rule": {"key": "qualifying_purchase", "version": 1},
  "source": {"event": "order.completed", "id": "order:123"}
}
```

Use the existing [metadata queries](metadata.md) to find these awards, for example `whereMeta('source.id', 'order:123')`. Metadata remains context for the owner's single balance.

## Transactions and retries

All eligible credits from a call share one transaction on the owner's connection. The service locks the saved owner row before checking the ledger for duplicates, then uses `rewardCredit()` for each award. The rule application key is persisted in the same insert as its credit. If any write fails, every new credit and key from the call rolls back. Ledger events dispatch after the outermost transaction commits and are discarded on rollback.

The database enforces uniqueness on the nullable `rule_application_key` column of the configured rewards table, containing a SHA-256 key over a JSON tuple of the owner's morph type, string-normalized owner ID, rule key and exact source ID. Version and event name are deliberately excluded. A repeat request returns no new credit for an already-applied rule, even after its amount, version or event changes. A failed or rolled-back attempt leaves no credit or key and can be retried. Ineligible evaluations do not consume a source.

Keep owner identifiers, morph aliases, rule keys and source IDs stable. Use a stable business identity such as `order:123`, including tenant identifiers when business IDs are only unique within a tenant. Changing a rule key creates a distinct reward entitlement. Adding a new rule can award an old source when it is processed again. Do not delete or edit ledger entries or their rule application keys: they preserve balances and duplicate prevention. Previously applied rewards are not recalculated or reversed by configuration changes.

Applications still own authorization, trusted context, currency/tenant eligibility, event delivery and retries. There is no automatic subscription to business events or database-backed rule editor. Ordinary direct ledger calls leave the key null; use `RewardRules` for rule eligibility and skipping duplicate awards. A listener failure after commit cannot undo the award; retrying `apply()` skips it and does not replay its event. Use application-managed durable delivery when downstream processing needs retries.

MySQL/InnoDB and PostgreSQL provide the row locks used here. SQLite functional tests do not establish equivalent concurrent-write guarantees; see [architecture](../architecture.md).

The service supplies `rewardCredit()` with its optional `ruleApplicationKey` argument. Ordinary callers should leave it null. Non-null keys must be 64 lowercase hexadecimal characters; a database uniqueness violation aborts the credit transaction. Rule/source metadata alone does not enforce uniqueness.
