<?php
namespace Mantonio84\pymMagicBox\Console;

use Illuminate\Console\Command;

use Mantonio84\pymMagicBox\Models\pmbMethod;
use Mantonio84\pymMagicBox\Models\pmbPerformer;


class GrantMethod extends Command
{
    protected $signature = 'pmb:grant-method {merchant} {method}';

    protected $description = 'Aggiunge un metodo di pagamento ad un soggetto creditore';

    public function handle()
    {   
        $merchant_id=$this->argument("merchant");        
        if (!$this->isUuid($merchant_id)){
            $this->error("Invalid merchant given!");
            return 1;
        }
        
        $method=null;
        $m=$this->argument("method");
        
        if (is_int($m) || ctype_digit($m)){
            $method=pmbMethod::find(intval($m));
        }else if (is_string($m) && !empty($m)){
            $method=pmbMethod::where("name",$m)->first();
        }
        
         if (is_null($method)){
            $this->error("Invalid method given!");
            return 2;
        }
        
        if (!pmbPerformer::merchant($merchant_id)->exists()){
            if (!$this->confirm("Merchant '$merchant_id' does not exists. Do you want to add it?",true)){
                return 0;
            }
        }
        
        $performer=pmbPerformer::merchant($merchant_id)->ofMethods($method)->first();
        if (is_null($performer)){
            $performer=new pmbPerformer(["merchant_id" => $merchant_id, "enabled" => true]);
            $performer->method()->associate($method);
            $performer->save();
        }else{
            $performer->enabled=true;
            $performer->save();
        }
        
        $this->info("OK: method '".$method->name."' granted to merchant '".$merchant_id."', performer id #".$performer->getKey().".");
    }
    
    protected function isUuid($value) {
        if (! is_string($value)) {
            return false;
        }
        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }	
}