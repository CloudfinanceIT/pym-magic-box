<?php
namespace Mantonio84\pymMagicBox\Models;
use \Mantonio84\pymMagicBox\Alias;

class pmbAlias extends pmbBase {
	
	
	protected $guarded=["id"];
	protected $casts=["expires_at" => "datetime", "adata" => "array"];

	public function performer(){
		return $this->belongsTo(pmbPerformer::class);
	}
	
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
	
	public function scopeOfPerformers($query, $v){
		if (is_array($v)){
			return $query->whereIn("performer_id",array_map("intval",$v));
		}else if (is_int($v) || ctype_digit($v)){
			return $query->where("performer_id",intval($v));
		}
		return $query;
	}
	
	public function getPmbLogData(): array {
            
		return array_merge($this->only(["performer_id","customer_id"]),["alias_id" => $this->getKey()]);
	}
	
	
}