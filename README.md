# Simple Reward

A simple reward ledger for Laravel with credits, debits, transfers, running balances, transaction metadata, and events after commit. Optional typed rule classes award fixed credits with duplicate prevention.

Requires **PHP 8.5+** and **Laravel 13**. Composer package: `wtroiano/simple-reward`; PHP namespace: `Wtroiano\SimpleReward`.

## Installation

```sh
composer require wtroiano/simple-reward:^0.1
php artisan vendor:publish --tag=simple-reward-config
php artisan vendor:publish --tag=simple-reward-migrations
php artisan migrate
```

Laravel discovers the service provider automatically. Configuration publishing is optional. Set `table_name` in `config/simple-reward.php` before running `migrate`; UUID/ULID owners also require adjusting the published migration first. See [getting started](docs/consumers/getting-started.md).

## Usage

Add `HasRewards` to your existing model, preserving its base class and other traits. A minimal example:

```php
declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Wtroiano\SimpleReward\Traits\HasRewards;

class User extends Model
{
    use HasRewards;
}
```

Use a saved model to record rewards:

```php
$transaction = $user->rewardCredit(100, 'Welcome bonus', ['campaign' => 'signup']);
$user->rewardDebit(25, 'Redeemed points');

$user->rewardBalance();             // 75.0
$user->hasRewards(50);              // true
$user->rewardHistory(20);           // Latest entries first
$user->rewardBalanceAt(now());      // Balance at a date or timestamp

$result = $user->rewardTransfer($recipient, 10, 'Gift');
// ['sender_balance' => 65.0, 'recipient_balance' => 10.0]

$rewards = $user->rewards()->whereMeta('campaign', 'signup')->get();
```

Amounts round to two decimal places. Debits and transfers throw `InsufficientRewardException` when funds are unavailable unless negative balances are enabled. Transfers require the same model class and database connection. See [ledger operations](docs/consumers/rewards.md) for limits and event payloads.

For business-event awards, implement `RewardRule`, register its class in `simple-reward.rules`, and call `RewardRules::apply()` with a stable source ID and trusted context. The owner also implements `Contracts\Rewardable`. See the complete [rule class example](docs/consumers/rules.md).

## Documentation

- [Getting started and configuration](docs/consumers/getting-started.md)
- [Balances, transfers, history, and events](docs/consumers/rewards.md)
- [Reward rule classes and duplicate prevention](docs/consumers/rules.md)
- [Metadata queries](docs/consumers/metadata.md)
- [Architecture and database behavior](docs/architecture.md)
- [Laravel Boost guidelines and development skill](docs/boost.md)
- [Contributing](CONTRIBUTING.md), [testing](docs/development/testing.md), and [release checks](docs/development/releasing.md)
- [Changelog](CHANGELOG.md)

## Development

```sh
composer install
composer test:unit
XDEBUG_MODE=coverage composer test:release
```

The release checks include Pint, Rector, PHPStan at maximum level, 100% type and line coverage, documentation, runtime dependency audit, and distribution validation. Coverage requires Xdebug or PCOV.

Licensed under the [MIT license](LICENSE).
