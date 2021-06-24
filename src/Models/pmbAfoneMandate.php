<?php
namespace Mantonio84\pymMagicBox\Models;
use \Illuminate\Database\Eloquent\SoftDeletes;

class pmbAfoneMandate extends pmbBaseWithPerformer  {	
	 use SoftDeletes;
	 
	protected $guarded=["id"];
        protected $casts=["confirmed_at" => "datetime", "beneficiary_ready" => "boolean"];
        protected $appends=["confirmed"];
	
        public function scopeIban($query, string $value){
            return $query->where("iban",$value);
        }
	
        public function scopeConfirmed($query,bool $v=true){
		if ($v){
			return $query->whereNotNull("confirmed_at");
		}else{
			return $query->whereNull("confirmed_at");
		}
	}
        
        public function getConfirmedAttribute(){
		return !is_null($this->confirmed_at);
	}
	
	public function setConfirmedAttribute(bool $value){
		$this->confirmed_at = $value ? now() : null;
	}
	
	public function getPmbLogData(): array {            
		return ["performer_id" => $this->performer_id];
	}
	
	public function scopeCustomer($query, string $value){
		return $query->where("customer_id",$value);
	}
}