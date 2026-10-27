# Metadata queries

Attach JSON-serializable metadata to each transaction:

```php
$user->rewardCredit(10, 'Order reward', [
    'source' => 'purchase',
    'order' => ['id' => 123],
    'tags' => ['gold', 'bonus'],
    'refund_id' => null,
]);
```

Metadata paths use dot notation, such as `order.id`. Leading and trailing whitespace is trimmed. Empty paths, empty segments, quotes, and `->` are rejected with `InvalidArgumentException`.

```php
$user->rewards()->whereMeta('source', 'purchase')->get();
$user->rewards()->whereMeta('order.id', '>=', 100)->get();
$user->rewards()->whereMeta('refund_id', '=', null)->get();
$user->rewards()->whereMetaContains('tags', 'gold')->get();
$user->rewards()->whereMetaHas('order.id')->get();
$user->rewards()->whereMetaNull('refund_id')->get();
$user->rewards()->whereMetaLength('tags', '>', 1)->get();
$user->rewards()->whereMetaLength('tags', 2)->get();
```

`whereMetaHas` means a non-null value. `whereMetaNull` includes explicit JSON null and absent keys. JSON comparisons use the database's native semantics; array indexing and specialized JSON operators can vary across drivers.

Every scope has an OR counterpart: `orWhereMeta`, `orWhereMetaContains`, `orWhereMetaHas`, `orWhereMetaNull`, and `orWhereMetaLength`. Group OR conditions so they cannot bypass the owner constraint:

```php
$user->rewards()->where(function ($query): void {
    $query->whereMeta('source', 'purchase')->orWhereMeta('source', 'gift');
})->get();
```

## Filtered history

```php
$entries = $user->rewardsByMeta('source', 'purchase', limit: 20);
$largeOrders = $user->rewardsByMeta('order.id', '>', 100);
$unrefunded = $user->rewardsByMeta('refund_id', '=', null);

$filtered = $user->rewardsHistoryWithMeta([
    ['key' => 'source', 'value' => 'purchase'],
    ['key' => 'order.id', 'method' => 'has'],
    ['key' => 'tags', 'method' => 'contains', 'value' => 'gold'],
    ['key' => 'tags', 'method' => 'length', 'operator' => '>', 'value' => 1],
    ['key' => 'refund_id', 'method' => 'null'],
], limit: 25, order: 'desc');
```

Both helpers use the same history ordering and 1–1000 limit. Filters combine with AND. Each filter requires `key`; `method` defaults to `where`, `operator` to `=`, and an omitted `value` to null. Supported methods are `where`, `contains`, `has`, `null`, and `length`.

For `rewardsByMeta`, use an explicit `=` when matching a literal `=`, `!=`, or `<>` string; those operators with a null third argument express null comparisons. For other comparisons, pass all three arguments. Use the relationship scopes for more complex queries.

The package does not create metadata-specific indexes. Add application-owned indexes after measuring real queries with your database's query planner.
