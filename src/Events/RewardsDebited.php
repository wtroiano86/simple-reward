<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class RewardsDebited implements ShouldDispatchAfterCommit
{
    /**
     * Create a new event representing rewards deducted from a rewardable model.
     *
     * @param  Model  $rewardable  The model from which rewards were deducted.
     * @param  int  $transactionId  Identifier for the related transaction.
     * @param  float  $amount  The amount deducted.
     * @param  float  $newBalance  The resulting balance after the deduction.
     * @param  string|null  $description  Optional description of the deduction.
     * @param  array<string, mixed>  $metadata  Additional metadata related to the deduction.
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
