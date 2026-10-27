<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Traits;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use LogicException;
use Wtroiano\SimpleReward\Events\RewardsCredited;
use Wtroiano\SimpleReward\Events\RewardsDebited;
use Wtroiano\SimpleReward\Events\RewardsTransferred;
use Wtroiano\SimpleReward\Exceptions\InsufficientRewardException;
use Wtroiano\SimpleReward\Models\Reward;

/** @mixin Model */
trait HasRewards
{
    /** @return MorphMany<Reward, $this> */
    public function rewards(): MorphMany
    {
        return $this->morphMany(Reward::class, 'rewardable');
    }

    /** @param array<string, mixed> $metadata */
    public function rewardCredit(float $amount, ?string $description = null, array $metadata = [], ?string $ruleApplicationKey = null): Reward
    {
        throw_if($ruleApplicationKey !== null && preg_match('/\A[a-f0-9]{64}\z/', $ruleApplicationKey) !== 1, InvalidArgumentException::class, 'Rule application keys must be lowercase SHA-256 hashes.');

        return $this->recordReward($amount, 'credit', $description, $metadata, $ruleApplicationKey);
    }

    /** @param array<string, mixed> $metadata */
    public function rewardDebit(float $amount, ?string $description = null, array $metadata = []): Reward
    {
        return $this->recordReward($amount, 'debit', $description, $metadata);
    }

    public function rewardBalance(): float
    {
        return (float) ($this->rewards()->latest('id')->first(['running_balance'])->running_balance ?? 0);
    }

    public function hasRewards(float $amount): bool
    {
        return $this->rewardBalance() >= $this->normalizeRewardAmount($amount);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{sender_balance: float, recipient_balance: float}
     */
    public function rewardTransfer(self $recipient, float $amount, ?string $description = null, array $metadata = []): array
    {
        $amount = $this->normalizeRewardAmount($amount);

        throw_if($this->getConnection()->getName() !== $recipient->getConnection()->getName(), InvalidArgumentException::class, 'Rewards can only be transferred on the same database connection.');

        throw_if($this->is($recipient), InvalidArgumentException::class, 'Rewards cannot be transferred to the same model.');

        return $this->getConnection()->transaction(function () use ($recipient, $amount, $description, $metadata): array {
            $owners = [$this, $recipient];
            usort($owners, fn (self $left, self $right): int => [$left->getTable(), $left->getKey()] <=> [$right->getTable(), $right->getKey()]);

            foreach ($owners as $owner) {
                $owner->lockRewardOwner();
            }

            $debit = $this->rewardDebit($amount, $description, $metadata);
            $credit = $recipient->rewardCredit($amount, $description, $metadata);

            $result = [
                'sender_balance' => (float) $debit->running_balance,
                'recipient_balance' => (float) $credit->running_balance,
            ];

            event(new RewardsTransferred(
                transactionId: $credit->id,
                sender: $this,
                recipient: $recipient,
                amount: $amount,
                senderNewBalance: $result['sender_balance'],
                recipientNewBalance: $result['recipient_balance'],
                description: $description,
                metadata: $metadata,
            ));

            return $result;
        }, 5);
    }

    /** @return Collection<int, Reward> */
    public function rewardHistory(int $limit = 10, string $order = 'desc'): Collection
    {
        return $this->rewardHistoryQuery($limit, $order)->get();
    }

    public function rewardBalanceAt(DateTimeInterface|int $dateTime): float
    {
        if (is_int($dateTime)) {
            $dateTime = Date::createFromTimestamp($dateTime > 9999999999 ? intdiv($dateTime, 1000) : $dateTime);
        }

        return (float) ($this->rewards()->where('created_at', '<=', $dateTime)->latest('id')->first(['running_balance'])->running_balance ?? 0);
    }

    /** @return Collection<int, Reward> */
    public function rewardsByMeta(string $key, mixed $operator = null, mixed $value = null, int $limit = 10, string $order = 'desc'): Collection
    {
        $query = $this->rewardHistoryQuery($limit, $order);

        if ($value === null && ! in_array($operator, ['=', '!=', '<>'], true)) {
            return $query->whereMeta($key, $operator)->get();
        }

        return $query->whereMeta($key, $operator, $value)->get();
    }

    /**
     * @param  list<array{key: string, method?: 'where'|'contains'|'has'|'null'|'length', operator?: mixed, value?: mixed}>  $filters
     * @return Collection<int, Reward>
     */
    public function rewardsHistoryWithMeta(array $filters, int $limit = 10, string $order = 'desc'): Collection
    {
        $query = $this->rewardHistoryQuery($limit, $order);

        foreach ($filters as $filter) {
            $key = $filter['key'];
            $value = $filter['value'] ?? null;
            $operator = $filter['operator'] ?? '=';

            match ($filter['method'] ?? 'where') {
                'contains' => $query->whereMetaContains($key, $value),
                'has' => $query->whereMetaHas($key),
                'null' => $query->whereMetaNull($key),
                'length' => $query->whereMetaLength($key, $operator, $value),
                default => $query->whereMeta($key, $operator, $value),
            };
        }

        return $query->get();
    }

    /** @return MorphMany<Reward, $this> */
    private function rewardHistoryQuery(int $limit, string $order): MorphMany
    {
        $order = mb_strtolower($order) === 'asc' ? 'asc' : 'desc';

        return $this->rewards()->orderBy('created_at', $order)->orderBy('id', $order)->limit(min(max($limit, 1), 1000));
    }

    private function normalizeRewardAmount(float $amount): float
    {
        $amount = round($amount, 2);

        throw_if(! is_finite($amount) || $amount <= 0 || $amount > 99999999.99, InvalidArgumentException::class, 'Amount must round to between 0.01 and 99999999.99 rewards.');

        return $amount;
    }

    private function lockRewardOwner(): void
    {
        throw_unless($this->exists, LogicException::class, 'Save the model before recording rewards.');

        // Lock a stable row even when this owner has no ledger entries yet.
        $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  'credit'|'debit'  $type
     * @param  array<string, mixed>  $metadata
     */
    private function recordReward(float $amount, string $type, ?string $description, array $metadata, ?string $ruleApplicationKey = null): Reward
    {
        $amount = $this->normalizeRewardAmount($amount);

        return $this->getConnection()->transaction(function () use ($amount, $type, $description, $metadata, $ruleApplicationKey): Reward {
            $this->lockRewardOwner();
            $balance = (float) ($this->rewards()->latest('id')->lockForUpdate()->first(['running_balance'])->running_balance ?? 0);
            $newBalance = round($balance + ($type === 'credit' ? $amount : -$amount), 2);

            throw_if(! Config::boolean('simple-reward.allow_negative_balance', false) && $newBalance < 0, InsufficientRewardException::class, $amount, $balance);

            throw_if(abs($newBalance) > 99999999.99, InvalidArgumentException::class, 'The resulting reward balance exceeds the ledger limit.');

            $reward = $this->rewards()->create([
                'amount' => $amount,
                'description' => $description,
                'type' => $type,
                'metadata' => $metadata,
                'rule_application_key' => $ruleApplicationKey,
                'running_balance' => $newBalance,
            ]);

            $event = $type === 'credit' ? RewardsCredited::class : RewardsDebited::class;
            event(new $event($this, $reward->id, $amount, $newBalance, $description, $metadata));

            return $reward;
        }, 5);
    }
}
