# Reward operations

## Credits, debits, and balances

```php
use Wtroiano\SimpleReward\Exceptions\InsufficientRewardException;

$entry = $user->rewardCredit(100, 'Signup', ['campaign' => 'welcome']);

try {
    $user->rewardDebit(25, 'Redeemed');
} catch (InsufficientRewardException $exception) {
    // Show the application's insufficient-balance response.
}

$balance = $user->rewardBalance();
$eligible = $user->hasRewards(50);
```

Credit and debit return `Wtroiano\SimpleReward\Models\Reward`. Amounts are positive for both; `type` distinguishes `credit` and `debit`. Model `amount` and `running_balance` attributes are decimal strings. Balance helpers and event amounts are floats.

Amounts round to two decimals using PHP's default half-away-from-zero rounding. After rounding, they must be finite and between `0.01` and `99999999.99`. The absolute running balance cannot exceed `99999999.99`, matching the `decimal(10, 2)` schema. Invalid amounts and overflow throw `InvalidArgumentException`. Unsaved owners throw `LogicException`.

`hasRewards()` accepts the same positive amount range. It is a snapshot, so a later debit can still fail. By default, insufficient debits throw `InsufficientRewardException` without recording a transaction. Set `simple-reward.allow_negative_balance` to `true` to allow negative balances within the same precision limit.

## Transfers

```php
$balances = $sender->rewardTransfer($recipient, 10, 'Gift', ['campaign' => 'referral']);
// ['sender_balance' => 90.0, 'recipient_balance' => 10.0]
```

The recipient must use the same model class as the sender, and both must be saved on the same database connection. Self transfers and transfers between connections throw `InvalidArgumentException`. A transfer creates one debit and one credit atomically. If either write fails, neither ledger entry remains.

## History

```php
$entries = $user->rewardHistory();            // 10 most recent entries
$oldest = $user->rewardHistory(20, 'asc');
$asOf = $user->rewardBalanceAt(now()->subDay());
$atTimestamp = $user->rewardBalanceAt(1767268800);
$atMilliseconds = $user->rewardBalanceAt(1767268800000);
```

History returns an Eloquent collection, ordered by `created_at` then `id`. Limits clamp to 1–1000; direction is case-insensitive and defaults to descending for invalid values. Use `$user->rewards()` for pagination or other Eloquent queries.

Historical lookup accepts `DateTimeInterface` (including Carbon) or an integer Unix timestamp. Integers greater than `9999999999` are treated as milliseconds. It returns the latest inserted balance whose creation time is at or before the cutoff, or `0.0`. Do not backdate or edit ledger entries: running balances describe insertion order.

## Events

All events live under `Wtroiano\SimpleReward\Events` and implement Laravel's `ShouldDispatchAfterCommit`. They dispatch after the outer transaction commits and are discarded on rollback.

| Event | Payload |
| --- | --- |
| `RewardsCredited` | `rewardable`, `transactionId`, `amount`, `newBalance`, `description`, `metadata` |
| `RewardsDebited` | `rewardable`, `transactionId`, `amount`, `newBalance`, `description`, `metadata` |
| `RewardsTransferred` | `sender`, `recipient`, `transactionId`, `amount`, `senderNewBalance`, `recipientNewBalance`, `description`, `metadata` |

A transfer emits debit, credit, then transfer events. The transfer's `transactionId` refers to the recipient's credit entry.

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Wtroiano\SimpleReward\Events\RewardsCredited;

Event::listen(RewardsCredited::class, function (RewardsCredited $event): void {
    Log::info('Rewards credited', [
        'transaction_id' => $event->transactionId,
        'balance' => $event->newBalance,
    ]);
});
```

Listeners run after the ledger has committed. A listener failure cannot undo that commit; design retry and duplicate-request handling in the application.
