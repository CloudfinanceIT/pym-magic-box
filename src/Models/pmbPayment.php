<?php
namespace Mantonio84\pymMagicBox\Models;

use \Illuminate\Database\Eloquent\Model;
use \Mantonio84\pymMagicBox\Payment;

class pmbPayment extends pmbBase {
		
	protected $guarded=["id"];        
	protected $casts=["amount" => "float", "billed_at" => "datetime", "confirmed_at" => "datetime", "refunded_at" => "datetime", "other_data" => "array"];
	protected $appends=["billed","confirmed","refunded"];
	
	protected static function boot(){
		parent::boot();
		static::creating(function ($md){
			if (empty($md->bb_code) && $md->method && ($md->method->auto === false || config("pymMagicBox.bb_code.only_manual",true)===false)){
				$l=intval(config("pymMagicBox.bb_code.len",5));
				if ($l<3 || $l>16) $l=5;
				$md->bb_code=static::generateBBCode($l);
			}
		});
	}	
	
	protected static function generateBBCode(int $len){
		$r=null;
		while (is_null($r) || ctype_digit($r)){
			$r=substr(str_shuffle("ABCDEFGHJLMNPQRTUVWXYZ2346789"), 0, $len);
		}
		return $r;
	}
	
	public function toEditable(){
		return new Payment($this->performer->merchant_id, $this);
	}
		
	public function performer(){
		return $this->belongsTo(pmbPerformer::class);
	}
	
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
	
	public function scopeOfPerformers($query, $v){
		if (is_array($v)){
			return $query->whereIn("performer_id",array_map("intval",$v));
		}else if (is_int($v) || ctype_digit($v)){
			return $query->where("performer_id",intval($v));
		}
		return $query;
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
		return array_merge(["payment_id" => $this->getKey()] ,$this->only(["customer_id","order_ref","amount","performer_id"]));
	}
	
}