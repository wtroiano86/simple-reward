<?php

declare(strict_types=1);

use Wtroiano\SimpleReward\Tests\Support\Account;

beforeEach(function (): void {
    $this->account = Account::query()->create();
    $this->first = $this->account->rewardCredit(10, metadata: ['source' => 'purchase', 'order' => ['id' => 10], 'tags' => ['gold', 'bonus'], 'refund' => null]);
    $this->second = $this->account->rewardCredit(20, metadata: ['source' => 'gift', 'order' => ['id' => 20], 'tags' => ['silver'], 'refund' => 'yes']);
    $this->third = $this->account->rewardCredit(30, metadata: []);
    Account::query()->create()->rewardCredit(99, metadata: ['source' => 'gift']);
});

it('queries values, comparisons, nested paths and explicit nulls', function (): void {
    expect($this->account->rewards()->whereMeta('source', 'purchase')->get()->modelKeys())->toBe([$this->first->id])
        ->and($this->account->rewards()->whereMeta(' order.id ', '>', 10)->get()->modelKeys())->toBe([$this->second->id])
        ->and($this->account->rewards()->whereMeta('refund', '=', null)->count())->toBe(2)
        ->and($this->account->rewards()->whereMeta('refund', '!=', null)->get()->modelKeys())->toBe([$this->second->id]);
});

it('queries contains, non-null keys, missing keys and array lengths', function (): void {
    expect($this->account->rewards()->whereMetaContains('tags', 'gold')->get()->modelKeys())->toBe([$this->first->id])
        ->and($this->account->rewards()->whereMetaHas('refund')->get()->modelKeys())->toBe([$this->second->id])
        ->and($this->account->rewards()->whereMetaNull('refund')->count())->toBe(2)
        ->and($this->account->rewards()->whereMetaLength('tags', 2)->get()->modelKeys())->toBe([$this->first->id])
        ->and($this->account->rewards()->whereMetaLength('tags', '<', 2)->get()->modelKeys())->toBe([$this->second->id]);
});

it('supports grouped or scopes without including another owner', function (string $scope, array $arguments, int $count): void {
    $results = $this->account->rewards()->where(function ($query) use ($scope, $arguments): void {
        $query->whereMeta('source', 'purchase')->{$scope}(...$arguments);
    })->get();
    expect($results)->toHaveCount($count)
        ->and($results->pluck('rewardable_id')->unique()->all())->toBe([$this->account->id]);
})->with([
    ['orWhereMeta', ['source', 'gift'], 2],
    ['orWhereMeta', ['refund', '=', null], 2],
    ['orWhereMetaContains', ['tags', 'silver'], 2],
    ['orWhereMetaHas', ['refund'], 2],
    ['orWhereMetaNull', ['source'], 2],
    ['orWhereMetaLength', ['tags', 1], 2],
    ['orWhereMetaLength', ['tags', '=', 1], 2],
]);

it('rejects invalid metadata paths', function (string $key): void {
    expect(fn () => $this->account->rewards()->whereMeta($key, 'value')->get())->toThrow(InvalidArgumentException::class);
})->with(['', ' ', 'data->key', 'data"key', "data'key", 'data..key', '.data', 'data.']);

it('filters and bounds history through convenience methods', function (): void {
    expect($this->account->rewardsByMeta('source', 'purchase', limit: 1)->modelKeys())->toBe([$this->first->id])
        ->and($this->account->rewardsByMeta('order.id', '>', 10)->modelKeys())->toBe([$this->second->id])
        ->and($this->account->rewardsByMeta('refund', '=', null, order: 'asc')->modelKeys())->toBe([$this->first->id, $this->third->id])
        ->and($this->account->rewardsByMeta('refund', '!=', null)->modelKeys())->toBe([$this->second->id])
        ->and($this->account->rewardsByMeta('refund', null, limit: 0)->modelKeys())->toBe([$this->third->id]);
});

it('combines every supported metadata filter', function (): void {
    $results = $this->account->rewardsHistoryWithMeta([
        ['key' => 'source', 'value' => 'purchase'],
        ['key' => 'order.id', 'method' => 'has'],
        ['key' => 'refund', 'method' => 'null'],
        ['key' => 'tags', 'method' => 'contains', 'value' => 'gold'],
        ['key' => 'tags', 'method' => 'length', 'operator' => '>', 'value' => 1],
        ['key' => 'refund', 'operator' => '=', 'value' => null],
    ]);
    expect($results->modelKeys())->toBe([$this->first->id])
        ->and($this->account->rewardsHistoryWithMeta([], limit: 1)->modelKeys())->toBe([$this->third->id]);
});
