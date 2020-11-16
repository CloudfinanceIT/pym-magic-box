<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Engines\Base as AbstractEngine;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Exceptions\aliasNotFoundException;
use \Illuminate\Support\Str;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;
use \Mantonio84\pymMagicBox\Exceptions\invalidCurrencyCodeException;
use \Mantonio84\pymMagicBox\Exceptions\invalidAmountException;
use \Mantonio84\pymMagicBox\Exceptions\pymMagicBoxException;

class Engine extends Base implements pmbLoggable {
		
        protected static $currencies=["AED","AFN","ALL","AMD","ANG","AOA","ARS","AUD","AWG","AZN","BAM","BBD","BDT","BGN",
                               "BHD","BIF","BMD","BND","BOB","BOV","BRL","BSD","BTN","BWP","BYN","BZD","CAD","CDF",
                               "CHE","CHF","CHW","CLF","CLP","CNY","COP","COU","CRC","CUC","CUP","CVE","CZK","DJF",
                               "DKK","DOP","DZD","EGP","ERN","ETB","EUR","FJD","FKP","GBP","GEL","GHS","GIP","GMD",
                               "GNF","GTQ","GYD","HKD","HNL","HRK","HTG","HUF","IDR","ILS","INR","IQD","IRR","ISK",
                               "JMD","JOD","JPY","KES","KGS","KHR","KMF","KPW","KRW","KWD","KYD","KZT","LAK","LBP",
                               "LKR","LRD","LSL","LYD","MAD","MDL","MGA","MKD","MMK","MNT","MOP","MRU","MUR","MVR",
                               "MWK","MXN","MXV","MYR","MZN","NAD","NGN","NIO","NOK","NPR","NZD","OMR","PAB","PEN",
                               "PGK","PHP","PKR","PLN","PYG","QAR","RON","RSD","RUB","RWF","SAR","SBD","SCR","SDG",
                               "SEK","SGD","SHP","SLL","SOS","SRD","SSP","STN","SVC","SYP","SZL","THB","TJS","TMT",
                               "TND","TOP","TRY","TTD","TWD","TZS","UAH","UGX","USD","USN","UYI","UYU","UYW","UZS",
                               "VES","VND","VUV","WST","XAF","XCD","XOF","XPF","YER","ZAR","ZMW","ZWL"
                                ];
    
	public $supports_aliases=false;	
	protected $signatures;
	
	public function __construct(string $merchant_id, AbstractEngine $managed){		
		$this->acceptMerchantId($merchant_id);
		$this->managed=$managed;
                $this->performer=$this->managed->performer;		
		$this->supports_aliases=$managed->supportsAliases();                
		pmbLogger::debug($this->merchant_id, ["pe" => $managed->performer, "message" => "Created engine wrapper for '".class_basename($managed)."'"]);
	}
        
        public static function getValidCurrencyCodes(){
            return static::$currencies;
        }
        
        public static function isValidCurrencyCode(string $code){            
            return in_array(strtoupper($code),static::getValidCurrencyCodes());
        }
	
	public function getPmbLogData(): array{
		return [
			"performer_id" => $this->managed->performer->getKey(),
			"method_name" => $this->managed->performer->method->name,
		];
	}
        
        public function pay($amount, array $other_data=[], string $customer_id="", string $order_ref=""){ 		
                $amount=$this->parseAmountAndCurrencyCode($amount);
		pmbLogger::info($this->merchant_id,["amount" => $amount, "customer_id" => $customer_id, "order_ref" => $order_ref, "pe" => $this->managed->performer, "message" => "Pay request"]);		
		return $this->wrapPayResponse($this->managed->pay($amount[0],$amount[1],null,$customer_id,$order_ref,$other_data));
	}
	
	public function payWithAlias($amount, $alias_ref, array $other_data=[], string $order_ref=""){
                $amount=$this->parseAmountAndCurrencyCode($amount);
                if (is_scalar($alias_ref)){
                    $alias=Alias::find($this->merchant_id,$alias_ref)->toBase();                   
                }else if ($alias_ref instanceof pmbAlias){
                    $alias=$alias_ref;
                }else if ($alias_ref instanceof Alias){
                    $alias=$alias_ref->toBase();
                }
                
                if (is_null($alias)){
                    $a=is_scalar($alias_ref) ? "Alias '".$alias_ref."'" : "Requested alias";
                    throw aliasNotFoundException::make($a." not found on performer #".$this->performer->getKey()."!")->loggable("WARNING",$this->merchant_id,["pe" => $this->performer]);
                }
                    
                if (!$alias->performer->is($this->performer)){                    
                    throw aliasNotFoundException::make("Alias '".$alias->name."' not valid on performer #".$this->performer->getKey()."!")->loggable("WARNING",$this->merchant_id,["pe" => $this->performer, "al" => $alias]);
                    $alias=null;
                }
            
		pmbLogger::info($this->merchant_id,["amount" => $amount, "al" => $alias, "order_ref" => $order_ref, "pe" => $this->managed->performer, "message" => "Pay request with alias #".$alias->getKey()." ".$alias->name]);		
		return $this->wrapPayResponse($this->managed->pay($amount[0],$amount[1],$alias,$alias->customer_id,$order_ref,$other_data));
	}
	
