# Laravel Boost

The package ships two resources for consuming applications:

- [Core guidelines](../resources/boost/guidelines/core.blade.php): essential integration constraints, typed rule APIs, duplicate identity, events and database boundaries.
- [simple-reward-development skill](../resources/boost/skills/simple-reward-development/SKILL.md): choosing ledger operations or rule classes, implementing a typed rule, registration, trusted context, retries, metadata queries and application behavior tests.

The skill follows [Laravel Boost's third-party package skill convention](https://laravel.com/framework/docs/boost#third-party-package-skills): `resources/boost/skills/{skill-name}/SKILL.md`, with `name` and `description` frontmatter. The guideline routes relevant work to the skill, keeping the detailed workflow available on demand.

Use the consuming application's Boost setup after installing or updating this package. Run `php artisan boost:install` and select the relevant guidelines/skills features, or `php artisan boost:update` to refresh an existing setup. Those commands belong in the consuming application; they generate files for its configured agents.

This package has no installer that changes application or global agent configuration. Package validation does not run Boost installation here. The test suite renders the Blade guideline, documentation checks validate skill frontmatter and PHP examples, and distribution checks require both resources in the Composer archive. Discovery through every Boost version or agent client is not established by those checks.
