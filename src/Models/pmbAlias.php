<?php
namespace Mantonio84\pymMagicBox\Models;
use \Mantonio84\pymMagicBox\Alias;
use \Illuminate\Database\Eloquent\SoftDeletes;

class pmbAlias extends pmbBaseWithPerformer  {
	 use SoftDeletes;
	
	protected $guarded = ["id"];
	protected $casts = ["expires_at" => "datetime", "adata" => "array", "confirmed_at" => "datetime"];

	
	public function scopeConfirmed($query, bool $v = true)
	{
		if ($v){
			return $query->whereNotNull("confirmed_at");
		}else{
			return $query->whereNull("confirmed_at");
		}
	}
	
	public function scopeExpired($query)
	{
		return $query->where("expires_at","<",now())->whereNotNull("expires_at");
	}
	
	public function scopeNotExpired($query)
	{
		return $query->where(function ($q){
			return $q->where("expires_at",">",now())->orWhereNull("expires_at");
		});
	}
		
	public function toEditable(){
		return new Alias($this->performer->merchant_id, $this);
	}
		
	
	public function getPmbLogData(): array 
	{            
		return array_merge($this->only(["performer_id","customer_id"]),["alias_id" => $this->getKey()]);
	}
        
    public function getConfirmedAttribute()
    {
		return !is_null($this->confirmed_at);
	}
	
	public function setConfirmedAttribute(bool $value)
	{
		$this->confirmed_at = $value ? now() : null;
	}
}