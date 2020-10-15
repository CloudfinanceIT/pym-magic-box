<?php
namespace Mantonio84\pymMagicBox\Console;

use Illuminate\Console\Command;

use Mantonio84\pymMagicBox\Models\pmbMethod;
use Mantonio84\pymMagicBox\Models\pmbPerformer;


class GrantAllMethods extends Command
{
    protected $signature = 'pmb:grant-all-methods {merchant}';

    protected $description = 'Aggiunge tutti i metodi di pagamento disponibili ad un soggetto creditore';

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
        
        $ids=array();
        foreach (pmbMethod::all() as $method){
            $performer=pmbPerformer::merchant($merchant_id)->ofMethods($method)->first();
            if (is_null($performer)){
                $performer=new pmbPerformer(["merchant_id" => $merchant_id, "enabled" => true]);
                $performer->method()->associate($method);
                $performer->save();
            }else{
                $performer->enabled=true;
                $performer->save();
            }
            $ids[]=intval($performer->getKey());
        }
        sort($ids);
        $this->info("OK: al available methods granted to merchant '".$merchant_id."', performer ids: ".implode(", ",$ids));
    }
    
    protected function isUuid($value) {
        if (! is_string($value)) {
            return false;
        }
        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }	
}