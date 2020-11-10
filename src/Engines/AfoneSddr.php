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
use \Mantonio84\pymMagicBox\Exceptions\pymMagicBoxValidationException;
use \Mantonio84\pymMagicBox\Rules\EqualsTo;

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
		$iban=$this->validateIban(Arr::get($data,"iban"));				
        return [
			"iban" => $iban
		];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
		$iban=$this->validateIban(Arr::get($alias->adata,"iban"));		
		$mandate_id=intval(Arr::get($alias->adata,"mandate_id"));
		if ($mandate_id>0){
			$mandate=pmbAfoneMandate::ofPerformers($this->performer)->iban($iban)->find($mandate_id);
			if ($mandate){
				if ($mandate->confirmed){
					$this->httpClient()->post("/rest/sepa/mandate/disable",$this->withBaseData([
						"rum" => $mandate->rum,
						"transactionRef" => $mandate->first_transaction_ref
					]));
				}
				$mandate->delete();
			}
		}
        return true;
    }

    protected function onProcessConfirm(pmbPayment $payment, array $data = array()): bool {
        $md=$payment->other_data['mandate'];
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->confirmed()->find(intval($md[1]));
        if (is_null($mandate)){
            return $this->throwAnError("SEPA Mandate not found!","EMERGENCY",["py" => $payment]);
        }
        if ($payment->tracker!="DID:".$mandate->demande_signature_id){
            return $this->throwAnError("SEPA Mandate signature id mismatch!","EMERGENCY",["py" => $payment]);
        }        
        $payment->tracker=null;        
        $payment->billed=true;
        return true;        
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array()): processPaymentResponse {
        if (!isset($data['label']) || empty($data['label'])){
            return $this->throwAnError("No SDD Label given!");            
        }
		$mandate=null;
        if (empty($alias_data)){
			$data['iban']=$this->validateIban(Arr::get($data,"iban"));
			$mandate=pmbAfoneMandate::ofPerformers($this->performer)->iban($data['iban'])->confirmed()->first();
		}else{
			$data['iban']=$alias_data->adata['iban'];
			$mandate_id=intval(Arr::get($alias_data->adata,"mandate_id"));
			if ($mandate_id>0){
				$mandate=pmbAfoneMandate::ofPerformers($this->performer)->iban($data['iban'])->confirmed()->find($mandate_id);
			}
		}
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
               "other_data" => ["sepaTransferId" => Arr::get($process,"sepaTransfer.sepaTransferId"), 'mandate' => [class_basename($mandate), $mandate->getKey()]]
            ]);
        }else{
            $customer=$this->generateCustomerForm($payment, $data);
            if (empty($customer)){
                return $this->throwAnError("No customer data given!");
            }            
            $this->log("DEBUG","No SEPA mandate found for IBAN ".$data['iban'].". Mandate signature procedure in progress...");          
            $transactionRef=$this->generateTransactionRef();
            $pd=[                
                "transferType" => "SDDR",                
                "amount" => $payment->amount*100,
                "label" => $data['label'],
                "transferDate" => now()->format("YmdHis"),          
                "iban" => $data['iban'],
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
            $did=isset($a['did']) ? intval($a['did']) : 0;
            unset($a);
            if ($did<=0){
                return $this->throwAnError("CRITICAL"."Mandate signature failed: no signature_id in action url!");
            }
            $this->log("INFO","No SEPA mandate found for IBAN ".$data['iban'].". Redirect to ".$process['actionUrl']);    
            $mandate=pmbAFoneMandate::ofPerformers($this->performer)->confirmed(false)->iban($data['iban'])->first();
            if (is_null($mandate)){
                $mandate=pmbAfoneMandate::make(Arr::only($data,["iban"]))->performer()->associate($this->performer);
            }
			$mandate->rum=Arr::get($process,"sepaTransfer.rum",$mandate->rum);
			$mandate->demande_signature_id=$did;	
			$mandate->first_transaction_ref=$transactionRef;
            $mandate->save();
            if (!empty($alias_data)){
				$alias_data->adata['mandate_id']=$mandate->getKey();
				$alias_data->save();
			}
            return processPaymentResponse::make([
                "transaction_ref" => $transactionRef, 
                "tracker" => "DID:".$did,
                "other_data" => ["sepaTransferId" => Arr::get($process,"sepaTransfer.sepaTransferId"), 'mandate' => [class_basename($mandate), $mandate->getKey()]]
            ])->needsUserInteraction(response()->redirectTo($process['actionUrl']));
        }
    }
	
	public function hasValidMandate($iban){
		$iban=$this->validateIban($iban);
		return pmbAfoneMandate::ofPerformers($this->performer)->iban($iban)->exists();
	}
    
    public function listenMandateSigned(array $request){
        $request=array_change_key_case($request, CASE_LOWER);
        $v=Validator::make($request,[
            "result" => ["required","string",new EqualsTo("OK")],
            "cancelled" => ["required","string",new EqualsTo("false")],
            "demandesignatureid" => ["required","integer","min:1"]
        ]);
        if ($v->fails()){
            throw pymMagicBoxValidationException::make("SEPA Mandate sign error")->withErrors($v->getMessageBag())->loggable("EMERGENCY",$this->merchant_id,["pe" => $this->performer]);
        }
        $did=intval($request['demandesignatureid']);
        $payment=pmbPayment::ofPerformers($this->performer)->billed(false)->confirmed(false)->refunded(false)->where("tracker", "DID:".$did)->first();
        if (is_null($payment)){
            return $this->throwAnError("SEPA Mandate sign error","EMERGENCY","Pending payment not found!");
        }
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->confirmed(false)->where("demande_signature_id",$did)->first();
        if (is_null($mandate)){
            return $this->throwAnError("SEPA Mandate sign error","EMERGENCY","Pending mandate record not found!");
        }
        $this->httpClient()->post("/rest/sepa/sdd/endCreate",$this->withBaseData([
            "transactionRef" =>	$mandate->first_transaction_ref,
            "signId" => $did
        ]));        
        $mandate->confirmed=true;
        if (!$mandate->save()){
             return $this->throwAnError("SEPA Mandate sign error","EMERGENCY","Cannot save mandate record!");
        }        
        $py=$this->confirm($payment, ["mandate" => $mandate]);        
        if ($py->confirmed){            
			$this->log("INFO","SEPA Mandate signature process completed successfully","",["py" => $payment]);
            return redirect()->route($this->config["after-mandate-sign-route"],["pymMagicBoxPayment" => $this->merchant_id."-".$py->getKey()]);
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
        return true;
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
         return array_merge($data,[
				"key" => $this->cfg("key"),
				"serialNumber" => $this->cfg("serial_number"),
				"origin" => url("")
		]);
    }
    
    protected function generateTransactionRef(){
        return Str::random(32);
    }
    
    protected function validateCurrencyCode(string $code) {    
        return (strtoupper($code)=="EUR");
    }
	
	protected function validateIban($iban){
		if (!is_string($iban) || empty($iban)){
			return $this->throwAnError("No iban given!");            
		}
		$iban=strtoupper(str_replace(" ", "", $iban));
        $ibanValid=IBAN::validate($iban);
        if ($ibanValid!==true){
            return $this->throwAnError($ibanValid);            
        }
		return $iban;
	}
}
