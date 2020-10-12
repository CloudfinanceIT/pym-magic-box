<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Engines\Base as AbstractEngine;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use Mantonio84\pymMagicBox\Exceptions\aliasNotFoundException;
use \Illuminate\Support\Str;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;


class Engine extends Base implements pmbLoggable {
		
	public $supports_aliases=false;	
	protected $signatures;
	
	public function __construct(string $merchant_id, AbstractEngine $managed){		
		$this->acceptMerchantId($merchant_id);
		$this->managed=$managed;
                $this->performer=$this->managed->performer;		
		$this->supports_aliases=$managed->supportsAliases();                
		pmbLogger::debug($this->merchant_id, ["pe" => $managed->performer, "message" => "Created engine wrapper for '".class_basename($managed)."'"]);
	}
	
	public function getPmbLogData(): array{
		return [
			"performer_id" => $this->managed->performer->getKey(),
			"method_name" => $this->managed->performer->method->name,
		];
	}
        
        public function pay(float $amount, array $other_data=[], string $customer_id="", string $order_ref=""){ 		
		pmbLogger::debug($this->merchant_id,["amount" => $amount, "customer_id" => $customer_id, "order_ref" => $order_ref, "pe" => $this->managed->performer, "message" => "Pay request"]);		
		return $this->wrapPayResponse($this->managed->pay($amount,null,$customer_id,$order_ref,$other_data));
	}
	
	public function payWithAlias(float $amount, $alias_ref, array $other_data=[], string $order_ref=""){
                if (is_scalar($alias_ref)){
                    $alias=Alias::find($this->merchant_id,$alias_ref)->toBase();                   
                }else if ($alias_ref instanceof pmbAlias){
                    $alias=$alias_ref;
                }else if ($alias_ref instanceof Alias){
                    $alias=$alias_ref->toBase();
                }
                
                if (is_null($alias)){
                    $a=is_scalar($alias_ref) ? "Alias '".$alias_ref."'" : "Requested alias";
                    throw new aliasNotFoundException($a." not found on performer #".$this->performer->getKey()."!");
                }
                    
                if (!$alias->performer->is($this->performer)){                    
                    throw new aliasNotFoundException("Alias '".$alias->name."' not valid on performer #".$this->performer->getKey()."!");
                    $alias=null;
                }
            
		pmbLogger::debug($this->merchant_id,["amount" => $amount, "al" => $alias, "order_ref" => $order_ref, "pe" => $this->managed->performer, "message" => "Pay request with alias #".$alias->getKey()." ".$alias->name]);		
		return $this->wrapPayResponse($this->managed->pay($amount,$alias,$alias->customer_id,$order_ref,$other_data));
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
                                if (!($e instanceof pymMagicBoxLoggedException)){       
                                    pmbLogger::emergency($this->merchant_id,array_merge($params,["per" => $this->managed->performer, "ex" => $e]));
                                }				
				return null;
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
						
		return $this->findSomethingOfThisMagicBox($n,$v);
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