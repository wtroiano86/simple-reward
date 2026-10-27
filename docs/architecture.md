# Architecture

`HasRewards` is the public integration point. It records `Reward` models through the `rewardable` polymorphic relation and reads the latest `running_balance` by ID. Credit and debit amounts remain positive; transaction type determines the balance change.

Each write runs on the owner's connection in a transaction with up to five attempts for retryable concurrency errors. It locks the saved owner row before reading the ledger. This lock exists even before the first reward transaction. The balance query also uses a locking read. Transfers lock both owners in deterministic table/key order and write both sides within a single transaction. All package events are deferred until the outer transaction commits.

The owner and ledger must be on the same connection. Transfers are restricted to the same model class. Applications own authorization, tenant policies, direct-ledger idempotency, and any currency or reward-category rules. There is one balance per owner; metadata is context, not a separate balance dimension.

The optional `RewardRules` service resolves registered classes implementing `Contracts\RewardRule` into readonly `RuleDefinition`, `AllOf`, `Condition` and `FixedAward` objects with an `Operator` enum, evaluates trusted context, and applies fixed credits through `HasRewards`. Its owner must be an Eloquent model implementing `Contracts\Rewardable`, which the trait satisfies. All new awards in one call share a transaction and a saved-owner lock. Each rule credit stores a nullable, uniquely indexed `rule_application_key` on the rewards row, inserted with its balance and audit metadata. The key hashes owner morph type/ID, rule key and source ID; version and event are audit context, not identity. Duplicate awards are skipped, and failures roll back credits and their keys together. Ordinary credits, debits and transfers leave the key null. The single ledger migration supports both direct operations and rules. See [rule definitions and retry semantics](consumers/rules.md).

After locking the owner, rule application fetches existing keys for all matching rules in one locking query. It checks those keys in memory and creates missing awards in configuration order. Calls without matching rules skip this lookup.

Use trait methods for all writes. Direct updates, inserts, deletes, or backdated timestamps bypass balance invariants. The package does not enforce an immutable database ledger or prevent administrative changes. Deleting an owner does not automatically delete its polymorphic history.

MySQL/InnoDB and PostgreSQL support the row locks used here. SQLite does not provide equivalent concurrency guarantees. The default tests use SQLite; CI also exercises functional behavior on MySQL and PostgreSQL. Functional tests are not a multi-process contention benchmark.

The migration and model use `simple-reward.table_name`. Publication is explicit; provider discovery never changes application schemas. Runtime distribution includes source, config, migration stub, consumer docs, and Boost guidance. Test fixtures, tools, lockfile, and local caches are excluded.
