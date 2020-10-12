<?php

namespace Mantonio84\pymMagicBox;

use Illuminate\Support\ServiceProvider;


class pymMagicBoxServiceProvider extends ServiceProvider {

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {			
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/Migrations');		

            $rpath=base_path('routes/pymMagicBox.php');

            if (is_file($rpath)){
                $this->loadRoutesFrom($rpath);
            }

            $this->commands([
                    \Mantonio84\pymMagicBox\Console\PopulateEngines::class,
            ]);				
            $this->publishes([
                __DIR__.'/Config/pymMagicBox.php' => config_path('pymMagicBox.php'),                    
            ], 'mantonio84-pymmagicbox-config');

            $this->publishes([
               __DIR__.'/Config/routes.php' => $rpath,                    
            ], 'mantonio84-pymmagicbox-routes');

            $this->publishes([
               __DIR__.'/Seeders/PymMagicBoxDummyMethodSeeder.php' => database_path("seeders/PymMagicBoxDummyMethodSeeder.php"),                    
            ], 'mantonio84-pymmagicbox-devdummyseeder');
            
            $this->publishes([
                __DIR__.'/Views' => resource_path('views/vendor/mantonio84-pymmagicbox'),
            ], 'mantonio84-pymmagicbox-views');
        }
    }

    /**
     * Register any application services.
*
     * @return void
     */
    public function register() {

      
    }

}
