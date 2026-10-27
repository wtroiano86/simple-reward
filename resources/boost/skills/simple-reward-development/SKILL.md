---
name: simple-reward-development
description: Integrate wtroiano/simple-reward into Laravel applications. Use when implementing reward credits, debits, transfers, typed reward rule classes, metadata queries, or tests for duplicate awards, rollback and event timing.
---

# Simple Reward application development

Use this skill in applications consuming `wtroiano/simple-reward` on PHP 8.5 / Laravel 13.
Follow the application's existing model, listener, job and test conventions.

## Choose the integration

- For direct credits, debits or transfers, use `Wtroiano\SimpleReward\Traits\HasRewards` on a saved Eloquent model. Application code owns authorization and duplicate-request prevention.
- For fixed awards triggered by a business event, use `Wtroiano\SimpleReward\RewardRules` and explicit rule classes. The owner also implements `Wtroiano\SimpleReward\Contracts\Rewardable`; `HasRewards` provides the required method.
- For history and reporting, query the `rewards()` relation or the trait's history helpers. Do not use queries to insert, update, delete or backdate ledger entries. Metadata does not partition the owner's balance.

Inspect existing migrations and configuration before adding setup. Direct operations and rules share the rewards table, configured by `simple-reward.table_name` (default `rewards`) and published with `simple-reward-migrations`. Each rule credit stores a nullable unique `rule_application_key` in the same row as its amount, balance and metadata. Ordinary credits, debits and transfers leave that key null. The ledger must be on the owner's connection. Adapt its polymorphic ID columns for UUID/ULID owners before migration, preserving the explicit index-name argument that keeps names bounded for long tables and prefixes. Changing table configuration later does not migrate existing data.

Read the installed package's consumer docs for details needed by the task, normally under `vendor/wtroiano/simple-reward/docs/`: `consumers/getting-started.md` for migrations and models, `consumers/rules.md` for rule options, `consumers/rewards.md` for ledger methods/events, and `consumers/metadata.md` for scopes. These paths are relative to the consuming application, not the installed skill directory.

## Implement a rule class

Implement `Contracts\RewardRule::definition(): Rules\RuleDefinition`. Use typed objects and the `Operator` enum; array definitions, string operators and rule instances in configuration are unsupported. A rule can receive constructor dependencies through Laravel's container. Keep constructors and definitions free of writes and external effects: all registered definitions are resolved before the award transaction, including disabled or unrelated rules.

For example, adapt the namespace, threshold and currency to the application's policy:

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

Add `App\Rewards\QualifyingPurchase::class` to the existing `rules` list in `config/simple-reward.php`, preserving other settings and registered classes. Class names support Laravel's configuration cache. Refresh the consuming application's cache through its existing deployment workflow when registrations or configuration change.

Choose a unique, stable rule key (1–64 lowercase letters, digits, dots, underscores or hyphens, starting with a letter or digit) and a positive integer version. A `FixedAward` accepts a decimal string from `0.01` to `99999999.99`, with at most two decimals. `enabled` defaults to true. Omitting conditions means the rule qualifies for every occurrence of its event.

`AllOf` combines conditions with AND. `Equal` and `NotEqual` use strict PHP types: `5000`, `5000.0` and `'5000'` differ. Ordered comparisons accept integers or finite floats, not numeric strings. Missing paths never match, including `NotEqual`; explicit null can match `Equal` with null. Use trusted, normalized application data for context.

## Apply awards and preserve retries

Call the service from the application's existing listener, job or service after loading and authorizing the business record. Build the source ID from a stable business identity, including tenant identity when needed, rather than from a job attempt ID or timestamp:

```php
use Wtroiano\SimpleReward\RewardRules;

$credits = app(RewardRules::class)->apply(
    owner: $user,
    event: 'order.completed',
    sourceId: 'order:123',
    context: ['order' => ['total_minor' => 7500, 'currency' => 'USD']],
);
```

An empty result means no new credits: rules may be disabled, unrelated, ineligible or already applied. The service does not subscribe to application events automatically.

Duplicate identity consists of owner morph type/ID, rule key and exact source ID. Changing the amount, version or event does not reaward a source for the same key. A new key creates a separate entitlement. Keep keys, owner identities and morph aliases stable; do not edit ledger entries or clear their rule application keys to retry. Failed or rolled-back attempts leave no credit or key and can be retried normally.

All new credits and their rule application keys in one call share a transaction on the owner's connection. The service supplies the key through `rewardCredit()`; ordinary callers leave that argument null. Package events dispatch only after the outermost commit. An after-commit listener failure does not undo the award; retrying `apply()` skips it and does not replay that event. Use the application's durable delivery mechanism for downstream retries. Audit metadata stores rule key/version and source event/ID; it does not store the full context.

## Direct operations and queries

Pass positive amounts to both `rewardCredit()` and `rewardDebit()`; their operation determines the sign. Amounts round to two decimals. `hasRewards()` is a snapshot, so handle `InsufficientRewardException` from the actual debit or transfer. Transfers require distinct saved owners of the same class and connection. Do not enable negative balances to bypass a failed business operation.

Metadata paths use dots, such as `rule.key` and `source.id`. Group OR metadata scopes in a `where` closure so they cannot bypass the owner relationship constraint. `whereMetaHas` means non-null; `whereMetaNull` includes missing paths. Use the relation for pagination instead of expanding bounded history helper limits.

## Verify application behavior

Use the application's existing test stack. For the behavior being introduced, cover eligible and ineligible context, threshold boundaries and strict types, repeat delivery of the same source, and separation between owners or sources. When changing transactional integration, cover rollback of credits and their application keys together and events after the outer commit. Use real listeners when verifying event timing so an event fake does not hide the transaction boundary.

Assert persisted balances, ledger counts and rule application keys through the public flow; do not create ledger rows directly in fixtures. SQLite tests establish functional behavior, not production row-lock contention guarantees. Run the relevant application checks and report the database and checks actually used.
