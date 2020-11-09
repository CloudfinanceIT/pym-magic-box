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
		if (!isset($data['3ds'])){
			return false;
		}
		if (!is_array($data['3ds'])){
			return false;
		}
		$rp=$data['3ds'];
		$rp['transactionRef']=$payment->transaction_ref;

        $this->httpClient(["py" => $payment])->post("rest/payment/end3dsDebit", $this->withBaseData($rp));        
        return true;
   }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array()): processPaymentResponse {
		$customer=$this->generateCustomerForm($payment, $data);
		if (empty($customer)){
			return $this->throwAnError("No customer data given!");
        }   
		$browser=$this->generateCustomerForm($data);
		if (empty($customer)){
			return $this->throwAnError("No browser data given!");
        }   
		$tracker=Str::random(8);
		$confirm_3ds_data=["v" => 2, "track" => $tracker];
		
        $pd=$this->withBaseData([                
            "amount" => round($payment->amount*100),
            "transactionRef" => $this->generateTransactionRef(),
            "customer" => json_encode($customer),
			"browser" => json_encode($browser),
            "force3ds" => $this->cfg("force3ds",0),
			"ip" => request()->ip(),
			"redirection3DSV2Url" => $this->getListenURL("confirm-3ds",$confirm_3ds_data)
        ]);            
		$cardholder=$this->extractDataForm($data,"cardholder",["chAccDate","chAccChange","chAccPwChange","shipAddressUsage","txnActivityDay","txnActivityYear","provisionAttemptsDay","nbPurchaseAccount","suspiciousAccActivity","shipNameIndicator"]);
		if (!empty($cardholder)){
			$pd['cardholderAccount']=json_encode($cardholder);
		}
		$caddy=$this->extractDataForm($data,"caddy",["shipIndicator","deliveryTimeframe","deliveryEmailAddress","reorderItemsInd","preOrderPurchaseInd","preOrderDate","giftCardAmount","nbCarteCadeau"]);
		if (!empty($caddy)){
			$pd['caddy']=json_encode($caddy);
		}
        if (empty($alias_data)){
            if (!isset($data['tokenRef']) || empty($data['tokenRef'])){
                return $this->throwAnError("Invalid tokenRef!");
            }            
            $pd["tokenRef"] = $data['tokenRef'];            
            $process=$this->httpClient(["py" => $payment])->post("rest/payment/tokenDebit",$pd);            
        }else{
            if (!isset($alias_data->adata['aliasRef']) || empty($alias_data->adata['aliasRef'])){
                return $this->throwAnError("Invalid aliasRef!");
            }  
            if (!isset($data['cvv']) || empty($data['cvv']) || !ctype_digit($dat['cvv'])){
                return $this->throwAnError("aliasDebit requires cvv code!");
            }  
            $pd["alias"] = $alias_data->adata['aliasRef'];            
            $pd['cvv'] = $data['cvv'];
            $process=$this->httpClient(["py" => $payment])->post("/rest/payment/aliasDebit",$pd);            
        }        
        if (Arr::get($process,"actionCode")=="AUTH_3DS_REQUISE"){
			$isVersioneOne=(Arr::get($process,"transaction.verifyEnrollment3dsV1",false) === true);
            $this->log("INFO", "Payment ".$pd['transactionRef']." needs 3DSv". ($isVersioneOne ? "1" : "2") . " confirmation");			
			$vd=[
					"method" => "post",
					"action" => Arr::get($process,"transaction.verifyEnrollment3dsActionUrl"),
			];			
			if ($isVersioneOne){
				$confirm_3ds_data['v']=1;				
				$vd['fields'] = ["TermUrl" => $this->getListenURL("confirm-3ds",$confirm_3ds_data), "MD" => Arr::get($process,"transaction.verifyEnrollment3dsMd"), "PaReq" => Arr::get($process,"transaction.verifyEnrollment3dsPareq")];				
			}else{				
				$vd['fields'] = ["creq" => Arr::get($process,"transaction.verifyEnrollment3dsCreq")];
			}
            return processPaymentResponse::make([
                "billed" => true,
                "confirmed" => false,
                "transaction_ref" => $pd['transactionRef'],
                "tracker" => $tracker,
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
		$this->log("DEBUG","3DS confirmation listen: begin",json_encode($request));		
		abort_unless($this->listenConfirm3dsValidate($request,[
			"v" => ["required","integer","in:1,2"],			
			"track" => ["required","string","size:8"]
		]),400,"Missing data (01).");
		
		if ($request['v']==1){		
			return $this->run3dsConfirm(1, $request['track'], $request, [
				"md" => ["bail","nullable","required_without:pares","string"],			
				"pares" => ["bail","nullable","required_without:md","string"],			
			]);
		}else{
			return $this->run3dsConfirm(2, $request['track'], $request, [
				"cres" => ["required","string"],							
			]);
		}				              
    }
	
	protected function run3dsConfirm(int $version, string $track, array $request, array $rules){
		abort_unless($this->listenConfirm3dsValidate($request,$rules),400,"Missing data (02).");
		$payment=$this->paymentFinderQuery()->billed()->confirmed(false)->refunded(false)->where("tracker",$track)->firstOrFail();
		if (is_null($payment)){
			$this->log("ALERT","3DS confirmation of '".$track."' failed: payment not found!");
			return response("Payment tracking failed!",503);
		}	
		$this->log("INFO","3DS confirmation of '".$track."' ready to start");
		$keys=array_keys($rules);
		$a=array_merge(array_fill_keys($keys,null),Arr::only($request,$keys));
		$py=$this->confirm($payment,["3ds" => $a]);
		if ($py->confirmed){            
			return redirect()->route($this->config["after-3ds-route"],["payment" => $py->getKey(), "merchant" => $this->merchant_id]);
		}else{
			return response("3ds confirmation failed!",503);
		}
	}
	
	protected function listenConfirm3dsValidate(array $request, array $rules){
		$v=Validator::make($request,$rules);
		if ($v->fails()){
			$this->log("CRITICAL","3DS confirmation listen: invalid input data!",\Arr::flatten($v->errors()->getMessages()));
			return false;
		}else{
			return true;
		}
	}
    
    public function isConfirmable(pmbPayment $payment): bool{
        return $payment->billed && !$payment->confirmed && !$payment->refunded && !empty($payment->tracker);
    }
    
    protected function onProcessRefund(pmbPayment $payment, array $data = array()): bool {
        $this->httpClient(["py" => $payment])->post("/rest/payment/refund",$this->withBaseData([
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
        $ret=$this->extractDataForm($data,"customer",["customerRef","firstName","lastName","email","road","zipCode","city","country","phone","meetingDate"]);
        if (!isset($ret['customerRef']) && !empty($payment->customer_id)){
            $ret['customerRef']=$payment->customer_id;
        }
        return empty($ret) ? null : $ret;
    
	}
	
	protected function generateBrowserForm(array $data){
		return $this->extractDataForm($data,"browser",["browserAcceptHeader","browserIP","browserJavaEnabled","browserLanguage","browserColorDepth","browserScreenHeight","browserScreenWidth","browserTZ","browserUserAgent","challengeWindowSize","browserJavascriptEnabled"]);
	}
	
	protected function extractDataForm(array $data, string $root_key, array $keys){
		$ret=array();
        if (isset($data[$root_key]) && is_array($data[$root_key])){
            $ret=array_filter(Arr::only($data[$root_key],$keys));
        }
		return $ret;
	}
    
    protected function getEndPointURL(string $uri=""){        
        return $this->httpClient()->getEndPointURL($uri);
    }
    
    protected function httpClient(array $log_data = []){        
        if (is_null($this->httpclient)){
            $this->httpclient=HttpClient::make($this->merchant_id, $this->cfg("base_uri"))
                    ->withLogData(["pe" => $this->performer])
                    ->validateResponsesWith(function ($rp){                        
                        return (intval(Arr::get($rp,"ok",0))==1);
                    });
        }
        return $this->httpclient->withLogData($log_data);
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
}
