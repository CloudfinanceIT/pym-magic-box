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
use \Mantonio84\pymMagicBox\Exceptions\invalidCurrencyCodeException;
use \Mantonio84\pymMagicBox\Exceptions\genericMethodException;
use \Mantonio84\pymMagicBox\Exceptions\pymMagicBoxException;
use \Illuminate\Contracts\Validation\Validator as ValidatorContract;
use \Mantonio84\pymMagicBox\Engine as pmbEngineWrapper;
use \Carbon\Carbon;
use \Illuminate\Support\Str;
use \Illuminate\Support\Arr;
use \Mantonio84\pymMagicBox\Classes\aliasCreateResponse;
use Mantonio84\pymMagicBox\Models\pmbRefund;
use Mantonio84\pymMagicBox\Classes\Currency;

abstract class Base {
	
	public abstract function isRefundable(pmbPayment $payment): float;	
	public abstract function supportsAliases(): bool;
        public abstract function isConfirmable(pmbPayment $payment): bool;
        public abstract function isAliasConfirmable(pmbAlias $alias): bool;
	protected abstract function validateConfig(array $config);
	protected abstract function onProcessPayment(pmbPayment $payment, $alias_data, array $data=[], string $customer_id): processPaymentResponse;
	protected abstract function onProcessRefund(pmbPayment $payment, float $amount, array $data=[]): bool;
	protected abstract function onProcessPaymentConfirm(pmbPayment $payment, array $data=[]): bool;
        protected abstract function onProcessAliasConfirm(pmbAlias $alias, array $data=[]): bool;
	protected abstract function onProcessAliasCreate(array $data, string $name, string $customer_id="", $expires_at=null): array;	
	protected abstract function onProcessAliasDelete(pmbAlias $alias): bool;	
	
	public $performer;
	protected $config;
	protected $allPerformersIds;
        protected $merchant_id;
        protected $partial_refund_allowed=true;
	
