# Getting started

Use PHP 8.5+ and Laravel 13. Install `wtroiano/simple-reward` through Composer:

```sh
composer require wtroiano/simple-reward:^0.1
php artisan vendor:publish --tag=simple-reward-config
php artisan vendor:publish --tag=simple-reward-migrations
php artisan migrate
```

Configuration publishing is optional. Laravel automatically registers `Wtroiano\SimpleReward\Providers\RewardServiceProvider` through Composer discovery. First publication names the migration with the current timestamp, such as `2030_02_03_040506_create_rewards_table.php`. Repeated publication reuses the existing filename and preserves application changes unless `--force` is passed. This behavior does not depend on `database.migrations.update_date_on_publish`. The package does not run migrations automatically.

The configuration file is `config/simple-reward.php`:

```php
return [
    'allow_negative_balance' => false,
    'table_name' => 'rewards',
    'rules' => [],
];
```

Set the table name before running migrations. Both the migration and `Reward` model use this setting; changing it later does not move data. Keep it stable for migration rollback. The default schema uses numeric polymorphic owner IDs. For UUID/ULID owners, adjust the published migration to use `uuidMorphs`/`ulidMorphs` before running it.

The migration gives secondary indexes explicit names of at most 37 ASCII characters, using a hash of the connection prefix and table name. Long custom table names therefore do not lengthen the indexes, and distinct tables use distinct names. Preserve the explicit index-name argument when changing `morphs` to `uuidMorphs`/`ulidMorphs`. For later index changes, inspect `Schema::getIndexes()` and use the actual index name rather than Laravel's default generated name.

`rules` is empty by default. Class-defined awards use the same rewards table and the `RewardRules` service; see [reward rule classes](rules.md) for setup, conditions and duplicate prevention.

Add the trait to your existing model, preserving its base class and other traits. A minimal example:

```php
declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Wtroiano\SimpleReward\Traits\HasRewards;

class User extends Model
{
    use HasRewards;
}
```

Save the model before writing rewards. The ledger uses the model's database connection. If owners use a separate connection, migrate the rewards table on that connection too. Application morph maps are supported.

MySQL with InnoDB and PostgreSQL provide the row locks used by ledger writes. SQLite is useful for local development and functional tests but does not provide equivalent row locking. See [architecture](../architecture.md).

The package creates a new ledger; it does not import data or rename tables from other packages.
