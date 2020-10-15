<?php
namespace Mantonio84\pymMagicBox\Console;

use Illuminate\Console\Command;

use Mantonio84\pymMagicBox\Models\pmbMethod;
use Mantonio84\pymMagicBox\Models\pmbPerformer;


class RevokeAllMethods extends Command
{
    protected $signature = 'pmb:revoke-all-methods {merchant} {--delete}';

    protected $description = 'Rimuove tutti i metodi di pagamento disponibili da un soggetto creditore';

    public function handle()
    {   
        $merchant_id=$this->argument("merchant");        
        if (!$this->isUuid($merchant_id)){
            $this->error("Invalid merchant given!");
            return 1;
        }
        
    
        
        if (!pmbPerformer::merchant($merchant_id)->exists()){
            if (!$this->confirm("Merchant '$merchant_id' does not exists. Do you want to add it?",true)){
                return 0;
            }
        }
        
        
        foreach (pmbMethod::all() as $method){
            $performer=pmbPerformer::merchant($merchant_id)->ofMethods($method)->first();
            if (!is_null($performer)){   
                if ($this->option("delete")){
                    $performer->delete();
                }else{
                    $performer->enabled=false;
                    $performer->save();
                }
            }        
        }
        
        $this->info("OK: al available methods revoked to merchant '".$merchant_id);
    }
    
    protected function isUuid($value) {
        if (! is_string($value)) {
            return false;
        }
        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }	
}