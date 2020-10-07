<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbLog;
use \Mantonio84\pymMagicBox\Models\pmbPayment;

class Gateway extends Base {
	
	
	public static function of(string $merchant_id){
		return new static($merchant_id);
	}
	
	public function __construct(string $merchant_id){
		$this->acceptMerchantId($merchant_id);
	}
	
	public function pay(float $amount, $method, array $other_data=[], string $customer_id="", string $order_ref=""){ 
		$performer=$this->findMethodPerformerOrFail($method);
		pmbLog::write("DEBUG",$this->merchant_id,["amount" => $amount, "customer_id" => $customer_id, "order_ref" => $order_ref, "pe" => $performer, "message" => "Pay request"]);
		$engine=$this->getEngine($performer);		
		return $this->wrapPaymentModel($engine->pay($amount,null,$customer_id,$order_ref,$other_data));
	}
	
	public function payWithAlias(float $amount, $alias, array $other_data=[], string $order_ref=""){
		$alias=$this->findAliasOrFail($alias);
		pmbLog::write("DEBUG",$this->merchant_id,["amount" => $amount, "al" => $alias, "order_ref" => $order_ref, "pe" => $alias->performer, "message" => "Pay request with alias #".$alias->getKey()." ".$alias->name]);
		$engine=$this->getEngine($alias->performer);
		return $this->wrapPaymentModel($engine->pay($amount,$alias,$alias->customer_id,$order_ref,$other_data));
	}
			
	public function engine($finder){
		$performer=null;
		if (is_scalar($finder)){
			$finder=["method" => $finder];
		}
		if (is_array($finder)){
			if (isset($finder['method'])){
				$performer=$this->findMethodPerformer($finder['method']);
			}
			if (isset($finder['alias']) && is_null($performer)){
				$performer=$this->findAlias($finder['alias'])->performer;
			}
			if (isset($finder['payment']) && is_null($performer)){
				$performer=$this->findPayment($finder['payment'])->performer;
			}
		}
		if ($performer){					
			return new Engine($this->merchant_id,$this->getEngine($performer));
		}else{
			pmbLog::write("EMERGENCY", $this->merchant_id, array_merge($finder,["message" => "Requested engine not found!"]));
		}
		return null;
	}	
		
	
}