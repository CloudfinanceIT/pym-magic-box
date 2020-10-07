<?php
namespace Mantonio84\pymMagicBox\Models;
use \Illuminate\Support\Str;


class pmbPerformer extends pmbBase{
		
	protected $guarded=["id"];
	
	protected $casts=["credentials" => "array", "enabled" => "boolean"];
	
	public function scopeMerchant($query, string $merchant_id){
		return $query->where("merchant_id",$merchant_id);
	}
	
	public function scopeEnabled($query, bool $v=true){
		return $query->where("enabled",$v);
	}
	
	public function method(){
		return $this->belongsTo(pmbMethod::class);
	}
	
        public function scopeCurrentAppEnv($query){
            return $query->where(function ($q){
               return $q->where("app_env",config("app.env"))->orWhereNull("app_env");
            });
        }
        
	public function getPmbLogData(): array {
		return [
                    "performer_id" => $this->getKey(),
                    "method_name" => optional($this->method)->name
                ];
		
	}
	
	public function getEngine(){
            $cls=$this->method->engine_class_name;		
            return new $cls($this);		
	}


}