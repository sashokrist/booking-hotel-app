<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
        {
            Http::macro('pms', function () {
                return Http::withOptions([
                    'verify'   => base_path('cacert.pem'),
                    'base_uri' => 'https://api.pms.donatix.info/api/',
                    'timeout'  => 30,
                ])->retry(3, 1000);
            });
        }

}
