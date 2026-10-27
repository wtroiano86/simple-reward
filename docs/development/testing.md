# Testing and quality checks

```sh
composer install
composer lint                 # Apply Rector and Pint
composer test:unit            # Behavior and package integration tests
composer test:lint            # Pint and Rector without writes
composer test:types           # PHPStan at maximum level
composer test:type-coverage   # 100% type coverage
composer test:coverage        # 100% source line coverage
composer test                 # All PHP quality gates
composer test:docs            # Local links and PHP/JSON snippet syntax
composer test:distribution    # Archive contents
composer test:release         # Manifest, runtime audit, PHP, docs, distribution
```

`composer test:coverage` needs Xdebug with coverage enabled or PCOV. With Xdebug, use `XDEBUG_MODE=coverage composer test` or `XDEBUG_MODE=coverage composer test:release`. SQLite runs in memory by default. The suite also renders the shipped Boost guideline and executes the shipped migration stub.

To test against a dedicated disposable MySQL or PostgreSQL database, set `REWARD_TEST_DB_DRIVER` (`mysql` or `pgsql`), `REWARD_TEST_DB_HOST`, `REWARD_TEST_DB_PORT`, `REWARD_TEST_DB_DATABASE`, `REWARD_TEST_DB_USERNAME`, and `REWARD_TEST_DB_PASSWORD`, then run `composer test:unit`. Tests create and drop `accounts`, `rewards`, `custom_rewards`, `migrations`, and temporary tables beginning with `simple_reward_index_test_` (also with a `tenant_` prefix); never point them at an application database.

CI resolves both highest and lowest dependencies on PHP 8.5 and runs functional database suites on MySQL 8.4 and PostgreSQL 17. SQLite functional checks do not establish concurrent-write behavior on production engines. No multi-process concurrency stress suite is included.

Documentation checks cover root Markdown files, `llms.txt`, `docs` and Boost resources. They validate local links, heading anchors, Boost skill frontmatter and snippet syntax; they do not execute application examples or check external URLs. Distribution checks inspect a Composer archive and verify required runtime/documentation files, both Boost guidelines and skills, and development exclusions. Follow the [installation smoke test](releasing.md#installation-smoke-test) before a release. Do not run Boost installation in this package repository during validation.
