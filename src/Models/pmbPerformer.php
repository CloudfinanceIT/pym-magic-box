<?php
namespace Mantonio84\pymMagicBox\Models;
use \Illuminate\Support\Str;


class pmbPerformer extends pmbBase {
		
	protected $guarded=["id"];
	
	protected $casts=["credentials" => "array", "enabled" => "boolean"];
	
	public function scopeMerchant($query, string $merchant_id){
		return $query->where("merchant_id",$merchant_id);
	}
	
	public function scopeEnabled($query, bool $v=true){
		return $query->where("enabled",$v);
	}
	
	public function method(){
		return $this->belongsTo(Method::class);
	}
	
	public function getPmbLogData(): array {
		$ret=["performer_id" => $this->getKey()];
		if ($this->relationLoaded("method") && $this->method){
			$ret["method_name"]=$this->method->name;
		}
		return $ret;
	}
	
	public function getEngine($allPerformersIds=null){
		$cls=$this->method->engine_class_name;
		if (!is_array($allPerformersIds)){
			$allPerformersIds=static::merchant($this->merchant_id)->enabled()->pluck("id")->all();
		}
		return new $cls($this,$allPerformersIds);		
	}
}