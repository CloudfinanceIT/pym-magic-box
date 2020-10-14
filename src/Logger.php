<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbLog;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;
use \Mantonio84\pymMagicBox\Exceptions\pymMagicBoxException;
use \Illuminate\Support\Str;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use \Cache;

class Logger {
	
	protected static $singleton;
			
	protected static $levels=[
		"DEBUG",
		"INFO",
		"NOTICE",
		"WARNING",
		"ERROR",
		"CRITICAL",
		"ALERT",
		"EMERGENCY"
	];	
	
	public static function make(){
		if (is_null(static::$singleton)){
			static::$singleton=new static;
		}
		return static::$singleton;
	}
	
      
	public static function __callStatic($name, $arguments){		
		$c=count($arguments);
		if (ctype_lower($name) && $c>=1 && $c<=2){
			$level=array_search(strtoupper($name),static::$levels);		
			if ($level!==false){
				array_unshift($arguments, $level);				
				return static::make()->write(...$arguments);
			}		
		}
	}
	
	public function write($level, string $merchant_id, array $params=[]){		
		
		$this->rotate();
		
		$ml=config("pymMagicBox.min_log_level",false);		
                
		if ($ml===false){
			return null;
		}						
		if (is_int($level) || ctype_digit($level)){
			$level=intval($level);
			if ($level<0 || $level>count(static::$levels)-1){
				$level=0;
			}			
		}else if (is_string($level)){
			$level=array_search(strtoupper($level),static::$levels);
			if ($level===false){
				$level=0;
			}			
		}else{
			$level=0;
		}		
		
		$minLevel=intval(array_search(intval($ml),static::$levels));
		
		if ($minLevel>$level){
			return null;
		}
		
		$attributes=array_intersect_key($params,array_flip(["method_name", "alias_id", "performer_id", "payment_id", "amount", "customer_id", "order_ref", "message", "details", "currency_code"]));		
		foreach ($params as $md){
                    if ($md instanceof pmbLoggable){
			$attributes=array_merge(array_filter($md->getPmbLogData()),$attributes);
                    }
		}
                if (isset($attributes['details']) && !is_scalar($attributes['details'])){
                    $attributes['details']=$this->tryJsonEncode($attributes['details']);
                }
		$attributes=array_filter($attributes,"is_scalar");
		$attributes['level']=$level;
		$attributes['merchant_id']=$merchant_id;
                $attributes['session_id']=session()->getId();
		if (isset($attributes['message'])){
                    $attributes['message']=\Str::limit($attributes['message'],255);
		}		
                if (isset($attributes['currency_code']) && !Engine::isValidCurrencyCode($attributes['currency_code'])){
                    unset($attributes['currency_code']);
                }
		$a=new pmbLog($attributes);
		$a->save();
		return $a;
	}
	
	public function rotate(bool $forced=false){		
            if (!Cache::has("pymMagicBox.logRotatedAt") || ($forced)){
                $interval=config("pymMagicBox.log_rotation",false);
                if (is_string($interval)){
                    $interval=explode(" ",$interval);
                }
                if (is_array($interval) && count($interval)==2){			
                    $when=now()->sub(...$interval);
                    $lwhen=now()->add(...$interval);
                }else if ($interval instanceof \Carbon\CarbonInterval){
                    $when=now()->sub($interval);
                    $lwhen=now()->add($interval);
                }
                if (isset($when) && isset($lwhen)){
                    static::where("created_at","<=",$when)->delete();
                    Cache::put("pymMagicBox.logRotatedAt",1,$lwhen);
                }
            }
	}
        
    protected function tryJsonEncode($items, $default=null){
        if (is_string($items)){
            return $items;
        }else if (is_array($items)) {
            return json_encode($items);        
        } elseif ($items instanceof Arrayable) {
            return json_encode($items->toArray());
        } elseif ($items instanceof Jsonable) {
            return $items->toJson();
        } elseif ($items instanceof \JsonSerializable) {
            return json_encode($items);
        } elseif ($items instanceof \Traversable) {
            return json_encode(iterator_to_array($items));
        }

        return $default;
    }
}