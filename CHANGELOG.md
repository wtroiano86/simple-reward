# Changelog

All notable changes to `simple-reward` will be documented in this file.

## 0.1.0

Initial release of `wtroiano/simple-reward` for PHP 8.5 and Laravel 13.

- Credits, debits, atomic transfers, running balances, historical balances and bounded transaction history through `HasRewards`.
- Positive amounts rounded to two decimals, configurable negative balances and explicit insufficient-balance errors.
- Owner-row locking on the owner's connection, transactional rollback and credit, debit and transfer events after commit.
- JSON metadata scopes and filtered history, including nested paths and explicit-null comparisons.
- Optional rule classes with typed definitions, fixed awards, AND conditions and comparison operators.
- Atomic rule awards with a unique key on each credit and duplicate prevention per owner, rule and source. Existing keys are checked in one locking query.
- Automatic Laravel provider discovery, publishable configuration and one ledger migration. Migration filenames use the publication timestamp and remain stable on repeat publication; index names remain bounded for custom table names and prefixes.
- Consumer documentation, Laravel Boost guidelines and a `simple-reward-development` skill.
- Pest/Testbench tests, 100% line and type coverage gates, maximum-level PHPStan, Pint, Rector, documentation and distribution validation, and CI for SQLite, MySQL and PostgreSQL.