	public function createAnAlias(array $data, string $name, string $customer_id="", $expires_at=null){
		pmbLogger::debug($this->merchant_id,["message" => "createAnAlias request", "per" => $this->managed->performer]);
		return new Alias($this->merchant_id,$this->managed->aliasCreate($data, $name, $customer_id, $expires_at));		
	}
	
	public function toBase(){
		return $this->managed;
	}
	
	public function __call($method, $arguments){
		return $this->run($method, $arguments);
	}
	
	public function enumerateAbilities(){
		$this->discover();
		return array_keys($this->signatures);
	}
	
	public function run(string $method, $arguments=array()){		
		if ($this->canRun($method)){
			$params=array();
			foreach ($this->signatures[$method] as $rp){
				$params[]=$this->extractArgument($arguments, $rp, $method);
			}
			try {
				$ret=call_user_func_array([$this->managed,$method],$params);
			}catch (\Exception $e) {
				throw pmbLogger::make()->reportAnException($e,"EMERGENCY",$this->merchant_id,array_merge($arguments,["performer" => $this->managed->performer]));	                                
			}
			if ($ret instanceof pmbAlias){
				return new Alias($this->merchant_id, $ret);
			}
			if ($ret instanceof pmbPayment){
				return new Payment($this->merchant_id, $ret);
			}
			return $ret;
		}
	}
	
	public function canRun(string $method){
		$this->discover();
		return array_key_exists($method,$this->signatures);
	}
	
        protected function parseAmountAndCurrencyCode($w){
            $ret=null;
            $defaultCurrencyCode=config("pymMagicBox.default_currency_code","EUR");
            if (!static::isValidCurrencyCode($defaultCurrencyCode)){
                throw invalidCurrencyCodeException::make("Invalid default currency code '$defaultCurrencyCode'!")->loggable("EMERGENCY",$this->merchant_id,["pe" => $this->performer]);
                return null;
            }
            if (is_float($w) || is_int($w) || ctype_digit($w)){
                $ret=array(floatval($w), $defaultCurrencyCode);
            }else if (is_string($w)){
                $ret=explode(" ",$w,2);
            }else if (is_array($w)){
                $ret=$w;
            }
            $ret[1]=strtoupper($ret[1]);
            if (!is_array($ret)){
                throw invalidAmountException::make("Invalid amount given '$w'!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
                return null;
            }
            if (count($ret)!=2){
                throw invalidAmountException::make("Invalid amount given '$w'!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
                return null;
            }
            if (!is_float($ret[0]) && !is_int($ret[0]) && !ctype_digit($ret[0])){
                throw invalidAmountException::make("Invalid amount given '$w'!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
                return null;
            }
            if ($ret[0]<=0){
                throw invalidAmountException::make("Invalid amount given '$w'!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
                return null;
            }
            if (!static::isValidCurrencyCode($ret[1])){
                throw invalidCurrencyCodeException::make("Invalid currency code given '".$ret[1]."'!")->loggable("CRITICAL",$this->merchant_id,["pe" => $this->performer]);
                return null;
            }
            $ret[0]=floatval($ret[0]);
            return $ret;
        }
	
	protected function extractArgument(array &$arguments, $rp, $method){
		$n=$rp->getName();
		if (empty($arguments)){
			if ($rp->isOptional()){
				$v=$rp->getDefaultValue();
			}else{
				throw new \RuntimeException("Missing parameter '$n' for ".class_basename($this->managed).":::".$method."()");
			}
		}else{

			if (static::array_is_associative($arguments)){
				if (array_key_exists($n,$arguments)){
					$v=$arguments[$n];
					unset($arguments[$n]);
				}else{
					if ($rp->isOptional()){
						$v=$rp->getDefaultValue();
					}else{
						throw new \RuntimeException("Missing parameter '$n' for ".class_basename($this->managed).":::".$method."()");
					}
				}
			}else{
				$v=array_shift($arguments);
			}
		}
						
		return $v;
	}
		
	
	protected function discover(){
		if (is_null($this->signatures)){
			$this->signatures=array();
			$reflector=new \ReflectionClass($this->managed);
			$methods=$reflector->getMethods();
			foreach ($methods as $mt){
				if ($mt->isPublic() && !$mt->isStatic() && !$mt->isConstructor() && !$mt->isDestructor() && !$mt->isAbstract()){
					$n=$mt->getName();
					if (!in_array($n,["isConfirmable","isRefundable","supportsAliases","pay","confirm","refund","aliasCreate","aliasDelete","getConfigurationData"])){
						$this->signatures[$n]=$mt->getParameters();
					}
				}
			}
		}
	}
       
	
         protected function wrapPayResponse($ret){
            if (is_array($ret) && count($ret)==2){
                return new Payment($this->merchant_id, $ret[0], $ret[1]);                
            }
            return $ret;
	}

        
        protected static function array_is_associative(array $arr) {
		$k = array_keys($arr);
		$fk = reset($k);
		if (!ctype_digit($fk) && !is_int($fk))
			return true;
		$fk = intval($fk);                
		return (range($fk, count($arr) - 1) != $k);
	}
        
        
}