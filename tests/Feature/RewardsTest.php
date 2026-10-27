<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Wtroiano\SimpleReward\Events\RewardsCredited;
use Wtroiano\SimpleReward\Events\RewardsDebited;
use Wtroiano\SimpleReward\Events\RewardsTransferred;
use Wtroiano\SimpleReward\Exceptions\InsufficientRewardException;
use Wtroiano\SimpleReward\Models\Reward;
use Wtroiano\SimpleReward\Tests\Support\Account;

it('records credits and debits with decimal balances, metadata and owner relations', function (): void {
    $account = Account::query()->create();
    expect($account->rewardBalance())->toBe(0.0)
        ->and($account->rewardHistory())->toBeEmpty()
        ->and($account->hasRewards(1))->toBeFalse();

    $credit = $account->rewardCredit(10.126, 'Welcome', ['campaign' => 'signup']);
    $debit = $account->rewardDebit(0.13, 'Redeem');

    expect($credit->fresh()->amount)->toBe('10.13')
        ->and($credit->metadata)->toBe(['campaign' => 'signup'])
        ->and($credit->description)->toBe('Welcome')
        ->and($credit->type)->toBe('credit')
        ->and($credit->rewardable->is($account))->toBeTrue()
        ->and($debit->fresh()->running_balance)->toBe('10.00')
        ->and($debit->type)->toBe('debit')
        ->and($account->rewardBalance())->toBe(10.0)
        ->and($account->hasRewards(10))->toBeTrue()
        ->and($account->hasRewards(10.01))->toBeFalse();
});

it('rejects invalid amounts without writing a ledger entry', function (float $amount): void {
    $account = Account::query()->create();
    foreach (['rewardCredit', 'rewardDebit', 'hasRewards'] as $method) {
        expect(fn () => $account->{$method}($amount))->toThrow(InvalidArgumentException::class);
    }

    expect($account->rewardHistory())->toBeEmpty();
})->with([0.0, -1.0, 0.001, INF, -INF, NAN, 100000000.0]);

it('rejects an unsaved owner or an owner deleted after loading', function (): void {
    expect(fn (): Reward => (new Account)->rewardCredit(1))->toThrow(LogicException::class);
    $account = Account::query()->create();
    Account::query()->whereKey($account->id)->delete();
    expect(fn () => $account->rewardCredit(1))->toThrow(ModelNotFoundException::class);
});

it('rejects insufficient balances and uses the new negative-balance configuration', function (): void {
    $account = Account::query()->create();
    $account->rewardCredit(10);

    expect(fn () => $account->rewardDebit(11))->toThrow(InsufficientRewardException::class, 'Insufficient rewards. Requested: 11, Available: 10');
    expect($account->rewardBalance())->toBe(10.0)->and($account->rewards()->count())->toBe(1);

    config(['simple-reward.allow_negative_balance' => true]);
    $account->rewardDebit(11);
    expect($account->rewardBalance())->toBe(-1.0);
});

it('rejects balances outside the database precision', function (): void {
    $account = Account::query()->create();
    $account->rewardCredit(99999999.99);

    expect(fn () => $account->rewardCredit(0.01))->toThrow(InvalidArgumentException::class);
    expect($account->rewardBalance())->toBe(99999999.99)->and($account->rewards()->count())->toBe(1);
});

