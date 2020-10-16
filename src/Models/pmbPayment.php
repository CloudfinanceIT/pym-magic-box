<?php
namespace Mantonio84\pymMagicBox\Models;

use \Illuminate\Database\Eloquent\Model;
use \Mantonio84\pymMagicBox\Payment;


class pmbPayment extends pmbBaseWithPerformer  {
		
	protected $guarded=["id"];        
	protected $casts=["amount" => "float", "billed_at" => "datetime", "confirmed_at" => "datetime", "refunded_at" => "datetime", "other_data" => "array"];
	protected $appends=["billed","confirmed","refunded"];	
	
	
        public function alias(){
            return $this->belongsTo(pmbAlias::class);
        }
        
	public function scopeMerchant($query, string $merchant_id){
		return $query->whereHas("performer",function ($q) use ($merchant_id){
			return $q->where("merchant_id",$merchant_id);
		});
	}
	
	public function scopeBilled($query,bool $v=true){
		if ($v){
			return $query->whereNotNull("billed_at");
		}else{
			return $query->whereNull("billed_at");
		}
	}
	
	public function scopeConfirmed($query,bool $v=true){
		if ($v){
			return $query->whereNotNull("confirmed_at");
		}else{
			return $query->whereNull("confirmed_at");
		}
	}
	
	public function scopeRefunded($query,bool $v=true){
		if ($v){
			return $query->whereNotNull("refunded_at");
		}else{
			return $query->whereNull("refunded_at");
		}
	}
	
	public function scopePending($query){
		return $query->where(function ($q){
			return $query->whereNull("billed_at")->orWhereNull("confirmed_at");
		})->whereNull("refunded_at");
	}

	public function getBilledAttribute(){
		return !is_null($this->billed_at);
	}
	
	public function setBilledAttribute(bool $value){            
		$this->billed_at = $value ? now() : null;
	}
	
	public function getConfirmedAttribute(){
		return !is_null($this->confirmed_at);
	}
	
	public function setConfirmedAttribute(bool $value){
		$this->confirmed_at = $value ? now() : null;
	}
	
	public function getRefundedAttribute(){
		return !is_null($this->refunded_at);
	}
	
	public function setRefundedAttribute(bool $value){
		$this->refunded_at = $value ? now() : null;
	}
	
	public function getPmbLogData(): array {
		return array_merge(["payment_id" => $this->getKey()] ,$this->only(["customer_id","order_ref","amount","performer_id", "currency_code"]));
	}


}