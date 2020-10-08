<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\pmbMethod;
use \Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Exceptions\paymentMethodInvalidOperationException;
use \Mantonio84\pymMagicBox\Exceptions\invalidMethodConfigException;
use \Carbon\Carbon;
use \Illuminate\Support\Str;


abstract class Base {
	
	public abstract function isRefundable(): bool;	
	public abstract function supportsAliases(): bool;
	protected abstract function validateConfig(array $config): bool;
	protected abstract function onProcessPayment(pmbPayment $payment, $alias_data, array $data=[]): processPaymentResponse;
	protected abstract function onProcessRefund(pmbPayment $payment, array $data=[]): bool;
	protected abstract function onProcessConfirm(pmbPayment $payment, array $data=[]): bool;
	protected abstract function onProcessAliasCreate(array $data, string $name="", string $customer_id="", $expires_at=null): array;	
	protected abstract function onProcessAliasDelete(pmbAlias $alias): bool;	
	
	public $performer;
	protected $config;
	protected $allPerformersIds;
        protected $merchant_id;
	
	public function __construct(pmbPerformer $performer){
		$this->performer=$performer;		
		$this->merchant_id=$this->performer->merchant_id;
		$snm=Str::snake($performer->method->name);				
		$this->config=array_merge($this->onlyIfIsArray(config("pymMagicBox.profiles.common.".$snm)),$this->onlyIfIsArray(config("pymMagicBox.profiles.".$this->merchant_id.".".$snm)),$this->onlyIfIsArray($performer->credentials));
		if (!$this->validateConfig($this->config)){
			throw new \invalidMethodConfigException("Invalid config for method key '$snm'!");
		}        
		pmbLogger::debug($this->performer->merchant_id,["pe" => $this->performer, "message" => "Payment engine '".class_basename($this)."' ready"]);
	}	
        
			
	public function aliasCreate(array $data, string $name, string $customer_id="", $expires_at=null){
		if (!$this->supportsAliases()){
			throw new paymentMethodInvalidOperationException("Method '".$this->performer->method->name."' does not support aliases!");
		}
		$ret=$this->sandbox("onProcessAliasCreate",[$data, $name, $customer_id, $expires_at]);
		if (empty($ret)){
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "message" => "Alias creation falied!", "details" => json_encode($data)]);
			return null;
		}
		$a=new pmbAlias([
			"adata" => $ret,
			"name" => $name,
			"customer_id" => $customer_id,
			"expires_at" => $expires_at instanceof Carbon ? $expires_at : null
		]);
		$a->performer()->associate($this->performer);
		$a->save();		
                
		pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "al" => $a, "message" => "Alias created successfully!", "details" => json_encode(["input" => $data, "output" => $ret])]);
		return $a;
	}
	
	public function aliasDelete(pmbAlias $alias){
		if (!$this->supportsAliases()){
			throw new paymentMethodInvalidOperationException("Method '".$this->performer->method->name."' does not support aliases!");
		}
		$ret=$this->sandbox("onProcessAliasDelete",[$alias]);
		if ($ret){
			pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "message" => "Alias deleted successfully!", "al" => $alias]);
			$alias->delete();
		}else{
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "message" => "Alias delete error!", "al" => $alias]);
		}
		return $ret;
	}
	

	
	public function pay(float $amount, $alias=null, string $customer_id="", string $order_ref="", array $data=[]) {
		$payment=$this->resolveNewPaymentModel($order_ref,$amount,$customer_id,$this->performer);		
		if (!$payment->billed){
			$adata=null;
			if ($alias instanceof pmbAlias){
                            $payment->alias()->associate($alias);
				if ($this->supportsAliases()){
					$adata=$alias->adata;
				}else{
					if (!$payment->exists){
						$payment->save();		
					}	
					pmbLogger::alert($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Charging with alias not supported!"]));
					return $payment;
				}
			}
			$process=$this->sandbox("onProcessPayment",[$payment,$adata,$data]);
			if ($process instanceof processPaymentResponse){
				$payment->forceFill($process->toArray());			
				if ($this->performer->method->auto && $process->billed){
					$payment->confirmed=true;
				}
				$payment->save();
				if ($process->billed){					
					pmbLogger::info($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Successfully charged"]));
					event(new \Mantonio84\pymMagicBox\Events\Payment\Billed($this->merchant_id,$payment));
				}else{
					pmbLogger::warning($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully charged"]));
					event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"billed"));
				}			
				return $payment;
			}else {
				if (!$payment->exists){
					$payment->save();		
				}				
				pmbLogger::warning($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully charged", "details" => json_encode($process)]));
				event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"billed"));				
			}			
		}else{
			pmbLogger::notice($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Already charged: skipped"]));
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"already-charged"));
		}
		return $payment;
	}
	
	
	public function confirm(pmbPayment $payment, array $data=[]){		
		if ($this->performer->method->auto){
			throw new paymentMethodInvalidOperationException("Method '".$this->performer->method->name."' does not support manual confirm operation!");
		}
		$unique=(config("pymMagicBox.unique_payments",true)===true);
		if ($payment->billed && (!$payment->confirmed || !$unique) && !$payment->refunded){
			$success=$this->sandbox("onProcessConfirm",[$payment,$data]);
			if ($success){
				$payment->confirmed=true;	
				$payment->save();
				pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Successfully confirmed"]);
				event(new \Mantonio84\pymMagicBox\Events\Payment\Confirmed($this->merchant_id,$payment));
			}else{
				pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully confirmed"]);
				event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"confirmed"));
			}
		}else{
			pmbLogger::notice($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Not suitable for confirmation: skipped", "details" => json_encode($payment->only(["billed","confirmed","refunded"]))]);
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"confirm-unsuitable"));
		}
		return $payment;
	}
		
	public function refund(pmbPayment $payment, array $data=[]){		
		if (!$this->isRefundable()){
			throw new paymentMethodInvalidOperationException("Method '".$this->performer->method->name."' does not support refund operation!");
		}
		$unique=(config("pymMagicBox.unique_payments",true)===true);
		if ($payment->billed && $payment->confirmed && (!$payment->refunded  || !$unique)){
			$success=$this->sandbox("onProcessRefund",[$payment,$data]);
			if ($success){
				$payment->refunded=true;		
				$payment->save();
				pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Successfully refunded"]);
				event(new \Mantonio84\pymMagicBox\Events\Payment\Refunded($this->merchant_id,$payment));
			}else{
				pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully refunded"]);
				event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"refunded"));
			}
		}else{
			pmbLogger::notice($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Not suitable for refund: skipped", "details" => json_encode($payment->only(["billed","confirmed","refunded"]))]);
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"refund-unsuitable"));
		}
		return $payment;
	}
	
	protected function resolveNewPaymentModel($order_ref, $amount, $customer_id, pmbPerformer $performer){
                $payment=new pmbPayment(compact("order_ref","amount", "customer_id"));
		$unique=(config("pymMagicBox.unique_payments",true)===true);		
		if ($unique){			
                    $found=null;
                    $list=pmbPayment::ofPerformers($this->getAllPerformersIds())->where("order_ref",$order_ref)->where("amount",$amount)->whereNotNull("order_ref")->get();			
                    if ($list->isNotEmpty()){
                            $found=$list->firstWhere("performer_id",$performer->getKey()) ?? $list->first();			
                    }
                    if ($found){
                        $payment=$found;
                    }
		}				
		$payment->performer()->associate($performer);
		return $payment;
	}
        
        protected function getAllPerformersIds(){
            if (is_null($this->allPerformersIds)){
                $this->allPerformersIds=pmbPerformer::merchant($this->merchant_id)->enabled()->pluck("id")->unique()->all();
            }
            return $this->allPerformersIds;
        }
	
	protected function sandbox(string $funName, array $args=[]){
		try {
			$result=$this->{$funName}(...$args);
		}catch (\Exception $e) {
			pmbLogger::emergency($this->performer->merchant_id,array_merge($args,["performer" => $this->performer, "ex" => $e]));
			throw $e;
			return null;
		}
		return $result;
	}
	
	protected function getListenURL(string $funName, array $params=[], bool $absolute=true){
		if (config("pymMagicBox.auto_register_routes",true)===true && Str::startsWith($funName,"listen") && $funName!="listen"){	
			return route("pym-magic-box", array_merge($params,["merchant" => $this->performer->merchant_id, "method" => Str::kebab($this->performer->method->name), "action" => Str::kebab(substr($funName,6))]), $absolute);
		}
		return null;
	}
        
    private function onlyIfIsArray($v,array $default=[]){
		return (is_array($v)) ? $v : $default;
	}
}