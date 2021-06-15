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
use Mantonio84\pymMagicBox\Classes\aliasCreateResponse;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use Mantonio84\pymMagicBox\Payment;

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
    
    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null) {
        $iban=$this->validateIban(Arr::get($data,"iban"));				
        $customer_id=trim($customer_id);
        if (empty($customer_id)){
            return $this->throwAnError("customer_id is required!");            
        }        
        $adata=["iban" => $iban];
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->iban($adata['iban'])->customer($customer_id)->confirmed()->first();
        if ($mandate){
             $this->log("INFO","Found SEPA mandate #".$mandate->getKey()." for IBAN ".$adata['iban']);
            return $adata;
        }else{            
            $customer=$this->generateCustomerForm($data);
            if (empty($customer)){
                return $this->throwAnError("No customer data given!");
            }                 
            $this->log("DEBUG","No SEPA mandate found for IBAN ".$adata['iban'].". Mandate signature procedure in progress...");          
            $transactionRef=$this->generateTransactionRef();
            $pd=[                
                "transferType" => "SDDR",                
                "amount" => 0,
                "label" => $name,
                "transferDate" => now()->format("YmdHis"),          
                "iban" => $adata['iban'],
                "customer" => json_encode($customer),
                "redirectUrl" => $this->getListenURL("mandate-signed-alias"),
                "transactionRef" => $transactionRef,
            ];          
            $process=$this->httpClient()->post("/rest/sepa/sdd/signMandateAndCreate", $this->withBaseData($pd));            
            $a=null;
            parse_str(parse_url($process['actionUrl'],PHP_URL_QUERY),$a);
            $did=isset($a['did']) ? intval($a['did']) : 0;
            unset($a);
            if ($did<=0){
                return $this->throwAnError("CRITICAL"."Mandate signature failed: no signature_id in action url!");
            }
            $this->log("INFO","No SEPA mandate found for IBAN ".$adata['iban'].". Redirect to ".$process['actionUrl']);    
            $mandate=pmbAFoneMandate::ofPerformers($this->performer)->confirmed(false)->iban($adata['iban'])->customer($customer_id)->first();
            if (is_null($mandate)){
                $mandate=pmbAfoneMandate::make(["iban" => $adata['iban'], "customer_id" => $customer_id])->performer()->associate($this->performer);
            }
            $mandate->rum=Arr::get($process,"sepaTransfer.rum",$mandate->rum);
            $mandate->demande_signature_id=$did;	
            $mandate->first_transaction_ref=$transactionRef;
            $mandate->save();            
            $adata['mandate']=[class_basename($mandate), $mandate->getKey()];
            return aliasCreateResponse::make([                
                "tracker" => "DID:".$did,
                "adata" => $adata
            ])->needsUserInteraction(response()->redirectTo($process['actionUrl']));
        }
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

    protected function onProcessPaymentConfirm(pmbPayment $payment, array $data = array()): bool {
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

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array(), string $customer_id): processPaymentResponse {		
        if (!isset($data['label']) || empty($data['label'])){
            return $this->throwAnError("No SDD Label given!");            
        }
		$mandate=null;
        if (empty($alias_data)){
			$customer_id=trim($customer_id);			
			if (empty($customer_id)){
				return $this->throwAnError("customer_id is required!");            
			}
			$data['iban']=$this->validateIban(Arr::get($data,"iban"));			
		}else{
			$data['iban']=$alias_data->adata['iban'];			
			$customer_id=$alias_data->customer_id ?? $customer_id;
			if (empty($customer_id)){
				return $this->throwAnError("alias customer_id is required!");            
			}
		}		
		$mandate=pmbAfoneMandate::ofPerformers($this->performer)->iban($data['iban'])->customer($customer_id)->confirmed()->first();
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
               "other_data" => ["iban" => $data['iban'], "sepaTransferId" => Arr::get($process,"sepaTransfer.sepaTransferId"), 'mandate' => [class_basename($mandate), $mandate->getKey()]]
            ]);
        }else{
            $customer=$this->generateCustomerForm($data);
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
                "redirectUrl" => $this->getListenURL("mandate-signed-payment"),
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
            $mandate=pmbAFoneMandate::ofPerformers($this->performer)->confirmed(false)->iban($data['iban'])->customer($customer_id)->first();
            if (is_null($mandate)){
                $mandate=pmbAfoneMandate::make(["iban" => $data['iban'], "customer_id" => $customer_id])->performer()->associate($this->performer);
            }
			$mandate->rum=Arr::get($process,"sepaTransfer.rum",$mandate->rum);
			$mandate->demande_signature_id=$did;	
			$mandate->first_transaction_ref=$transactionRef;
            $mandate->save();            
            return processPaymentResponse::make([
                "transaction_ref" => $transactionRef, 
                "tracker" => "DID:".$did,
                "other_data" => ["iban" => $data['iban'], "sepaTransferId" => Arr::get($process,"sepaTransfer.sepaTransferId"), 'mandate' => [class_basename($mandate), $mandate->getKey()]]
            ])->needsUserInteraction(response()->redirectTo($process['actionUrl']));
        }
    }
	
	public function hasValidMandate($iban, $customer_id){
		$iban=$this->validateIban($iban);
		return pmbAfoneMandate::ofPerformers($this->performer)->iban($iban)->customer($customer_id)->exists();
	}
    
    public function listenMandateSignedPayment(array $request){
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
        $payment=null;
       
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
        }
        
        return response("Mandate sign confirmation failed!",503);
    }
	
	public function listenMandateSignedAlias(array $request){
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
        $payment=null;
        $alias=null;
      
        $alias=pmbAlias::ofPerformers($this->performer)->confirmed(false)->where("tracker", "DID:".$did)->first();
		if (is_null($alias)){
            return $this->throwAnError("SEPA Mandate sign error","EMERGENCY","Pending alias not found!");
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
   
        $al=$this->aliasConfirm($alias,["mandate" => $mandate]);
        if ($al->confirmed){            
			$this->log("INFO","SEPA Mandate signature process completed successfully","",["al" => $al]);
            return redirect()->route($this->config["after-mandate-sign-route"],["pymMagicBoxAlias" => $this->merchant_id."-".$al->getKey()]);
        }
        
        return response("Mandate sign confirmation failed!",503);
    }
    
    
    public function isConfirmable(pmbPayment $payment): bool{
        return !$payment->billed && !$payment->confirmed && !$payment->refunded && !empty($payment->tracker);
    }
    
    public function prepareRefund(Payment $macroPayment){
        $payment=$macroPayment->toBase();
        $mandate_id=intval(Arr::get($payment->other_data,"mandate.1"));
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->confirmed()->find($mandate_id);
        if (is_null($mandate)){
            return $this->throwAnError("No SEPA mandate found!");
        }        
        $ret=$this->prepareMandateForRefund($mandate);
        $this->log("INFO","SEPA beneficiary status is $ret");
        return $ret;
    }
    
    public function checkAllRefundsReadyState(){         
        $mandates=pmbAfoneMandate::ofPerformers($this->performer)->confirmed()->whereNotNull("beneficiary_id")->where("beneficiary_ready",false)->get();
        $this->log("DEBUG","Found ".count($mandates)." SEPA beneficiary to check...");
        if ($mandates->isNotEmpty()){
            $remote=$this->loadRemoteBeneficiaryList();
            foreach ($mandates as $md){
                $ret=$this->evaluateBeneficiaryState($md, $remote);
                if ($md->wasChanged("beneficiary_ready")){
                    $this->log("INFO","SEPA beneficiary status of mandate #".$md->getKey()." now is '$ret'");
                }
            }
        }
        $this->log("DEBUG","Check of ".count($mandates)." SEPA beneficiary completed!");
        return $mandates;
    }
	
	public function findSepaTransfers(array $data=[],int $per_request_limit=100){
		$this->log("DEBUG","Starting afone SEPA transfers search...");
        $client=HttpClient::make($this->merchant_id, $this->cfg("base_uri"))
                ->withLogData(["pe" => $this->performer])
                ->validateResponsesWith([
                    "empty" => ["required","boolean"],
                    "nbTransfers" => ["required", "integer"],
                    "sepaTransfer" => ["present","array"],
                    "sepaTransfer.*" => ["bail","nullable","array"],
                    "sepaTransfer.*.ok" => ["bail","nullable","integer","in:0,1"],
                    "sepaTransfer.*.confirmed" => ["bail","nullable","integer","in:0,1"],
					"sepaTransfer.*.transactionRef" => ["bail","nullable","string"],
                ]);
        $page=1;
        $max=null;
        $found=array();      		
        while (is_null($max) || $page<=$max){
            $segment=$client->post("/rest/sepa/transfer/find", $this->withBaseData(array_merge(["transferType" => "SDDR"],$data,["limit" => $per_request_limit, "page" => $page])));            
            $found=array_merge($found,array_values($segment['sepaTransfer']));       
            if (is_null($max)){
                $max=ceil($segment['nbTransfers']/$per_request_limit);
            }
            $page++;
        }
        $this->log("INFO","Afone SEPA transfers search completed: found ".count($found)." afone items",$data);
        return collect($found);
	}
    
    protected function prepareMandateForRefund(pmbAfoneMandate $mandate){
        if (is_null($mandate->beneficiary_id)){
            $this->log("INFO","Starting SEPA beneficiary creation...");
            $process=$this->httpClient()->post("/rest/sepa/beneficiary/create", $this->withBaseData([
               "iban" => $payment->other_data['iban'],
               "label" => uniqid("PYM-REFUND-"),
               "holderName" => empty($payment->customer_id) ? uniqid("PYM-CUSTOMER-") : $payment->customer_id,               
            ]));            
            $mandate->beneficiary_id=Arr::get($process,"beneficiary.beneficiaryId");            
            $ret=Arr::get($process,"beneficiary.status","PENDING");                                    
            if ($ret=="ACTIVE"){
                $mandate->beneficiary_ready=true;
            }else{
                $mandate->beneficiary_ready=false;
                sleep(3);
                $data=$this->loadRemoteBeneficiaryList()->firstWhere("beneficiaryId",$mandate->beneficiary_id);    
                if (is_array($data) && isset($data['status'])){
                    $ret=$data['status'];
                    $mandate->beneficiary_ready=($ret=="ACTIVE");
                }
            }
            $this->log("INFO","SEPA beneficiary created with status '$ret'");
            $mandate->save();
            return $ret;
        }
        if ($mandate->beneficiary_ready){
            return "ACTIVE";
        }  
        return $this->evaluateBeneficiaryState($mandate, $this->loadRemoteBeneficiaryList());
    }
    
    protected function evaluateBeneficiaryState(pmbAfoneMandate $mandate, \Illuminate\Support\Collection $remote){
        if ($mandate->beneficiary_ready){
            return "ACTIVE";
        }     
        $data=$remote->firstWhere("beneficiaryId",$mandate->beneficiary_id);      
        if (empty($data)){
            return "ERROR";
        }
        $mandate->beneficiary_ready=(data['status']=="ACTIVE");
        $mandate->save();
        return $data['status'];                        
    }
    
    protected function loadRemoteBeneficiaryList(int $per_request_limit=100){
        $this->log("DEBUG","Starting afone SEPA beneficiary search...");
        $client=HttpClient::make($this->merchant_id, $this->cfg("base_uri"))
                ->withLogData(["pe" => $this->performer])
                ->validateResponsesWith([
                    "empty" => ["required","boolean"],
                    "nbBeneficiaries" => ["required", "integer"],
                    "beneficiary" => ["required","array"],
                    "beneficiary.*" => ["bail","nullable","array"],
                    "beneficiary.*.beneficiaryId" => ["bail","nullable","integer"],
                    "beneficiary.*.status" => ["bail","nullable","string","in:PENDING,REFUSED,ACTIVE,INACTIVE"],
                ]);
        $page=1;
        $max=null;
        $found=array();      
        while (is_null($max) || $page<=$max){
            $segment=$client->post("/rest/sepa/beneficiary/find", $this->withBaseData(["limit" => $per_request_limit, "page" => $page, "reverseOrder" => true]));            
            $found=array_merge($found,array_values($segment['beneficiary']));       
            if (is_null($max)){
                $max=ceil($segment['nbBeneficiaries']/$per_request_limit);
            }
            $page++;
        }
        $this->log("INFO","Afone SEPA beneficiary search completed: found ".count($found)." afone items",$data);
        return collect($found);
    }
    
    protected function onProcessRefund(pmbPayment $payment, float $amount, array $data = array()): bool {       
        $mandate_id=intval(Arr::get($payment->other_data,"mandate.1"));
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->confirmed()->find($mandate_id);
        if (is_null($mandate)){
            return $this->throwAnError("No SEPA mandate found!");
        }
        $status=$this->prepareMandateForRefund($mandate);
        $this->log("INFO","SEPA beneficiary status is $status");
        if ($status!="ACTIVE"){
            return false;
        }       
        $transaction_ref=$this->generateTransactionRef();
        $process=$this->httpClient()->post("/rest/sepa/sct/createSct",$this->withBaseData([
           "iban" => $payment->other_data['iban'],
           "beneficiaryId" => $mandate->beneficiary_id,
           "amount" => $amount,
           "transactionRef" => $transaction_ref,
           "label" => "Refund ".empty($payment->order_ref) ? "PYM-PAYMENT-".$payment->getKey() : $payment->order_ref
        ]));
        $this->registerARefund($payment, $amount, $transaction_ref, Arr::get($process,"sepaTransfer"));
        return true;
    }

     public function isRefundable(pmbPayment $payment): float {
        if ($payment->billed && $payment->confirmed && $payment->refundable_amount>0){
            $mandate_id=intval(Arr::get($payment->other_data,"mandate.1"));
            return pmbAfoneMandate::ofPerformers($this->performer)->confirmed()->where("id",$mandate_id)->where("beneficiary_ready",true)->exists() ? $payment->refundable_amount : 0;                        
        }        
        return 0;
    }

    public function supportsAliases(): bool {
        return true;
    }
     
    protected function generateCustomerForm(array $data){
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

    protected function onProcessAliasConfirm(pmbAlias $alias, array $data = array()): bool {
        $adata=$alias->adata;
        $md=$adata['mandate'];
        $mandate=pmbAfoneMandate::ofPerformers($this->performer)->confirmed()->find(intval($md[1]));
        if (is_null($mandate)){
            return $this->throwAnError("SEPA Mandate not found!","EMERGENCY",["al" => $alias]);
        }
        if ($alias->tracker!="DID:".$mandate->demande_signature_id){
            return $this->throwAnError("SEPA Mandate signature id mismatch!","EMERGENCY",["al" => $alias]);
        }        
        unset($adata['mandate']);
        $alias->adata=$adata;
        $alias->tracker=null;                
        return true;          
    }

    public function isAliasConfirmable(pmbAlias $alias): bool {
        return !$alias->confirmed;
    }

}
