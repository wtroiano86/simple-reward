<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Wtroiano\SimpleReward\Providers\RewardServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Account');
            $table->timestamps();
        });
        $migration = require __DIR__.'/../database/migrations/create_rewards_table.php.stub';
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Schema::dropIfExists('rewards');
            Schema::dropIfExists('custom_rewards');
            Schema::dropIfExists('accounts');
            Schema::dropIfExists('migrations');
            DB::purge('testing');
        } finally {
            parent::tearDown();
        }
    }

    protected function getPackageProviders(mixed $app): array
    {
        return [RewardServiceProvider::class];
    }

    protected function defineEnvironment(mixed $app): void
    {
        $driver = env('REWARD_TEST_DB_DRIVER', 'sqlite');
        $app->make(Repository::class)->set('database.default', 'testing');
        $app->make(Repository::class)->set('database.connections.testing', [
            'driver' => $driver,
            'host' => env('REWARD_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('REWARD_TEST_DB_PORT', $driver === 'pgsql' ? 5432 : 3306),
            'database' => env('REWARD_TEST_DB_DATABASE', $driver === 'sqlite' ? ':memory:' : 'simple_reward_test'),
            'username' => env('REWARD_TEST_DB_USERNAME', 'root'),
            'password' => env('REWARD_TEST_DB_PASSWORD', ''),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    }
}
