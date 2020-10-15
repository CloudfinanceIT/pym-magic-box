<?php
namespace Mantonio84\pymMagicBox\Console;

use Illuminate\Console\Command;

use Mantonio84\pymMagicBox\Models\pmbMethod;
use Mantonio84\pymMagicBox\Models\pmbPerformer;


class RevokeMethod extends Command
{
    protected $signature = 'pmb:revoke-method {merchant} {method}  {--delete}';

    protected $description = 'Rimuove un metodo di pagamento ad un soggetto creditore';

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
        
        $performer=pmbPerformer::merchant($merchant_id)->ofMethods($method)->first();
        if (!is_null($performer)){            
            if ($this->option("delete")){
                $performer->delete();
            }else{
                $performer->enabled=false;
                $performer->save();
            }
        }
        
        $this->info("OK: method '".$method->name."' revoked to merchant '".$merchant_id."'");
    }
    
    protected function isUuid($value) {
        if (! is_string($value)) {
            return false;
        }
        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }	
}