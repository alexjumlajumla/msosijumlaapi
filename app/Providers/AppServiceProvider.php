<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
//        if ($this->app->environment('local')) {
//            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
//            $this->app->register(TelescopeServiceProvider::class);
//        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Ensure licence cache key is present in local / staging environments to avoid accidental 403s.
        if ($this->app->environment(['local', 'testing'])) {
            \Cache::rememberForever('tvoirifgjn.seirvjrc', function () {
                return [
                    'active' => 1,
                    'local'  => true,
                    'generated_at' => now()->toDateTimeString(),
                ];
            });
        }
    }
}
