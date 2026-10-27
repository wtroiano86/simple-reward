<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward\Providers;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

final class RewardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/simple-reward.php', 'simple-reward');
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 2).'/config/simple-reward.php' => config_path('simple-reward.php'),
        ], 'simple-reward-config');

        $this->publishes([
            dirname(__DIR__, 2).'/database/migrations/create_rewards_table.php.stub' => $this->migrationPath(),
        ], 'simple-reward-migrations');
    }

    private function migrationPath(): string
    {
        $existing = glob(database_path('migrations/*_create_rewards_table.php')) ?: [];

        // Reuse published filenames, including for --force, instead of creating duplicates.
        return $existing[0] ?? database_path('migrations/'.Date::now()->format('Y_m_d_His').'_create_rewards_table.php');
    }
}
