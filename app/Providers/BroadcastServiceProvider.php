<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register routes for channel authorization
        Broadcast::routes(['middleware' => ['web', 'auth']]);

        // Register channels
        require base_path('routes/channels.php');
    }
}
