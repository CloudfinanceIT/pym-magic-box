<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use Mantonio84\pymMagicBox\Models\pmbAfoneMandate;
use \Validator;
use \Illuminate\Support\Str;
use \Illuminate\Support\Arr;
use \Mantonio84\pymMagicBox\Classes\HttpClient;
use \Mantonio84\pymMagicBox\Rules\IBAN;
use \Mantonio84\pymMagicBox\Rules\RouteName;
use Mantonio84\pymMagicBox\Exceptions\pymMagicBoxValidationException;

class AfoneSddr extends Base {
    
    protected $httpclient;
 
    
    public static function autoDiscovery(){
        return [
            "name" => "afone_sddr",          
        ];
    }
    
    protected function validateConfig(array $config) {
        return [
            "base_uri" => ["required","url"],
            "key" => ["required","string","alpha_num","size:20"],
            "serial_number" => ["required","string",'regex:/^(HOM|VAD)-[\d]{3}-[\d]{3}$/'],            
            "after-mandate-sign-route" => ["required","string", new RouteName],
        ];
        
    }
    
    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null): array {
        return [];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        return false;
    }

    protected function onProcessConfirm(pmbPayment $payment, array $data = array()): bool {
        if (!isset($data['mandate'])){
            return $this->throwAnError("SEPA Mandate required!","EMERGENCY",["py" => $payment]);
        }
        if (is_int($data['mandate']) || ctype_digit($data['mandate'])){
            $data['mandate']=pmbAfoneMandate::ofPerformers($this->performer)->find(intval($data['mandate']));
            if (is_null($data['mandate'])){
                 return $this->throwAnError("SEPA Mandate not found!","EMERGENCY",["py" => $payment]);
            }
        }
        if ($data['mandate']->confirmed){
             return $this->throwAnError("SEPA Mandate already confirmed!","EMERGENCY",["py" => $payment]);
        }
        if ($payment->tracker!=$data['mandate']->demande_signature_id){
            return $this->throwAnError("SEPA Mandate signature id mismatch!","EMERGENCY",["py" => $payment]);
        }
        $this->httpClient()->post("/rest/sepa/sdd/endCreate",$this->withBaseData([
            "transactionRef" => $payment->transaction_ref,
            "signId" => $data['mandate']->demande_signature_id
        ]));
        $data['mandate']->confirmed=true;
        $data['mandate']->save();
        $this->log("DEBUG","SEPA Mandate confirmed successfully",["py" => $payment]);
        $payment->tracker=null;
        $payment->other_data=Arr::except($payment->other_data, ["iban","rum"]);
        $payment->billed=true;
        return true;        
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array()): processPaymentResponse {
        if (!isset($data['label']) || empty($data['label'])){
            return $this->throwAnError("No SDD Label given!");            
        }
        if (!isset($data['iban']) || empty($data['iban'])){
            return $this->throwAnError("Invalid iban!");            
        }
        $data['iban']=str_replace(" ", "", $data['iban']);
        $ibanValid=IBAN::validate($data['iban']);
        if ($ibanValid!==true){
            return $this->throwAnError($ibanValid);            
        }
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->iban($data['iban'])->confirmed()->first();
        if ($mandate){
            $this->log("INFO","Found SEPA mandate #".$mandate->getKey()." for IBAN ".$data['iban']);
            $mandate->touch();
            $transactionRef=$this->generateTransactionRef();
            $process=$this->httpClient()->post("/rest/sepa/sdd/createFromMandate", $this->withBaseData([
                "transactionRef" => $transactionRef,
                "rum" => $mandate->rum,
                "last" => false,
                "amount" => $payment->amount*100,
                "label" => $data['label'],
                "transferDate" => now()->format("YmdHis"),                
            ]));
            return new processPaymentResponse([
               "billed"  => true,
               "confirmed" => true,
               "transaction_ref"  => $transactionRef,
               "other_data" => ["sepaTransferId" => Arr::get($process,"sepaTransfer.sepaTransferId")]
            ]);
        }else{
            $customer=$this->generateCustomerForm($payment, $data);
            if (empty($customer)){
                return $this->throwAnError("No customer data given!");
            }            
            $this->log("INFO","No SEPA mandate found for IBAN ".$data['iban']);          
            $transactionRef=$this->generateTransactionRef();
            $pd=[                
                "transferType" => "SDDR",                
                "amount" => $payment->amount*100,
                "label" => $data['label'],
                "transferDate" => now()->format("YmdHis"),          
                "iban" => data['iban'],
                "customer" => json_encode($customer),
                "redirectUrl" => $this->getListenURL("mandate-signed"),
                "transactionRef" => $transactionRef,
            ];
            if (isset($data['bic'])){
                $pd['bic']=$data['bic'];
            }
            $process=$this->httpClient()->post("/rest/sepa/sdd/signMandateAndCreate", $this->withBaseData($pd));            
            $a=null;
            parse_str(parse_url($process['actionUrl'],PHP_URL_QUERY),$a);
            if (!isset($a['did'])){
                return $this->throwAnError("CRITICAL"."Mandate signature failed: no signature_id in action url!");
            }
            $this->log("INFO","Mandate signature required: redirect to ".$process['actionUrl']);
            return processPaymentResponse::make([
                "transaction_ref" => $transactionRef, 
                "tracker" => $a['did'],
                "other_data" => [
                    "sepaTransferId" => Arr::get($process,"sepaTransfer.sepaTransferId"),
                    "rum" => Arr::get($process,"sepaTransfer.rum"),
                    "iban" => $data['iban']
                ]
            ])->needsUserInteraction(redirect($process['actionUrl']));
        }
    }
    
    public function listenMandateSigned(array $request){
        $request=array_change_key_case($request, CASE_LOWER);
        $v=Validator::make($request,[
            "result" => ["required","string","in:OK"],
            "cancelled" => ["required","string","in:false"],
            "demandesignatureid" => ["required","integer"]
        ]);
        if ($v->fails()){
            throw pymMagicBoxValidationException::make("SEPA Mandate sign error")->withErrors($v->getMessageBag())->loggable("EMERGENCY",$this->merchant_id,["pe" => $this->performer]);
        }
        $payment=pmbPayment::ofPerformers($this->performer)->billed(false)->confirmed(false)->refunded("false")->where("tracker", $request['demandesignatureid'])->first();
        if (is_null($payment)){
            return $this->throwAnError("SEPA Mandate sign error","EMERGENCY","Pending payment not found!");
        }
        $mandate=pmbAfoneMandate::make([
            "iban" => $payment->other_data['iban'],
            "rum" => $payment->other_data['rum'],
            "demande_signature_id" => $request['demandesignatureid'],            
        ])->performer()->associate($this->performer);
        if (!$mandate->save()){
             return $this->throwAnError("SEPA Mandate sign error","EMERGENCY","Cannot save mandate record!");
        }
        $this->log("DEBUG","SEPA Mandate created successfully",["py" => $payment]);
        $py=$this->confirm($payment, ["mandate" => $mandate]);        
        if ($py->confirmed){            
            return redirect()->route($this->config["after-mandate-sign-route"],["payment" => $py->getKey(), "merchant" => $this->merchant_id]);
        }else{
            return response("Mandate sign confirmation failed!",503);
        }
    }
    
    public function isConfirmable(pmbPayment $payment): bool{
        return !$payment->billed && !$payment->confirmed && !$payment->refunded && !empty($payment->tracker);
    }
    
    protected function onProcessRefund(pmbPayment $payment, array $data = array()): bool {
       
    }

    public function isRefundable(pmbPayment $payment): bool {
        return $payment->billed && $payment->confirmed && !$payment->refunded;
    }

    public function supportsAliases(): bool {
        return false;
    }
     
    protected function generateCustomerForm(pmbPayment $payment, array $data){
        $ret=array();
        if (isset($data['customer']) && is_array($data['customer'])){
            $ret=array_filter(Arr::only($data['customer'],["firstName","lastName","email","road","zipCode","city","country","phone"]));
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
