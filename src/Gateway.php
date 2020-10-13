<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbMethod;
use \Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Illuminate\Support\Str;


class Gateway extends Base {
    
   
    
    public static function of(string $merchant_id){
	return new static($merchant_id);
    }
    
    protected $engines=array();            
    	
    public function __construct(string $merchant_id){
	$this->acceptMerchantId($merchant_id);
        $this->currencyCode(config("pymMagicBox.default_currency_code","EUR"));
    }
    
    public function __get($name){
        return $this->build($name);
    }        
        
    public function getAllAvailableMethods(){
        return pmbPerformer::with("method")->merchant($this->merchant_id)->enabled()->get()->pluck("method.name","method.id")->all();
    }
    
    public function build($name){
        if (!array_key_exists($name, $this->engines)){                  
            $this->engines[$name]=null;
            $performer=pmbPerformer::with("method")->whereHas("method",function ($q) use ($name){
                return $q->where("name",$name);
            })->merchant($this->merchant_id)->enabled()->first();              
            if ($performer){
                $this->engines[$name]=new Engine($this->merchant_id,$performer->getEngine());
            }
        }        
        return $this->engines[$name];
    }
        
}   