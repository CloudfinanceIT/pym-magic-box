<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbLog;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;
use \Illuminate\Support\Str;
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
	
	public function __call($name, $arguments){		
		$level=array_search(strtoupper($name),static::$levels);
		$c=count($arguments);
		if ($level!==false && $c>=1 && $c<=2){
			array_unshift($arguments, $level);
			return $this->write(...$arguments);
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
			if ($level<0 || $level>7){
				$level=0;
			}			
		}else if (is_string($level)){
			$level=array_search($level,static::$levels);
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
		
		$attributes=array_intersect_key($params,array_flip(["method_name", "alias_id", "performer_id", "payment_id", "amount", "customer_id", "order_ref", "message", "details"]));		
		foreach ($params as $md){
			if ($md instanceof pmbLoggable){
				$attributes=array_merge(array_filter($md->getPmbLogData()),$attributes);
			}else if ($md instanceof \Exception){
				$attributes=array_merge(["message" => class_basename($e)." ::: ".$e->getMessage(), "details" => $e->getTraceAsString()],$attributes);
			}
		}
		$attributes=array_filter($attributes,"is_scalar");
		$attributes['level']=$level;
		$attributes['merchant_id']=$merchant_id;
                $attributes['session_id']=session()->getId();
		if (isset($attributes['message'])){
			$attributes['message']=\Str::limit($attributes['message'],255);
		}		
		$a=new pmbLog($attributes);
		$a->save();
		return $a;
	}
	
	public function rotate(bool $forced=false){
		$last=intval(Cache::get("pymMagicBox.logRotatedAt"));
		if ((time()-$last>=86400) || ($forced)){
			$interval=config("pymMagicBox.log_rotation",false);
			if (is_string($interval)){
				$interval=explode(" ",$interval);
			}
			if (is_array($interval) && count($interval)==2){			
				$when=now()->sub(...$interval);
			}else if ($interval instanceof \Carbon\CarbonInterval){
				$when=now()->sub($interval);
			}
			if (isset($when)){
				static::where("created_at","<=",$when)->delete();
				Cache::forever("pymMagicBox.logRotatedAt",time());
			}
		}
	}
}