it('transfers rewards and dispatches the complete event payloads after commit', function (): void {
    $sender = Account::query()->create();
    $recipient = Account::query()->create();
    $sender->rewardCredit(50);
    $events = [];
    Event::listen([RewardsCredited::class, RewardsDebited::class, RewardsTransferred::class], function (object $event) use (&$events): void {
        $events[] = $event;
    });

    DB::beginTransaction();
    $balances = $sender->rewardTransfer($recipient, 20, 'Gift', ['occasion' => 'birthday']);
    expect($events)->toBe([]);
    DB::commit();

    expect($balances)->toBe(['sender_balance' => 30.0, 'recipient_balance' => 20.0])
        ->and($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(RewardsDebited::class)
        ->and($events[0]->rewardable->is($sender))->toBeTrue()
        ->and($events[0]->transactionId)->toBe($sender->rewardHistory()->first()->id)
        ->and($events[0]->newBalance)->toBe(30.0)
        ->and($events[1])->toBeInstanceOf(RewardsCredited::class)
        ->and($events[1]->rewardable->is($recipient))->toBeTrue()
        ->and($events[1]->newBalance)->toBe(20.0)
        ->and($events[2])->toBeInstanceOf(RewardsTransferred::class)
        ->and($events[2]->sender)->toBe($sender)
        ->and($events[2]->recipient)->toBe($recipient)
        ->and($events[2]->amount)->toBe(20.0)
        ->and($events[2]->senderNewBalance)->toBe(30.0)
        ->and($events[2]->recipientNewBalance)->toBe(20.0)
        ->and($events[2]->description)->toBe('Gift')
        ->and($events[2]->metadata)->toBe(['occasion' => 'birthday'])
        ->and($events[2]->transactionId)->toBe($events[1]->transactionId);

    $recipient->rewardTransfer($sender, 5);
    expect($sender->rewardBalance())->toBe(35.0)->and($recipient->rewardBalance())->toBe(15.0);
});

it('rolls back both sides and suppresses events when a transfer fails', function (): void {
    $sender = Account::query()->create();
    $recipient = Account::query()->create();
    $sender->rewardCredit(50);
    $recipient->rewardCredit(99999999.99);
    $events = [];
    Event::listen([RewardsCredited::class, RewardsDebited::class, RewardsTransferred::class], function (object $event) use (&$events): void {
        $events[] = $event;
    });

    expect(fn () => $sender->rewardTransfer($recipient, 10))->toThrow(InvalidArgumentException::class);
    expect($sender->rewardBalance())->toBe(50.0)
        ->and($recipient->rewardBalance())->toBe(99999999.99)
        ->and($sender->rewards()->count())->toBe(1)
        ->and($events)->toBe([]);
});

it('suppresses all transfer events when the outer transaction rolls back', function (): void {
    $sender = Account::query()->create();
    $recipient = Account::query()->create();
    $sender->rewardCredit(50);
    $events = [];
    Event::listen([RewardsCredited::class, RewardsDebited::class, RewardsTransferred::class], function (object $event) use (&$events): void {
        $events[] = $event;
    });
    DB::beginTransaction();
    $sender->rewardTransfer($recipient, 10);
    DB::rollBack();
    expect($events)->toBe([])->and($sender->rewardBalance())->toBe(50.0)->and($recipient->rewardBalance())->toBe(0.0);
});

it('rejects self transfers, invalid amounts, insufficient funds and cross-connection transfers', function (): void {
    $sender = Account::query()->create();
    $recipient = Account::query()->create();
    expect(fn () => $sender->rewardTransfer($sender->fresh(), 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $sender->rewardTransfer($recipient, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $sender->rewardTransfer($recipient, 1))->toThrow(InsufficientRewardException::class);
    config(['database.connections.other' => config('database.connections.testing')]);
    $recipient->setConnection('other');
    expect(fn () => $sender->rewardTransfer($recipient, 1))->toThrow(InvalidArgumentException::class, 'same database connection');
});

it('uses the owner connection when it differs from the application default', function (): void {
    $account = Account::query()->create();
    config(['database.default' => 'unconfigured']);
    $account->rewardCredit(10);
    expect($account->rewardBalance())->toBe(10.0);
    config(['database.default' => 'testing']);
});

it('returns deterministic history with normalized direction and bounded limits', function (): void {
    $account = Account::query()->create();
    $this->freezeTime();
    $first = $account->rewardCredit(1);
    $second = $account->rewardCredit(2);
    $third = $account->rewardDebit(1);
    expect($account->rewardHistory()->modelKeys())->toBe([$third->id, $second->id, $first->id])
        ->and($account->rewardHistory(2, 'ASC')->modelKeys())->toBe([$first->id, $second->id])
        ->and($account->rewardHistory(0, 'invalid')->modelKeys())->toBe([$third->id])
        ->and($account->rewardHistory(2000))->toHaveCount(3);
});

it('looks up balances at dates and second or millisecond timestamps', function (): void {
    $account = Account::query()->create();
    $date = Date::parse('2026-01-01 12:00:00', 'UTC');
    expect($account->rewardBalanceAt($date))->toBe(0.0);
    $this->travelTo($date);
    $account->rewardCredit(10);
    $this->travelTo($date->copy()->addDay());
    $account->rewardCredit(5);
    expect($account->rewardBalanceAt($date))->toBe(10.0)
        ->and($account->rewardBalanceAt($date->toDateTimeImmutable()))->toBe(10.0)
        ->and($account->rewardBalanceAt($date->timestamp))->toBe(10.0)
        ->and($account->rewardBalanceAt($date->timestamp * 1000))->toBe(10.0)
        ->and($account->rewardBalance())->toBe(15.0);
});

it('honors application morph maps', function (): void {
    Relation::enforceMorphMap(['account' => Account::class]);
    try {
        $account = Account::query()->create();
        $reward = $account->rewardCredit(1);
        expect($reward->rewardable_type)->toBe('account')->and($reward->rewardable->is($account))->toBeTrue();
    } finally {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }
});
