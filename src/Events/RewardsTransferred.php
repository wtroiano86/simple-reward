<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

final class RewardsTransferred implements ShouldDispatchAfterCommit
{
    /**
     * Create a RewardsTransferred event containing the transfer payload.
     *
     * @param  int  $transactionId  Identifier for the transfer transaction.
     * @param  Model  $sender  The model representing the sender.
     * @param  Model  $recipient  The model representing the recipient.
     * @param  float  $amount  The amount transferred.
     * @param  float  $senderNewBalance  Sender's balance after the transfer.
     * @param  float  $recipientNewBalance  Recipient's balance after the transfer.
     * @param  string|null  $description  Optional human-readable description of the transfer.
     * @param  array<string, mixed>  $metadata  Additional arbitrary metadata associated with the transfer.
     */
    public function __construct(
        public int $transactionId,
        public Model $sender,
        public Model $recipient,
        public float $amount,
        public float $senderNewBalance,
        public float $recipientNewBalance,
        public ?string $description,
        public array $metadata
    ) {}
}