	public function __construct(pmbPerformer $performer){
		$this->performer=$performer;		
		$this->merchant_id=$this->performer->merchant_id;
		$snm=Str::snake($performer->method->name);				
		$this->config=array_merge($this->onlyIfIsArray(config("pymMagicBox.profiles.common.".$snm)),$this->onlyIfIsArray(config("pymMagicBox.profiles.".$this->merchant_id.".".$snm)),$this->onlyIfIsArray($performer->credentials));
                $vc=$this->validateConfig($this->config);
                if ($vc===false){		
                    throw invalidMethodConfigException::make("Invalid config for method key '$snm'!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
		}else if (is_string($vc)){
                    throw invalidMethodConfigException::make("Invalid config for method key '$snm': $vc!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
                }else if ($vc instanceof ValidatorContract && $vc->fails()){                    
                    throw invalidMethodConfigException::make("Invalid config for method key '$snm'!")->withErrors($vc->getMessageBag())->loggable(null, $this->merchant_id, ["pe" => $this->performer]);
                }else if (is_array($vc)){
                    $vcv=\Validator::make($this->config,$vc);
                    if ($vcv->fails()){
                        throw invalidMethodConfigException::make("Invalid config for method key '$snm'!")->withErrors($vcv->getMessageBag())->loggable(null, $this->merchant_id, ["pe" => $this->performer]);
                    }
                }
                if (method_exists($this, "onEngineInit")){
                    $this->onEngineInit();
                }
		pmbLogger::debug($this->performer->merchant_id,["pe" => $this->performer, "message" => "Payment engine '".class_basename($this)."' ready"]);
	}	
        
        public function getConfigurationData(){
            return $this->config;
        }
			
	public function aliasCreate(array $data, string $name, string $customer_id="", $expires_at=null){
		if (!$this->supportsAliases()){
                    throw paymentMethodInvalidOperationException::make("Method '".$this->performer->method->name."' does not support aliases!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer]);
                    return null;
		}
                $action=null;
		$ret=$this->sandbox("onProcessAliasCreate",[$data, $name, $customer_id, $expires_at]);
		if ((!($ret instanceof aliasCreateResponse) && !is_array($ret)) || (empty($ret))){
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "message" => "Alias creation failed!", "details" => $data]);
			return null;
		}
                
		$a=new pmbAlias([			
			"name" => $name,
			"customer_id" => $customer_id,
			"expires_at" => $expires_at instanceof Carbon ? $expires_at : null
		]);
                $a->performer()->associate($this->performer);
                if ($ret instanceof aliasCreateResponse){
                    $action=$ret->getUserInteraction();
                    $a->forceFill($ret->toArray());                    
                }else{
                    $a->adata=$ret;
                    $a->confirmed=true;
                }		                
		$a->save();		
                event(new \Mantonio84\pymMagicBox\Events\Alias\Created($this->merchant_id, $a));
                if ($a->confirmed){
                    event(\Mantonio84\pymMagicBox\Events\Alias\Confirmed::make($this->merchant_id,$a)->with("contemporary_created",true));
                }
		pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "al" => $a, "message" => "Alias created successfully!", "details" => ["input" => $data, "output" => $ret]]);
		return [$a, $action];
	}
	
	public function aliasDelete(pmbAlias $alias){
		if (!$this->supportsAliases()){
                    throw paymentMethodInvalidOperationException::make("Method '".$this->performer->method->name."' does not support aliases!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer]);
                    return false;
		}
		$ret=$this->sandbox("onProcessAliasDelete",[$alias]);
		if ($ret){
			pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "message" => "Alias deleted successfully!", "al" => $alias]);
			$alias->delete();
                        event(new \Mantonio84\pymMagicBox\Events\Alias\Deleted($this->merchant_id, $alias));
		}else{
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "message" => "Alias delete error!", "al" => $alias]);
                        event(new \Mantonio84\pymMagicBox\Events\Alias\Error($this->merchant_id, $alias, "delete"));
		}
		return $ret;
	}
	
        public function aliasConfirm(pmbAlias $alias, array $data=[]){
            if (!$this->isAliasConfirmable($alias)){
                    throw paymentMethodInvalidOperationException::make("This method and/or alias does not support confirm operation!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer, "al" => $alias]);
                    return $alias;
		}		
		$success=$this->sandbox("onProcessAliasConfirm",[$payment,$data]);
		if ($success){
			$alias->confirmed=true;	
			$alias->save();
			pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "al" => $alias, "message" => "Successfully confirmed alias"]);
			event(new \Mantonio84\pymMagicBox\Events\Alias\Confirmed($this->merchant_id,$alias));
		}else{
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "al" => $alias, "message" => "Unsuccessfully confirmed alias"]);
			event(new \Mantonio84\pymMagicBox\Events\Alias\Error($this->merchant_id,$alias,"confirmed"));
		}		
		return $alias;
        }

	
	public function pay(float $amount, string $currency_code, $alias=null, string $customer_id="", string $order_ref="", array $data=[]) {
                $this->validateCurrencyCodeOrFail($currency_code);
		$payment=$this->resolveNewPaymentModel($order_ref,$amount,$currency_code,$customer_id,$this->performer);	
                $action=null;                
		if (!$payment->billed){
			$usealias=false;			
			if ($alias instanceof pmbAlias){					
				if (!$this->supportsAliases()){
					if (!$payment->exists){
						$payment->save();		
					}	
					pmbLogger::alert($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Charging with alias not supported!"]));
					return $payment;				
				}
                                if (!$alias->confirmed){
                                    pmbLogger::alert($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Alias not confirmed!"]));
                                    return $payment;
                                }
				$usealias=true;				
			}
			$process=$this->sandbox("onProcessPayment",[$payment, $usealias ? $alias : null, $data, $customer_id]);
			if ($usealias){								
				$payment->alias()->associate($alias);
			}
			if ($process instanceof processPaymentResponse){
                                $action=$process->getUserInteraction();
				$payment->forceFill($process->toArray());							
				$payment->save();
				if ($process->billed){					
					pmbLogger::info($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Successfully charged"]));
					event(new \Mantonio84\pymMagicBox\Events\Payment\Billed($this->merchant_id,$payment));
                                        if ($process->confirmed){
                                            pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Successfully auto confirmed"]);
                                            event(\Mantonio84\pymMagicBox\Events\Payment\Confirmed::make($this->merchant_id,$payment)->with("contemporary_billing",true));
                                        }
				}else{
					pmbLogger::warning($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully charged"]));
					event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"billed"));
				}	                                
			}else {				
                                $payment->save();										
				pmbLogger::warning($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully charged", "details" => $process]));
				event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"billed"));				
			}			
		}else{
                        $payment->save();	
			pmbLogger::notice($this->performer->merchant_id,array_merge(compact("amount","customer_id","order_ref","alias"),["pe" => $this->performer, "py" => $payment, "message" => "Already charged: skipped"]));
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"already-charged"));
		}
		return [$payment, $action];
	}
	
	
	public function confirm(pmbPayment $payment, array $data=[]){		
		if (!$this->isConfirmable($payment)){
                    throw paymentMethodInvalidOperationException::make("This method and/or payment does not support confirm operation!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer, "py" => $payment]);
                    return $payment;
		}		
		$success=$this->sandbox("onProcessPaymentConfirm",[$payment,$data]);
		if ($success){
			$payment->confirmed=true;	
			$payment->save();
			pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Successfully confirmed payment"]);
			event(new \Mantonio84\pymMagicBox\Events\Payment\Confirmed($this->merchant_id,$payment));
		}else{
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully confirmed payment"]);
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"confirmed"));
		}		
		return $payment;
	}
		
	public function refund(pmbPayment $payment, $amount, array $data=[]){		
                $allowed=$this->isRefundable($payment);
		if ($allowed==0){
                    throw paymentMethodInvalidOperationException::make("Refund operation not allowed!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer, "py" => $payment]);
                    return $payment;
		}		                
                if (is_float($amount) || is_int($amount)){
                    if ($amount<=0){
                        throw paymentMethodInvalidOperationException::make("Refund amount be greater than zero!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer, "py" => $payment]);
                        return $payment;
                    }
                    if ($amount>$allowed){
                        throw paymentMethodInvalidOperationException::make("Excessive refund amount!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer, "py" => $payment]);
                        return $payment;
                    }
                    if ($amount!=$allowed && !$this->partial_refund_allowed){
                        throw paymentMethodInvalidOperationException::make("Partial refunds are not allowed!")->loggable("ALERT", $this->merchant_id, ["pe" => $this->performer, "py" => $payment]);
                        return $payment;
                    }
                }else{
                    $amount=$allowed;
                }
		$success=$this->sandbox("onProcessRefund",[$payment,$amount,$data]);
		if ($success){
			$payment->refunded_amount=$payment->refunded_amount+$amount;		
			$payment->save();
			pmbLogger::info($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Successfully refunded $amount"]);
			event(new \Mantonio84\pymMagicBox\Events\Payment\Refunded($this->merchant_id,$payment));
		}else{
			pmbLogger::warning($this->performer->merchant_id,["pe" => $this->performer, "py" => $payment, "message" => "Unsuccessfully refunded"]);
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"refunded"));
		}		
		return $payment;
	}
	
	protected function resolveNewPaymentModel($order_ref, $amount, $currency_code, $customer_id, pmbPerformer $performer){
                $payment=new pmbPayment(compact("order_ref","amount", "customer_id", "currency_code"));	
                $payment->refunded_amount=0;
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
			throw pmbLogger::make()->reportAnException($e,"EMERGENCY",$this->merchant_id,array_merge($args,["performer" => $this->performer]));						
		}
		return $result;
	}
	
	protected function getListenURL(string $action, array $params=[], bool $absolute=true){
                if (!empty($action)){
                    $funName="listen".ucfirst(Str::camel($action));
                    if (method_exists($this, $funName)){	
                        return route("pym-magic-box", array_merge($params,["merchant" => $this->performer->merchant_id, "method" => Str::kebab($this->performer->method->name), "action" => $action]), $absolute);
                    }
                }
		return null;
	}
        
       protected function tryJsonEncode($items, $default=null){
            if (is_string($items)){
                return $items;
            }else if (is_array($items)) {
                return json_encode($items);        
            } elseif ($items instanceof Arrayable) {
                return json_encode($items->toArray());
            } elseif ($items instanceof Jsonable) {
                return $items->toJson();
            } elseif ($items instanceof \JsonSerializable) {
                return json_encode($items);
            } elseif ($items instanceof \Traversable) {
                return json_encode(iterator_to_array($items));
            }

            return $default;
        }
        
        protected function throwAnError(string $message, $level="EMERGENCY", $details=""){            
            throw genericMethodException::make($message)->loggable($level, $this->merchant_id, ["message" => $message, "details" => $details, "pe" => $this->performer]);
            return false;
        }
        
        protected function log($level, string $message, $details="", array $lg=[]){
            return pmbLogger::make()->write($level,$this->merchant_id,array_merge($lg,["message" => $message, "details" => $details, "pe" => $this->performer]));
        }
        
        protected function cfg(string $path, $default=null){
            return Arr::get($this->config,$path,$default);
        }
        
        protected function paymentFinderQuery(){
            return pmbPayment::ofPerformers($this->performer);
        }
        
        protected function validateCurrencyCodeOrFail(string $code){
            if (!$this->validateCurrencyCode($code)){
                return $this->throwAnError("Currency '$code' not supported!");
            }
            return true;
        }
        
        protected function registerARefund(pmbPayment $payment, float $amount, string $transaction_ref="", $details=null){
            $ret= pmbRefund::make([
                "amount" => $amount,
                "transaction_ref" => $transaction_ref,
                "details" => $this->tryJsonEncode($details)
            ]);
            $ret->payment()->associate($payment);
            $ret->save();
            $payment->unsetRelation("refunds");
            return $ret;
        }


        protected function validateCurrencyCode(string $code) {      
            if (!isset($this->config['currencies'])){ 
                //Siccome la validazione rispetto all'elenco completo delle currencies ISO 4217 viene già eseguito nella classe Mantonio84\pymMagicBox\Engine, è inutile rifarla!
                return true;
            }
            $code=strtoupper($code);
            $forbidden=array_map("strtoupper", $this->cfg("currencies.forbidden",[]));            
            if (in_array($code,$forbidden)){
                return false;
            }            
            
            $valid=$this->cfg("currencies.valid");
            if (is_array($valid)){
                $valid=array_map("strtoupper", $valid);            
                if (!in_array($code,$valid)){
                    return false;
                }
            }
            return Currency::exists($code);
        }
        
        private function onlyIfIsArray($v,array $default=[]){
		return (is_array($v)) ? $v : $default;
	}
}