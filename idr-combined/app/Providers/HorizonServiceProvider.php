<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon dashboard: http://localhost:8000/horizon
 * Open to everyone in the "local" environment; locked down elsewhere.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('admin');
        });
    }
}
