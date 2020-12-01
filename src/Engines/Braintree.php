<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbBraintreeUser;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Illuminate\Support\Arr;

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
            "privateKey" => ["required","string","regex:/^[0-9a-f]{32}$/"]
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
        $result=$this->request("paymentMethod", "create", [$data]);
        return ["token" => $result->paymentMethod->token];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {        
        $this->request("paymentMethod","delete",[["token" => $alias->adata['token']]]);
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
            "confirmed" => true,
            "transaction_ref" => $result->transaction->id,
            "other_data" > $result->transaction->jsonSerialize()
        ]);               
    }

    protected function onProcessPaymentConfirm(pmbPayment $payment, array $data = array()): bool {
        return false;
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
        return false;
    }

    public function isRefundable(pmbPayment $payment): float {
        if (!$payment->billed && !$payment->confirmed){
            return 0;
        }
        return $payment->refundable_amount;
    }

    public function supportsAliases(): bool {
        return true;
    }
    
    public function getClientToken(string $pmb_customer_id, array $customerData=[], $BtMerchantAccountId=null){
        $a=["customerId" => $this->getBtUser($pmb_customer_id,$customerData)->bt_customer_id];
        if (!empty($BtMerchantAccountId)){
            $a['merchantAccountId']=$BtMerchantAccountId;
        }                
        $clientToken=$this->request("clientToken", "generate", $a);
        if (!is_string($clientToken) || empty($clientToken)){
            return $this->throwAnError("Cannot generate a client token!");
        }
        return $clientToken;
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
        $result=$this->request("customer", "create", Arr::only($customerData,['firstName','lastName','company','email','phone','fax','website','customerBrowser','customerIp']));
        $a->bt_customer_id=$result->customer->id;
        $a->save();
        $this->log("INFO", "Created bt user for '$pmb_customer_id': '".$a->bt_customer_id."'","",["cu" => $a]);
        return $a;
    }    
    
    protected function request(string $gateway_name, string $gateway_function, array $args){
        $pid=uniqid();
        $this->log("DEBUG","[$pid] Gateway request of ".$gateway_name." -> ".$gateway_function,$args);
        
        $gateway=$this->gateway()->{$gateway_name}();
        $result=call_user_func_array([$gateway,$gateway_function], $args);
        
        if ($result instanceof \Braintree\Result\Error){
            return $this->throwAnError("[$pid] Gateway error".isset($result->message) ? ": ".$result->message : "","CRITIAL",$result);
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
