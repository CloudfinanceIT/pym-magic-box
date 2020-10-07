<?php
namespace Mantonio84\pymMagicBox\Models;
use \Mantonio84\pymMagicBox\Alias;

class pmbAlias extends pmbBaseWithPerformer  {
	
	
	protected $guarded=["id"];
	protected $casts=["expires_at" => "datetime", "adata" => "array"];

	
	
	public function scopeExpired($query){
		return $query->where("expires_at","<",now())->whereNotNull("expires_at");
	}
	
	public function scopeNotExpired($query){
		return $query->where(function ($q){
			return $q->where("expires_at",">",now())->orWhereNull("expires_at");
		});
	}
		
	public function toEditable(){
		return new Alias($this->performer->merchant_id, $this);
	}
	
	
	
	public function getPmbLogData(): array {
            
		return array_merge($this->only(["performer_id","customer_id"]),["alias_id" => $this->getKey()]);
	}

}