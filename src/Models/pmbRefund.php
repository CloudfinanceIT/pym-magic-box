<?php
namespace Mantonio84\pymMagicBox\Models;
use \Illuminate\Database\Eloquent\Model;

class pmbRefund extends Model {
    
    public static function make(array $data=[]){
        return new static($data);
    }       
    
    protected $guarded=["id"];
    protected $casts=["amount" => "float"];
    
    public function payment(){
        return $this->belongsTo(pmbPayment::class,"payment_id");
    }
}
