<?php
namespace Mantonio84\pymMagicBox\Models;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;
use \Illuminate\Database\Eloquent\Model;
use \Illuminate\Support\Str;
use \Cache;

class pmbLog extends Model {
		
	protected $guarded=["id"];
	
	protected static $levels=[
		7 => "EMERGENCY", 
		6 => "ALERT", 
		5 => "CRITICAL", 
		4 => "ERROR", 
		3 => "WARNING", 
		2 => "NOTICE", 
		1 => "INFO", 
		0 => "DEBUG"
	];
	
	public static function write($level, string $merchant_id, array $params=[]){		
		$ml=config("pymMagicBox.min_log_level",false);		
		if ($ml===false){
			return null;
		}
		
		static::runLogRotation();
		
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
				$attributes=array_merge(array_filter($pmbBase->getPmbLogData()),$attributes);
			}else if ($md instanceof \Exception){
				$attributes=array_merge(["message" => class_basename($e)." ::: ".$e->getMessage(), "details" => $e->getTraceAsString()],$attributes);
			}
		}
		$attributes=array_filter("is_scalar",$attributes);
		$attributes['level']=$level;
		$attributes['merchant_id']=$merchant_id;
		if (isset($attributes['message'])){
			$attributes['message']=\Str::limit($attributes['message'],255);
		}		
		$a=new static($attributes);
		$a->save();
		return $a;
	}
	
	protected static function runLogRotation(){
		$last=intval(Cache::get("pymMagicBox.logRotatedAt"));
		if (time()-$last>=86400){
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