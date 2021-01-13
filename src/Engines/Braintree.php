<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbBraintreeUser;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Illuminate\Support\Arr;
use \Illuminate\Support\Str;
use \Illuminate\Http\Request;

class Braintree extends Base {
    
    protected $gt;
    
    public static function autoDiscovery(){
        return [
            "name" => "braintree",          
        ];
    }
        
    protected function validateConfig(array $config) {
        return [
            "environment" => ["required","string","in:sandbox,production"],
            "merchantId" => ["required","string","regex:/^[a-z0-9]{16}$/"],
            "publicKey" => ["required","string","regex:/^[a-z0-9]{16}$/"],
            "privateKey" => ["required","string","regex:/^[0-9a-f]{32}$/"],
			"ignore_settled_status" => ["bail","nullable","boolean"],			
        ];
    }   
    
    protected function onProcessAliasConfirm(pmbAlias $alias, array $data = array()): bool {
        return false;
    }

    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null) {
        $n=Arr::get($data,"nonceFromTheClient");
        if (empty($n)){
            return $this->throwAnError("You must specify a 'nonceFromTheClient'!");
        }
        $data['customerId']=$this->getBtUser($customer_id,$data)->bt_customer_id;      
        Arr::set($data,"options.failOnDuplicatePaymentMethod",true);   
		$data['paymentMethodNonce']=$data['nonceFromTheClient'];
		unset($data['nonceFromTheClient']);
        $result=$this->request("paymentMethod", "create", [$data]);
        return ["token" => $result->paymentMethod->token];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {        
        $this->request("paymentMethod","delete",[$alias->adata['token']]);
        return true;
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array(), string $customer_id): processPaymentResponse {        
        $n=Arr::get($data,"deviceData");
        if (empty($n)){
            return $this->throwAnError("You must specify a 'deviceData'!");
        }
        Arr::set($data,"options.submitForSettlement",true);
        $data['amount'] = (string) $payment->currency->numberFormat($payment->amount);        
        Arr::forget($data, ["customerId"]);                
        if (empty($alias_data)){
            Arr::forget($data, ["paymentMethodToken"]);
            $n=Arr::get($data,"paymentMethodNonce");
            if (empty($n)){
                return $this->throwAnError("You must specify a 'paymentMethodNonce'!");
            }
        }else{
            Arr::forget($data, ["paymentMethodNonce"]);
            $data['paymentMethodToken']=$alias_data->adata['token'];
        }
        $result=$this->request("transaction", "sale", [$data]);        
        return processPaymentResponse::make([
            "billed" => true,
            "confirmed" => ($result->transaction->status=="SETTLED" || $this->cfg("ignore_confirm",false)),
			"tracker" => $result->transaction->id,
            "transaction_ref" => $result->transaction->id,
            "other_data" > $result->transaction->jsonSerialize()
        ]);               
    }

    protected function onProcessPaymentConfirm(pmbPayment $payment, array $data = array()): bool {
		$transaction=isset($data['_btt_']) ? optional($data['_btt_']) : $this->getRemoteTransaction($payment);
		$payment->other_data=$transaction->jsonSerialize();
        return ($transaction->status=="SETTLED");
    }

    protected function onProcessRefund(pmbPayment $payment, float $amount, array $data = array()): bool {
		
        $result=$this->request("transaction", "refund", [$payment->transaction_ref, (string) $payment->currency->numberFormat($amount)]);   
        $this->registerARefund($payment,$amount,$result->transaction->id,$result->transaction);
        return true;
    }

    public function isAliasConfirmable(pmbAlias $alias): bool {
        return false;
    }

    public function isConfirmable(pmbPayment $payment): bool {
        return ($payment->billed && !$payment->confirmed && $payment->refunded_amount==0);
    }

    public function isRefundable(pmbPayment $payment): float {
        if (!$payment->billed || !$payment->confirmed){
            return 0;
        }		
        return $payment->refundable_amount;
    }

    public function supportsAliases(): bool {
        return true;
    }
	
	public function webhook(Request $request){
		$request->validate([
			"bt_signature" => ["required","string",'regex:/^[a-z0-9]{16}\|[a-f0-9]{40}$/'],
			"bt_payload" => ["required","string",'regex:/^[a-zA-Z0-9\/\r\n+]*={0,2}$/']
		]);
		
		$webhookNotification=$this->request("webhookNotification","parse",[$request->bt_signature,$request->bt_payload]);		
		$fun="wb".ucfirst(Str::camel(strtolower($webhookNotification->kind)));
		if (method_exists($this,$fun)){
			$this->log("DEBUG", "Webhook calls '$fun'...",$webhookNotification);
			return call_user_func([$this,$fun],$webhookNotification);
		}
		return response("nop.",422);
	}
	
	protected function wbTransactionSettled($webhookNotification){
		$payment=pmbPayment::ofPerformers($this->performer)->billed()->where("tracker",$webhookNotification->transaction->id)->first();
		if (is_null($payment)){
			$this->log("NOTICE", "Webhook transaction_settled: suitable payment not found!",$webhookNotification);		
			if (!empty($webhookNotification->transaction->refundedTransactionId)){
				return $this->refundViaWebhook(
					$webhookNotification->transaction->refundedTransactionId, 
					$webhookNotification->transaction->id,
					$webhookNotification->transaction->amount, 
					$webhookNotification, 
					"webhook_refund", 
					$webhookNotification->transaction
				);		
				
			}
			return null;
		}
		$this->log("DEBUG", "Webhook transaction_settled: found payment #".$payment->getKey()."...",$webhookNotification,["py" => $payment]);
		if ($payment->confirmed){
			$this->log("INFO", "Webhook transaction_settled: payment #".$payment->getKey()." was already confirmed: skipped",$webhookNotification,["py" => $payment]);
			return response("ok.");
		}
		$a=$this->confirm($payment,["_btt_" => $webhookNotification->transaction]);
		if ($a->confirmed){
			return response("ok.");
		}
		return null;
	}
	
	
	protected function wbTransactionSettlementDeclined($webhookNotification){
		return $this->refundViaWebhook(
			$webhookNotification->transaction->id, 
			$webhookNotification->transaction->id,
			$webhookNotification->transaction->amount, 
			$webhookNotification, 
			"transaction_settlement_declined", 
			$webhookNotification->transaction
		);		
	}	
	
	protected function wbDisputeLost($webhookNotification){
		return $this->refundViaWebhook(
			$webhookNotification->dispute->transaction->id, 
			$webhookNotification->dispute->transaction->id,
			$webhookNotification->dispute->transaction->amount, 
			$webhookNotification, 
			"dispute_lost", 
			$webhookNotification->dispute
		);		
	}
	
	protected function refundViaWebhook($transactionId, $refundTransactionId, $amount, $webhookNotification, $reason, $refundDetails=""){
		$webhookNotificationKind=$$webhookNotification->kind;
		$payment=pmbPayment::ofPerformers($this->performer)->billed()->where("tracker",$transactionId)->first();
		if (is_null($payment)){
			$this->log("ERROR", "Webhook refund $webhookNotificationKind failed: suitable payment not found!",$webhookNotification);		
			return null;
		}
		$this->log("DEBUG", "Webhook refund $webhookNotificationKind: found payment #".$payment->getKey()."...",$webhookNotification,["py" => $payment]);
		if (!$payment->confirmed){
			$this->log("INFO", "Webhook refund $webhookNotificationKind: payment #".$payment->getKey()." not confirmed yet: proceed with decline operation",$webhookNotification,["py" => $payment]);			
			event(new \Mantonio84\pymMagicBox\Events\Payment\Error($this->merchant_id,$payment,"payment-cancelled"));
			return response("ok.");
		}
		$amount=floatval($amount);
		if ($amount<=0){
			$amount=$payment->refundable_amount;
		}else{
			$amount=min($amount,$payment->refundable_amount);
		}
		$this->forceRefund($payment, $amount, $refundTransactionId, $refundDetails, $reason);
		return response("ok.");
	}
	
	protected function wbCheck($webhookNotification){
		$this->log("DEBUG", "Webhook test OK!",$webhookNotification);
		return response("ok.");
	}
    
    public function getClientToken(string $pmb_customer_id, array $customerData=[], $BtMerchantAccountId=null){
        $a=["customerId" => $this->getBtUser($pmb_customer_id,$customerData)->bt_customer_id];
        if (!empty($BtMerchantAccountId)){
            $a['merchantAccountId']=$BtMerchantAccountId;
        }                
        $clientToken=$this->request("clientToken", "generate", [$a]);
        if (!is_string($clientToken) || empty($clientToken)){
            return $this->throwAnError("Cannot generate a client token!");
        }
        return $clientToken;
    }
	
	protected function getRemoteTransaction(pmbPayment $payment){		
		return optional($this->request("transaction", "find", [$payment->transaction_ref], true));   				
	}
    
    protected function withBaseData(array $data=[]){
        return array_merge($data,Arr::only($this->config, ["environment","merchantId","publicKey","privateKey"]));
    }
        
    
    protected function getBtUser(string $pmb_customer_id, array $customerData=[]){        
        $a=pmbBraintreeUser::ofPerformers($this->performer)->btUser($this->withBaseData())->pmbCustomer($pmb_customer_id)->first();
        if ($a){
            $this->log("DEBUG", "Found bt user for '$pmb_customer_id': '".$a->bt_customer_id."'","",["cu" => $a]);
            return $a;
        }
        $a=pmbBraintreeUser::make([
            "pmb_customer_id" => $pmb_customer_id,
            "bt_user" => $this->withBaseData(),            
        ])->performer()->associate($this->performer);
        $this->log("DEBUG", "Bt user not found for '$pmb_customer_id'. Creating...");        
        $result=$this->request("customer", "create", [Arr::only($customerData,['firstName','lastName','company','email','phone','fax','website','customerBrowser','customerIp'])]);
        $a->bt_customer_id=$result->customer->id;
        $a->save();
        $this->log("INFO", "Created bt user for '$pmb_customer_id': '".$a->bt_customer_id."'","",["cu" => $a]);
        return $a;
    }    
    
    protected function request(string $gateway_name, string $gateway_function, array $args, bool $suppress_error=false){
        $pid=uniqid();
        $this->log("DEBUG","[$pid] Gateway request of ".$gateway_name." -> ".$gateway_function,$args);
        
        $gateway=$this->gateway()->{$gateway_name}();
		try {
			$result=call_user_func_array([$gateway,$gateway_function], $args);
		}catch (Exception $e){
			$this->log($suppress_error ? "WARNING" : "CRITICAL", "[$pid] Gateway exception: ".$e->getMessage());
			if ($suppress_error){				
				return null;
			}else{
				throw $e;
			}
		}
        
        if ($result instanceof \Braintree\Result\Error){
			if ($suppress_error){
				$this->log("WARNING", "[$pid] Gateway error".isset($result->message) ? ": ".$result->message : "");
				return null;
			}else{
				return $this->throwAnError("[$pid] Gateway error".isset($result->message) ? ": ".$result->message : "","CRITIAL",$result);
			}
        }
        $this->log("DEBUG","[$pid] Gateway success response",$result);        
        return $result;
    }
    
     
    protected function gateway(){
        if (empty($this->gt)){
            $this->gt=new \Braintree\Gateway($this->withBaseData());
        }        
        return $this->gt;        
    }
}
