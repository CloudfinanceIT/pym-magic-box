<?php
namespace Mantonio84\pymMagicBox\Events\Payment;

use Mantonio84\pymMagicBox\Models\pmbPayment;
use \Illuminate\Support\Arr;
use Mantonio84\pymMagicBox\Payment;

abstract class Base {
	
	public $id;
	public $data;
	public $when;
	public $merchant_id;
	protected $model;
        
        public static function make(string $merchant_id, pmbPayment $payment){
            return new static($merchant_id,$payment);
        }
	
	public function __construct(string $merchant_id, pmbPayment $payment){
		$this->when=now();
		$this->id=intval($payment->getKey());
		$this->merchant_id=$merchant_id;
		$this->data=Arr::except($payment->toArray(),["performer","id"]);		
		$this->model=$payment;
	}
	
	public function getPayment(){
		return new Payment($this->merchant_id,$this->model);
	}
        
    public function with($key,$value=null) {
        if (is_array($key)) {
            foreach ($key as $k => $v){
                $this->with($k,$v);
            }
            return $this;
        }
        if (property_exists($this, $key)){
            $this->{$key}=$value;
        }
        
        return $this;
    }
}