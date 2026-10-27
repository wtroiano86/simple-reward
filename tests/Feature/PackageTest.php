<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Wtroiano\SimpleReward\Models\Reward;
use Wtroiano\SimpleReward\Providers\RewardServiceProvider;
use Wtroiano\SimpleReward\RewardRules;
use Wtroiano\SimpleReward\Tests\Support\Account;
use Wtroiano\SimpleReward\Tests\Support\AdditionalRule;
use Wtroiano\SimpleReward\Traits\HasRewards;

it('registers configuration and publishable resources using the package identity', function (): void {
    expect(config('simple-reward.table_name'))->toBe('rewards')
        ->and(config('simple-reward.allow_negative_balance'))->toBeFalse();
    foreach (['simple-reward-config', 'simple-reward-migrations'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(RewardServiceProvider::class, $tag);
        expect($paths)->toHaveCount(1);
        foreach (array_keys($paths) as $source) {
            expect(is_file($source))->toBeTrue();
        }
    }
});

it('uses the configured table for migrations and reward queries', function (): void {
    config(['simple-reward.table_name' => 'custom_rewards']);
    $migration = require __DIR__.'/../../database/migrations/create_rewards_table.php.stub';
    $migration->up();

    expect((new Reward)->getTable())->toBe('custom_rewards');
    $account = Account::query()->create();
    $account->rewardCredit(5);
    expect($account->rewardBalance())->toBe(5.0)
        ->and(Schema::hasColumns('custom_rewards', ['rewardable_type', 'rewardable_id', 'running_balance', 'rule_application_key']))->toBeTrue();
    $migration->down();
    expect(Schema::hasTable('custom_rewards'))->toBeFalse();
});

it('renders the shipped Boost guideline', function (): void {
    $guideline = file_get_contents(__DIR__.'/../../resources/boost/guidelines/core.blade.php');
    $rendered = Blade::render($guideline);
    expect($rendered)->toContain('Simple Reward', HasRewards::class, 'simple-reward-migrations');
});

it('keeps indexes bounded distinct and stable with long table names and connection prefixes', function (): void {
    $connection = Schema::getConnection();
    $originalPrefix = $connection->getTablePrefix();
    $originalTable = config('simple-reward.table_name');
    $tableName = 'simple_reward_index_test_'.str_repeat('x', 30);
    $cases = [
        ['table' => $tableName, 'prefix' => ''],
        ['table' => $tableName, 'prefix' => 'tenant_'],
        ['table' => $tableName.'b', 'prefix' => ''],
    ];
    $allNames = [];
    $migration = require __DIR__.'/../../database/migrations/create_rewards_table.php.stub';

    try {
        foreach ($cases as $case) {
            $connection->setTablePrefix($case['prefix']);
            config(['simple-reward.table_name' => $case['table']]);
            $migration->up();

            $indexes = array_values(array_filter(Schema::getIndexes($case['table']), fn (array $index): bool => ! $index['primary']));
            expect($indexes)->toHaveCount(4)
                ->and(Schema::hasIndex($case['table'], ['rewardable_type', 'rewardable_id']))->toBeTrue()
                ->and(Schema::hasIndex($case['table'], ['rewardable_type', 'rewardable_id', 'id']))->toBeTrue()
                ->and(Schema::hasIndex($case['table'], ['rewardable_type', 'rewardable_id', 'created_at']))->toBeTrue()
                ->and(Schema::hasIndex($case['table'], ['rule_application_key'], 'unique'))->toBeTrue();

            $names = array_column($indexes, 'name');
            foreach ($names as $name) {
                expect(mb_strlen($name))->toBeLessThanOrEqual(63);
            }

            expect(array_intersect($allNames, $names))->toBe([]);
            $allNames = [...$allNames, ...$names];
        }

        $migration->down();
        $migration->up();
        $recreated = array_values(array_filter(Schema::getIndexes($case['table']), fn (array $index): bool => ! $index['primary']));
        $recreatedNames = array_column($recreated, 'name');
        sort($recreatedNames);
        sort($names);
        expect($recreatedNames)->toBe($names);
    } finally {
        try {
            foreach ($cases as $case) {
                $connection->setTablePrefix($case['prefix']);
                Schema::dropIfExists($case['table']);
            }
        } finally {
            $connection->setTablePrefix($originalPrefix);
            config(['simple-reward.table_name' => $originalTable]);
        }
    }
});

it('publishes a current timestamped migration and reuses its filename on repeat publication', function (bool $updateMigrationDates): void {
    $directory = sys_get_temp_dir().'/simple-reward-publish-'.bin2hex(random_bytes(6));
    $oldDatabasePath = $this->app->databasePath();
    $oldConfigPath = $this->app->configPath();
    $this->app->useDatabasePath($directory.'/database');
    $this->app->useConfigPath($directory.'/config');
    config(['database.migrations.update_date_on_publish' => $updateMigrationDates]);
    $this->travelTo(Date::parse('2030-02-03 04:05:06'));

    $provider = new RewardServiceProvider($this->app);
    $provider->boot();

    try {
        $this->artisan('vendor:publish', ['--tag' => 'simple-reward-migrations'])->assertSuccessful();
        $this->artisan('vendor:publish', ['--tag' => 'simple-reward-config'])->assertSuccessful();
        $files = glob($directory.'/database/migrations/*_create_rewards_table.php');
        expect($files)->toHaveCount(1)
            ->and(basename($files[0]))->toBe('2030_02_03_040506_create_rewards_table.php')
            ->and(require $directory.'/config/simple-reward.php')->toBe([
                'allow_negative_balance' => false,
                'table_name' => 'rewards',
                'rules' => [],
            ]);

        file_put_contents($files[0], "\n// Application customization.\n", FILE_APPEND);
        $this->travel(1)->day();
        $provider->boot();
        $this->artisan('vendor:publish', ['--tag' => 'simple-reward-migrations'])->assertSuccessful();
        expect(glob($directory.'/database/migrations/*_create_rewards_table.php'))->toBe($files)
            ->and(file_get_contents($files[0]))->toContain('// Application customization.');

        $this->artisan('vendor:publish', ['--tag' => 'simple-reward-migrations', '--force' => true])->assertSuccessful();
        expect(glob($directory.'/database/migrations/*_create_rewards_table.php'))->toBe($files)
            ->and(file_get_contents($files[0]))->toBe(file_get_contents(__DIR__.'/../../database/migrations/create_rewards_table.php.stub'));

        $migration = require $files[0];
        $migration->down();
        $this->artisan('migrate', ['--path' => $directory.'/database/migrations', '--realpath' => true, '--force' => true])->assertSuccessful();
        $account = Account::query()->create();
        $account->rewardCredit(1);
        expect($account->rewardBalance())->toBe(1.0);

        expect(glob($directory.'/database/migrations/*.php'))->toHaveCount(1);
        config(['simple-reward.rules' => [AdditionalRule::class]]);
        expect(resolve(RewardRules::class)->apply($account, 'order.completed', 'order:1'))->toHaveCount(1)
            ->and(resolve(RewardRules::class)->apply($account, 'order.completed', 'order:1'))->toBe([])
            ->and($account->rewardBalance())->toBe(26.0);
    } finally {
        $this->app->useDatabasePath($oldDatabasePath);
        $this->app->useConfigPath($oldConfigPath);
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([true, false]);
