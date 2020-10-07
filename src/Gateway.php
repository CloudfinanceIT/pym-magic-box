<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbMethod;
use \Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Illuminate\Support\Str;

class Gateway extends Base {
    
    protected $engines=array();
    
    public static function of(string $merchant_id){
	return new static($merchant_id);
    }
    
	
    public function __construct(string $merchant_id){
	$this->acceptMerchantId($merchant_id);
    }
    
    public function __get($name){
        return $this->build($name);
    }
        
    public function build($name){
        if (!array_key_exists($name, $this->engines)){
            $this->engines[$name]=null;
            $performers=pmbPerformer::with("method")->merchant($this->merchant_id)->enabled()->get();
            foreach ($performers as $per){
                if (Str::snake($per->method->name) == $name){
                    $cls=$per->method->engine_class_name;
                    $this->engines[$name]=new Engine($this->merchant_id, $per->getEngine());
                    break;
                }
            }            
        }        
        return $this->engines[$name];
    }
        
}   