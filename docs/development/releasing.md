# Releasing 0.1.0

The release version is `0.1.0`, with Git tag `v0.1.0`. The repository is `https://github.com/wtroiano86/simple-reward.git`; Composer consumers use `wtroiano/simple-reward:^0.1`. Keep the version out of `composer.json`: Composer derives it from the [repository tag](https://getcomposer.org/doc/04-schema.md#version).

## Local release checks

Review the [0.1.0 changelog](../../CHANGELOG.md#010), public API, dependency constraints, support links, consumer examples and Boost resources. Run:

```sh
composer install
XDEBUG_MODE=coverage composer test:release
composer archive --format=zip --dir=tmp/release --file=simple-reward-0.1.0
```

The release gate validates the Composer manifest, audits runtime dependencies, runs all PHP quality and coverage checks, validates documentation, and checks archive contents. Xdebug must have coverage enabled; PCOV is also supported. If available, run `sloppy` and review its findings.

CI must pass for both highest and lowest resolvable dependencies, plus MySQL 8.4 and PostgreSQL 17. See [testing](testing.md) for disposable database configuration. The local Composer lockfile is excluded from source control and distribution; it does not establish compatibility with every allowed dependency version.

## Installation smoke test

Install the built archive into a disposable Laravel 13 application with PHP 8.5 and a disposable database. Before the tag exists, use a local Composer package repository identifying the archive as version `0.1.0`; after publication, verify the normal `composer require wtroiano/simple-reward:^0.1` command in another clean application.

Verify these behaviors:

1. Composer discovers the provider without manual registration, and the default configuration is available.
2. Publish configuration and the migration; confirm its filename uses the current timestamp. Repeat publication and confirm there is still one ledger migration and local edits survive without `--force`.
3. Run migrations, then credit, debit and transfer between saved models using `HasRewards`. Confirm persisted balances and metadata queries.
4. Add `Contracts\Rewardable` to the owner, register a `RewardRule` class, and apply a stable source twice. Only the first call creates a credit, with a non-null `rule_application_key`.
5. Cache configuration, restart the application process and apply another source. Confirm the registered rule still resolves.
6. Confirm the installed archive includes consumer docs, the Boost guideline and the development skill. Do not install agent configuration in this package repository during validation.

## Publication

Commit the reviewed source to the repository, push it and confirm the required CI jobs pass for that commit. Tag that commit `v0.1.0`, push the tag, and publish the release using the changelog entry. For a public Composer release, make the repository publicly accessible and register it on Packagist. Confirm Packagist exposes `0.1.0` and repeat the clean installation check against it.

Tagging, pushing, changing repository visibility, registering Packagist and publishing releases are separate maintainer actions. Local validation and archive creation do not perform them.
