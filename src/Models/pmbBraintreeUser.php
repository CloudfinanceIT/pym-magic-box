<?php
namespace Mantonio84\pymMagicBox\Models;


class pmbBraintreeUser extends pmbBaseWithPerformer  {		
    protected $guarded=["id"];
    
    public function setBtUserAttribute($value){
       $this->attributes['bt_user']=$this->castToBtUserHash($value);
    }
    
    public function scopeBtUser($query, $value){
        return $query->where("bt_user",$this->castToBtUserHash($value));
    }
    
    public function scopePmbCustomer($query, $value){
        return $query->where("pmb_customer_id",$value);
    }
    
    public function scopeBtCustomer($query,$value){
        return $query->where("bt_customer_id",$value);
    }
    
    public function getPmbLogData(): array {
        return $this->only(["customer_id","performer_id"]);
    }

    protected function castToBtUserHash($value){
        if (preg_match('/^[0-9a-f]{40}$/', $value)>0){
            return $value;
        }else if (is_array($value)){
            ksort($value);
            return sha1(json_encode($value));
        }else{
            return null;
        }
    }
}