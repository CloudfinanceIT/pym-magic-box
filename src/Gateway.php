<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbMethod;
use \Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Illuminate\Support\Str;
use \Illuminate\Http\Request;

class Gateway extends Base {
    
   
    
    public static function of(string $merchant_id){
	return new static($merchant_id);
    }
    
    protected static $engines=array();            
    	
    public function __construct(string $merchant_id){
	$this->acceptMerchantId($merchant_id);        
    }
    
    public function __get($name){
        return $this->build($name);
    }        
        
    public function getAllAvailableMethods(){
        return pmbPerformer::with("method")->merchant($this->merchant_id)->enabled()->get()->pluck("method.name","method.id")->all();
    }
            
	
    public function build($name){
		$kr=md5($this->merchant_id.":::".$name);
        if (!array_key_exists($kr, self::$engines)){                  
            self::$engines[$kr]=null;
            $performer=$this->resolvePerformer($name);              
            if ($performer){
               self::$engines[$kr]=new Engine($this->merchant_id,$performer->getEngine());
            }
        }        
        return self::$engines[$kr];
    }
    
    public function findPayment($ref){
        return new Payment($this->merchant_id,$ref);
    }
    
    public function findAlias($ref){
        return new Alias($this->merchant_id,$ref);
    }
    
	protected function resolvePerformer(string $method_name){
		return pmbPerformer::with("method")->whereHas("method",function ($q) use ($method_name){
                return $q->where("name",$method_name);
            })->merchant($this->merchant_id)->enabled()->first();         
	}
}   