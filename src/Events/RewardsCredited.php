<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class RewardsCredited implements ShouldDispatchAfterCommit
{
    /**
     * Create a RewardsCredited event payload.
     *
     * @param  Model  $rewardable  The model that received rewards.
     * @param  int  $transactionId  Identifier of the credit transaction.
     * @param  float  $amount  Amount of rewards added.
     * @param  float  $newBalance  Resulting balance after the addition.
     * @param  string|null  $description  Optional description of the transaction.
     * @param  array<string, mixed>  $metadata  Additional contextual data associated with the event.
     */
    public function __construct(
        public Model $rewardable,
        public int $transactionId,
        public float $amount,
        public float $newBalance,
        public ?string $description,
        public array $metadata
    ) {}
}
