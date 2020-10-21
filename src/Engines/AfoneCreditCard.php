<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Validator;
use \Illuminate\Support\Str;
use \Illuminate\Support\Arr;
use Mantonio84\pymMagicBox\Classes\HttpClient;
use \Mantonio84\pymMagicBox\Rules\RouteName;
use Mantonio84\pymMagicBox\Rules\EqualsTo;

class AfoneCreditCard extends Base {
    
    protected $httpclient;
 
    
    public static function autoDiscovery(){
        return [
            "name" => "afone_credit_card",          
        ];
    }
    
    protected function validateConfig(array $config) {
        return [
            "base_uri" => ["required","url"],
            "key" => ["required","string","alpha_num","size:20"],
            "serial_number" => ["required","string",'regex:/^(HOM|VAD)-[\d]{3}-[\d]{3}$/'],
            "force3ds" => ["bail","nullable","integer","in:0,1"],
            "after-3ds-route" => ["required","string", new RouteName],
        ];
        
    }
    
    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null): array {
        if (!isset($data['tokenRef']) || empty($data['tokenRef'])){
            return $this->throwAnError("Invalid tokenRef!");
        }
        $process=$this->httpClient()->post("/rest/alias/tokenCreate", $this->withBaseData([
            "tokenRef" => $data['tokenRef'],
            "aliasRef" => $name,
        ]));
        return $process['alias'];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        return true;
    }

    protected function onProcessConfirm(pmbPayment $payment, array $data = array()): bool {
        $this->httpClient()->post("rest/payment/end3dsDebit", $this->withBaseData([
            "transactionRef" => $payment->transaction_ref,
            "pares" => $data['pares'],
            "md" => $data['md']
        ]));        
        return true;
   }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array()): processPaymentResponse {
        $pd=$this->withBaseData([                
            "amount" => $payment->amount*100,
            "transactionRef" => $this->generateTransactionRef(),
            "customer" => $this->generateCustomerForm($payment, $data),
            "force3ds" => $this->cfg("force3ds",0),
        ]);            
        if (empty($alias_data)){
            if (!isset($data['tokenRef']) || empty($data['tokenRef'])){
                return $this->throwAnError("Invalid tokenRef!");
            }            
            $pd["tokenRef"] = $data['tokenRef'];            
            $process=$this->httpClient()->post("rest/payment/tokenDebit",$pd);            
        }else{
            if (!isset($alias_data['aliasRef']) || empty($alias_data['aliasRef'])){
                return $this->throwAnError("Invalid aliasRef!");
            }  
            if (!isset($data['cvv']) || empty($data['cvv']) || !ctype_digit($dat['cvv'])){
                return $this->throwAnError("aliasDebit requires cvv code!");
            }  
            $pd["alias"] = $alias_data['aliasRef'];
            $pd['ip'] = request()->ip();
            $pd['cvv'] = $data['cvv'];
            $process=$this->httpClient()->post("/rest/payment/aliasDebit",$pd);            
        }        
        if ($process['actionCode']=="AUTH_3DS_REQUISE"){
            $this->log("INFO", "Payment ".$pd['transactionRef']." needs 3ds confirmation");
            $md=Arr::get($process,"transaction.verifyEnrollment3dsMd");
            $vd=[
                "method" => "post",
                "action" => Arr::get($process,"transaction.verifyEnrollment3dsActionUrl"),
                "fields" => ["TermUrl" => $this->getListenURL("confirm-3ds"), "MD" => $md, "PaReq" => Arr::get($process,"transaction.verifyEnrollment3dsPareq")]
            ];
            return processPaymentResponse::make([
                "billed" => true,
                "confirmed" => false,
                "transaction_ref" => $pd['transactionRef'],
                "tracker" => $md,
            ])->needsUserInteraction(view("vendor.mantonio84-pymmagicbox.redirectform",$vd));
        }else{
            return new processPaymentResponse([
                "billed" => true,
                "confirmed" => true,
                "transaction_ref" => $pd['transactionRef']
            ]);
        }
    }
    
    public function listenConfirm3ds(array $request){
        $request=array_change_key_case($request, CASE_LOWER);
        if (!isset($request['md'])){
            return response("Missing data (01)",400);
        }
        if (!isset($request['pares'])){
            return response("Missing data (02)",400);
        }
        $payment=$this->paymentFinderQuery()->billed()->confirmed(false)->refunded(false)->where("tracker",$request['md'])->firstOrFail();
        if (is_null($payment)){
            $this->log("ALERT","3DS confirmation of '".$request['md']."' failed: payment not found!");
            return response("Payment tracking failed!",503);
        }
        $this->log("ALERT","3DS confirmation of '".$request['md']."' ready to start");
        $py=$this->confirm($payment,$request);
        if ($py->confirmed){            
            return redirect()->route($this->config["after-3ds-route"],["payment" => $py->getKey(), "merchant" => $this->merchant_id]);
        }else{
            return response("3ds confirmation failed!",503);
        }
    }
    
    public function isConfirmable(pmbPayment $payment): bool{
        return $payment->billed && !$payment->confirmed && !$payment->refunded && !empty($payment->tracker);
    }
    
    protected function onProcessRefund(pmbPayment $payment, array $data = array()): bool {
        $this->httpClient()->post("/rest/payment/refund",$this->withBaseData([
            "transactionRef" => $payment->transaction_ref,
            "amount" => $payment->amount*100
        ]));
        return true;
    }

    public function isRefundable(pmbPayment $payment): bool {
        return $payment->billed && $payment->confirmed && !$payment->refunded;
    }

    public function supportsAliases(): bool {
        return true;
    }
    
    public function getGenerateTokenURL(){
        return $this->getEndPointURL("/rest/token/create");
    }
    
    public function getGenerateTokenClientData(){
        return Arr::except($this->withBaseData([]),["key"]);
    }
    
    protected function generateCustomerForm(pmbPayment $payment, array $data){
        $ret=array();
        if (isset($data['customer']) && is_array($data['customer'])){
            $ret=array_filter(Arr::only($data['customer'],["customerRef","firstName","lastName","email","road","zipCode","city","country","phone","meetingDate"]));
        }
        if (!isset($ret['customerRef']) && !empty($payment->customer_id)){
            $ret['customerRef']=$payment->customer_id;
        }
        return empty($ret) ? null : $ret;
    }
    
    protected function getEndPointURL(string $uri=""){        
        return $this->httpClient()->getEndPointURL($uri);
    }
    
    protected function httpClient(){        
        if (is_null($this->httpclient)){
            $this->httpclient=HttpClient::make($this->merchant_id, $this->cfg("base_uri"))
                    ->withLogData(["pe" => $this->performer])
                    ->validateResponsesWith(function ($rp){                        
                        return (intval(Arr::get($rp,"ok",0))==1);
                    });
        }
        return $this->httpclient;
    }
  
    
    protected function withBaseData(array $data){
        return array_merge($data,Arr::only($this->config,["key","serialNumber"]),["origin" => url("")]);
    }
    
    protected function generateTransactionRef(){
        return Str::random(32);
    }
    
    protected function validateCurrencyCode(string $code) {    
        return (strtoupper($code)=="EUR");
    }
}
