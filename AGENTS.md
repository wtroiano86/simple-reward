# Package contributor guidance

This is `wtroiano/simple-reward`, a PHP 8.5 / Laravel 13 package under `Wtroiano\SimpleReward`.
Read [contributing](docs/development/contributing.md), [testing](docs/development/testing.md), and [architecture](docs/architecture.md) before changes.

Keep names, Composer discovery, config, migration publication, docs, and Boost guidance consistent. Use strict types, explicit types and generics, Pint, Rector, and maximum-level PHPStan. Add behavior tests for ledger changes, including atomic rollback and event timing when relevant. Preserve the 100% line and type coverage gates without ignoring source lines or analysis errors.

`resources/boost` guides consuming applications. Do not install agent configuration into this repository as part of validation. Publishing a release is separate from local package work.
