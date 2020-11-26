<?php

namespace Mantonio84\pymMagicBox;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class pymMagicBoxServiceProvider extends ServiceProvider {

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {			
        \Route::bind("pymMagicBoxPayment", function ($value) {                
            $a=explode("-",$value);
            if (count($a)==6){
                    $ref=intval($a[5]);
                    unset($a[5]);
                    $merchant_id=implode("-",$a);
                    if (\Mantonio84\pymMagicBox\Base::isUuid($merchant_id)){
                            return new \Mantonio84\pymMagicBox\Payment($merchant_id,$ref);
                    }
            }
        });
       \Route::bind("pymMagicBoxAlias", function ($value) {                
            $a=explode("-",$value);
            if (count($a)==6){
                    $ref=intval($a[5]);
                    unset($a[5]);
                    $merchant_id=implode("-",$a);
                    if (\Mantonio84\pymMagicBox\Base::isUuid($merchant_id)){
                            return new \Mantonio84\pymMagicBox\Alias($merchant_id,$ref);
                    }
            }
        });
	
		Request::macro("qualifiedIp", function (){			
			$IPAddress=$this->ip();
			if (config("app.env")=="local" && !filter_var($IPAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ){
				if (!\Cache::has("external_ip")){
					$realIP = file_get_contents("http://ipecho.net/plain");
					if (filter_var($realIP,FILTER_VALIDATE_IP)){
						\Cache::put("external_ip",$realIP,now()->addHour());
						return $realIP;
					}else{
						return $IPAddress;
					}					
				}else{
					return \Cache::get("external_ip");
				}
			}
			return $IPAddress;
		});
	
		$rpath=base_path('routes/pymMagicBox.php');

        if (is_file($rpath)){
            $this->loadRoutesFrom($rpath);
        }
		
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__.'/Migrations');		         

            $this->commands([
                    \Mantonio84\pymMagicBox\Console\PopulateEngines::class,
                    \Mantonio84\pymMagicBox\Console\GrantMethod::class,
                    \Mantonio84\pymMagicBox\Console\RevokeMethod::class,
                    \Mantonio84\pymMagicBox\Console\GrantAllMethods::class,
                    \Mantonio84\pymMagicBox\Console\RevokeAllMethods::class
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
