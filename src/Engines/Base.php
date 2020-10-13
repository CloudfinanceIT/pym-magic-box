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
use \Mantonio84\pymMagicBox\Exceptions\pymMagicBoxLoggedException;
use \Illuminate\Contracts\Validation\Validator as ValidatorContract;
use \Mantonio84\pymMagicBox\Engine as pmbEngineWrapper;
use \Carbon\Carbon;
use \Illuminate\Support\Str;
use \Illuminate\Support\Arr;

abstract class Base {
	
	public abstract function isRefundable(pmbPayment $payment): bool;	
	public abstract function supportsAliases(): bool;
        public abstract function isConfirmable(pmbPayment $payment): bool;
	protected abstract function validateConfig(array $config);
	protected abstract function onProcessPayment(pmbPayment $payment, $alias_data, array $data=[]): processPaymentResponse;
	protected abstract function onProcessRefund(pmbPayment $payment, array $data=[]): bool;
	protected abstract function onProcessConfirm(pmbPayment $payment, array $data=[]): bool;
	protected abstract function onProcessAliasCreate(array $data, string $name, string $customer_id="", $expires_at=null): array;	
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
                $vc=$this->validateConfig($this->config);
                if ($vc===false){		
                    throw new invalidMethodConfigException("Invalid config for method key '$snm'!");
		}else if (is_string($vc)){
                    throw new invalidMethodConfigException("Invalid config for method key '$snm': $vc!");
                }else if ($vc instanceof ValidatorContract && $vc->fails()){                    
                    throw invalidMethodConfigException::make("Invalid config for method key '$snm'!")->withErrors($vc->getMessageBag());
                }else if (is_array($vc)){
                    $vcv=\Validator::make($this->config,$vc);
                    if ($vcv->fails()){
                        throw invalidMethodConfigException::make("Invalid config for method key '$snm'!")->withErrors($vcv->getMessageBag());
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
	

	
	public function pay(float $amount, string $currency_code, $alias=null, string $customer_id="", string $order_ref="", array $data=[]) {
                $this->validateCurrencyCodeOrFail($currency_code);
		$payment=$this->resolveNewPaymentModel($order_ref,$amount,$currency_code,$customer_id,$this->performer);	
                $action=null;
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
		return [$payment, $action];
	}
	
	
	public function confirm(pmbPayment $payment, array $data=[]){		
		if ($this->isConfirmable($payment)){
			throw new paymentMethodInvalidOperationException("This method and/or payment does not support confirm operation!");
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
	
	protected function resolveNewPaymentModel($order_ref, $amount, $currency_code, $customer_id, pmbPerformer $performer){
                $payment=new pmbPayment(compact("order_ref","amount", "customer_id", "currency_code"));
		$unique=(config("pymMagicBox.unique_payments",true)===true);		
		if ($unique){			
                    $found=null;
                    $list=pmbPayment::ofPerformers($this->getAllPerformersIds())->where("order_ref",$order_ref)->whereNotNull("order_ref")->where("amount",$amount)->where("currency_code",$currency_code)->get();			
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
                        if (!($e instanceof pymMagicBoxLoggedException)){                        
                            pmbLogger::emergency($this->performer->merchant_id,array_merge($args,["performer" => $this->performer, "ex" => $e]));			
                        }
			return null;
		}
		return $result;
	}
	
	protected function getListenURL(string $action, array $params=[], bool $absolute=true){
                if (!empty($action)){
                    $funName="listen".ucfirst(Str::camel($action));
                    if (config("pymMagicBox.auto_register_routes",true)===true && method_exists($this, $funName)){	
                        return route("pym-magic-box", array_merge($params,["merchant" => $this->performer->merchant_id, "method" => Str::kebab($this->performer->method->name), "action" => $action, $absolute]));
                    }
                }
		return null;
	}
        
       
        
        protected function throwAnError(string $message, $level="EMERGENCY", $details=""){            
            throw new genericMethodException($level, $this->merchant_id, $message, $details, ["pe" => $this->performer]);
            return false;
        }
        
        protected function log($level, string $message, string $details="", array $lg=[]){
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
        
        protected function validateCurrencyCode(string $code) {      
            if (!isset($this->config['currencies'])){ 
                //Siccome la validazione rispetto all'elenco completo delle currencies ISO 4217 viene già eseguito nella classe Mantonio84\pymMagicBox\Engine, è inutile rifarla!
                return true;
            }
            
            $validCurrencies=array_map("strtoupper",array_diff($this->onlyIfIsArray($this->cfg("currencies.valid"), pmbEngineWrapper::getValidCurrencyCodes()),$this->onlyIfIsArray($this->cfg("currencies.forbidden"))));
            
            if (empty($validCurrencies)){
                //Follia!
                return true;
            }
            
            return in_array(strtoupper($code),$validCurrencies);
        }
        
        private function onlyIfIsArray($v,array $default=[]){
		return (is_array($v)) ? $v : $default;
	}
}