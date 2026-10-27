# Contributing

Use PHP 8.5, Composer 2, and SQLite support. Coverage requires Xdebug or PCOV.

```sh
git clone https://github.com/wtroiano86/simple-reward.git
cd simple-reward
composer install
composer test:unit
```

The Composer hooks prepare the Orchestra Testbench application. No separate Laravel app or production credentials are required.

| Location | Responsibility |
| --- | --- |
| `src/Traits/HasRewards.php` | Public ledger API, validation, transactions and locking |
| `src/Models/Reward.php` | Persistence, decimal casts and metadata scopes |
| `src/RewardRules.php`, `src/Rules`, `src/Contracts` | Optional rule validation, evaluation and atomic duplicate prevention |
| `src/Events`, `src/Exceptions` | Public events and insufficient-balance exception |
| `src/Providers`, `config`, `database` | Discovery, configuration and publishable migration |
| `tests/Feature`, `tests/Support` | Behavior tests and isolated owner fixture |
| `resources/boost`, `docs` | Application guidance and package maintenance docs |

Keep tests focused on public behavior: persisted balances, rejected writes, metadata results, rollback, event timing, and publishable resources. Consider the owner connection and first ledger entry when changing transaction logic. Avoid direct ledger mutation in application examples.

Run `composer lint` to apply Rector and Pint, then the [quality checks](testing.md). Update consumer docs and Boost guidance with public API changes. Keep development files excluded in both `.gitattributes` and Composer's archive configuration; this package has no frontend build.
