<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Tests\Support;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Wtroiano\SimpleReward\Contracts\Rewardable;
use Wtroiano\SimpleReward\Traits\HasRewards;

#[Unguarded]
final class Account extends Model implements Rewardable
{
    use HasRewards;
}
