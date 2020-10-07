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
		$this->loadMigrationsFrom(__DIR__.'/Migrations');		
                
                $rpath=base_path('routes/pymMagicBox.php');
                
                if (is_file($rpath)){
                    $this->loadRoutesFrom($rpath);
                }
		
		$this->publishes([
                    __DIR__.'/Config/pymMagicBox.php' => config_path('pymMagicBox.php'),                    
		], 'config');
                
                $this->publishes([
                   __DIR__.'/Config/routes.php' => $rpath,                    
		], 'routes');
    }

    /**
     * Register any application services.
*
     * @return void
     */
    public function register() {

      
    }

}
