## Simple Reward

`wtroiano/simple-reward` provides a Laravel 13 reward ledger on PHP 8.5.
Use the `simple-reward-development` skill when implementing ledger operations,
reward rule classes, or their application tests.

### Integration

Add `Wtroiano\SimpleReward\Traits\HasRewards` to a saved Eloquent model.
Publish the base migration with `php artisan vendor:publish --tag=simple-reward-migrations`.
First publication uses the current timestamp; repeated publication reuses the existing file.
Optional configuration uses `--tag=simple-reward-config` and the `simple-reward` key.
Set `table_name` before migrating; its default is `rewards`.
Preserve the migration's explicit index names when customizing polymorphic columns;
they remain bounded for long table names and connection prefixes.

Use `rewardCredit()`, `rewardDebit()` and `rewardTransfer()` for writes. Never insert,
edit, delete or backdate ledger entries directly. One owner has one balance; metadata
does not create separate currencies or reward balances. The application owns
authorization, tenant and currency policies, and idempotency for direct ledger calls.

Amounts round to two decimals and must be between `0.01` and `99999999.99` after rounding.
Credits and debits return `Wtroiano\SimpleReward\Models\Reward`. `hasRewards()` is a snapshot;
handle `Wtroiano\SimpleReward\Exceptions\InsufficientRewardException` on the actual debit
or transfer. Transfers require distinct saved owners of the same model class and connection.
Owners and ledger must share the owner's connection.

### Rule classes

`Wtroiano\SimpleReward\RewardRules` applies fixed awards from registered rule classes.
Owners additionally implement `Wtroiano\SimpleReward\Contracts\Rewardable`; `HasRewards`
supplies its method. Rules use the same rewards table and migration as direct operations.
Each rule credit stores a nullable unique `rule_application_key`; ordinary entries leave it null.

Register class names in `simple-reward.rules`. Each class implements
`Wtroiano\SimpleReward\Contracts\RewardRule` and returns a readonly `Rules\RuleDefinition`
using `FixedAward`, `AllOf`, `Condition` and the `Operator` enum from the same namespace.
Raw rule arrays and rule instances in configuration are rejected. Keep rule construction
and `definition()` free of side effects. `FixedAward` takes a positive decimal string
with at most two decimals. Conditions use nested array paths and strict equality or
numeric comparisons; missing fields never match.

`RewardRules::apply()` takes an owner, event name, stable source ID and trusted context.
It returns only newly created credits, in configuration order. Credits and their rule
application keys commit atomically. Duplicate prevention covers owner morph type/ID,
rule key and exact source ID, excluding version and event. Preserve those identifiers
and ledger entries with their application keys; a new rule key creates a new entitlement.

### Events and queries

`RewardsCredited`, `RewardsDebited` and `RewardsTransferred` under
`Wtroiano\SimpleReward\Events` dispatch after the outer transaction commits. Rollbacks
discard events. A listener failure cannot undo committed awards; repeating an applied
rule skips it without replaying its event. The application owns durable delivery and retries.

Use `rewardBalance()`, `rewardHistory()`, `rewardBalanceAt()` and the `rewards()` relation
for reads. Metadata scopes accept dot-separated paths; group OR scopes in a `where`
closure to preserve owner scoping. `whereMetaHas` means non-null; `whereMetaNull` includes
absent keys. Rule metadata contains `rule.key`, `rule.version`, `source.event` and `source.id`.

Writes lock owner rows. MySQL/InnoDB and PostgreSQL support these locks; SQLite tests
do not establish equivalent concurrency behavior. Consumer docs are installed under
`vendor/wtroiano/simple-reward/docs/`, including `consumers/rules.md` and `architecture.md`.
