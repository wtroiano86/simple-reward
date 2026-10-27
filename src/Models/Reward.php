<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $amount
 * @property string $running_balance
 * @property string|null $description
 * @property 'credit'|'debit' $type
 * @property array<string, mixed>|null $metadata
 * @property string|null $rule_application_key
 * @property string $rewardable_type
 * @property int|string $rewardable_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $rewardable
 */
#[Fillable([
    'amount', 'description', 'type', 'metadata', 'running_balance',
    'rewardable_type', 'rewardable_id', 'rule_application_key',
])]
final class Reward extends Model
{
    public function getTable(): string
    {
        return Config::string('simple-reward.table_name', 'rewards');
    }

    /** @return MorphTo<Model, $this> */
    public function rewardable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function whereMeta(Builder $query, string $key, mixed $operator = null, mixed $value = null): Builder
    {
        if (func_num_args() < 4) {
            $value = $operator;
            $operator = '=';
        }

        return $query->where($this->metadataPath($key), $operator, $value);
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function whereMetaContains(Builder $query, string $key, mixed $value): Builder
    {
        return $query->whereJsonContains($this->metadataPath($key), $value);
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function whereMetaHas(Builder $query, string $key): Builder
    {
        return $query->whereNotNull($this->metadataPath($key));
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function whereMetaNull(Builder $query, string $key): Builder
    {
        return $query->whereNull($this->metadataPath($key));
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function whereMetaLength(Builder $query, string $key, mixed $operator, mixed $value = null): Builder
    {
        if (func_num_args() < 4) {
            $value = $operator;
            $operator = '=';
        }

        return $query->whereJsonLength($this->metadataPath($key), $operator, $value);
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function orWhereMeta(Builder $query, string $key, mixed $operator = null, mixed $value = null): Builder
    {
        if (func_num_args() < 4) {
            $value = $operator;
            $operator = '=';
        }

        return $query->orWhere($this->metadataPath($key), $operator, $value);
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function orWhereMetaContains(Builder $query, string $key, mixed $value): Builder
    {
        return $query->orWhereJsonContains($this->metadataPath($key), $value);
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function orWhereMetaHas(Builder $query, string $key): Builder
    {
        return $query->orWhereNotNull($this->metadataPath($key));
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function orWhereMetaNull(Builder $query, string $key): Builder
    {
        return $query->orWhereNull($this->metadataPath($key));
    }

    /**
     * @param  Builder<Reward>  $query
     * @return Builder<Reward>
     */
    #[Scope]
    protected function orWhereMetaLength(Builder $query, string $key, mixed $operator, mixed $value = null): Builder
    {
        if (func_num_args() < 4) {
            $value = $operator;
            $operator = '=';
        }

        return $query->orWhereJsonLength($this->metadataPath($key), $operator, $value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    private function metadataPath(string $key): string
    {
        $key = mb_trim($key);

        throw_if($key === '' || str_contains($key, '->') || str_contains($key, '"') || str_contains($key, "'") || in_array('', explode('.', $key), true), InvalidArgumentException::class, 'Metadata keys must be nonempty dot-separated paths without quotes or arrows.');

        return 'metadata->'.str_replace('.', '->', $key);
    }
}
