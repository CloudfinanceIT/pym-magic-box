<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Validator;
use \Illuminate\Support\Str;
use \Illuminate\Support\Arr;

class AfoneCreditCard extends Base {
    
    protected $httpclient;
    
    public static function autoDiscovery(){
        return [
            "name" => "afone_credit_card",          
        ];
    }
    
    protected function validateConfig(array $config) {
        return Validator::make($config,[
            "base_uri" => ["required","url"],
            "key" => ["required","string","alpha_num","size:20"],
            "serialNumber" => ["required","string",'regex:/^(HOM|VAD)-[\d]{3}-[\d]{3}$/'],
            "force3ds" => ["bail","nullable","integer","in:0,1"],
            "after-3ds-route" => ["required","string"],
        ]);
        
    }
    
    protected function onEngineInit(){
        $this->config['base_uri']=Str::finish($this->config['base_uri'],"/");
    }
	
    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null): array {
        if (!isset($data['tokenRef']) || empty($data['tokenRef'])){
            return $this->throwAnError("Invalid tokenRef!");
        }
        $process=$this->parseResponse($this->post("/rest/alias/tokenCreate", $this->withBaseData([
            "tokenRef" => $data['tokenRef'],
            "aliasRer" => $name,
        ])));
        return $process['alias'];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        return true;
    }

    protected function onProcessConfirm(pmbPayment $payment, array $data = array()): bool {
        $this->parseResponse($this->post("rest/payment/end3dsDebit", $this->withBaseData([
            "transactionRef" => $payment->transaction_ref,
            "pares" => $data['pares'],
            "md" => $data['md']
        ])));
        $payment->tracker=null;
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
            $process=$this->parseResponse($this->post("rest/payment/tokenDebit",$pd));            
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
            $process=$this->parseResponse($this->post("/rest/payment/aliasDebit",$pd));            
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
        $this->parseResponse($this->post("/rest/payment/refund",$this->withBaseData([
            "transactionRef" => $payment->transaction_ref,
            "amount" => $payment->amount*100
        ])));
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
            $ret=Arr::only($data['customer'],["customerRef","firstName","lastName","email","road","zipCode","city","country","phone","meetingDate"]);
        }
        if (!isset($ret['customerRef']) && !empty($payment->customer_id)){
            $ret['customerRef']=$payment->customer_id;
        }
        return empty($ret) ? null : $ret;
    }
    
    protected function getEndPointURL(string $uri=""){        
        if (!empty($uri)){
            if (Str::endsWith($uri, "/")){
                $uri=substr($uri,-1);
            }
            if (Str::startWith($uri, "/")){
                $uri=substr($uri,1);
            }
        }
        return $this->config['base_uri'].$uri;
    }
    
    protected function httpClient(){        
        if (is_null($this->httpclient)){
            $this->httpclient=\GuzzleHttp\Client([            
                'base_uri' => $this->getEndPointURL(),                
                'timeout'  => 2.0,
            ]);
        }
        return $this->httpclient;
    }
    
    protected function post(string $uri, array $data){
        return $this->httpClient()->request("POST", $uri,["form_params" => $data]);
    }
    
    protected function parseResponse($response){
        $body=(string) $response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data) || $response->getStatusCode()!=200){
            return $this->throwAnError("Invalid POST response","ALERT",$body);
        }
        if (!isset($data['ok'])){
            return $this->throwAnError("Invalid POST response","ALERT",$body);
        }
        if ($data['ok']==0){
             return $this->throwAnError("POST error: ".$data['message'],"ALERT",$body);
        }
        return $data;
    }
        
    
    protected function withBaseData(array $data){
        return array_merge($data,Arr::only($this->config,["key","serialNumber"]),["origin" => url("")]);
    }
    
    protected function generateTransactionRef(){
        return Str::random(32);
    }
}
