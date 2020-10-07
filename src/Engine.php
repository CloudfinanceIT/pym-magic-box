<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Engines\Base as AbstractEngine;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Models\pmbLog;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Illuminate\Support\Str;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;

class Engine extends Base implements pmbLoggable{
	
	public $is_refundable=false;
	public $supports_aliases=false;
	protected $managed;	
	protected $signatures;
	
	public function __construct(string $merchant_id, AbstractEngine $managed){		
		$this->acceptMerchantId($merchant_id);
		$this->managed=$managed;
		$this->is_refundable=$managed->isRefundable();
		$this->supports_aliases=$managed->supportsAliases();
		pmbLog::write("DEBUG", $this->merchant_id, ["message" => "Created engine '".get_class($this)."'"]);
	}
	
	public function getPmbLogData(): array{
		return [
			"performer_id" => $this->managed->performer->getKey(),
			"method_name" => $this->managed->performer->method->name,
		];
	}
	
	public function createAnAlias(array $data, string $name, string $customer_id="", $expires_at=null){
		pmbLog::write("DEBUG",$this->merchant_id,["message" => "createAnAlias request", "per" => $this->managed->performer]);
		return new Alias($this->managed->aliasCreate($data, $name, $customer_id, $expires_at));		
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
				pmbLog::write("EMERGENCY",$this->merchant_id,array_merge($params,["per" => $this->managed->performer, "ex" => $e]));
				throw $e;
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
	
	public function findSomethingOfThisMagicBox($n,$v=null,bool $fail=true){
		if (is_array($n)){
			$ret=array();
			foreach ($n as $k => $vv){
				$ret[$k]=$this->findSomethingOfThisMagicBox($k,$vv,$fail);
			}
			return $ret;
		}
		$finderFun="find".ucfirst(Str::camel($n));
		if ($fail){
			$finderFun.="OrFail";
		}
		if (method_exists($this,$finderFun)){
			return call_user_func([$this,$finderFun],$v) ?? $v;
		}
		return $v;
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
			foreach ($method as $mt){
				if ($mt->isPublic() && !$mt->isStatic() && !$mt->isConstructor() && !$mt->isDestructor() && !$mt->isAbstract()){
					$n=$mt->getName();
					if (!in_array($n,["isRefundable","supportsAliases","pay","confirm","refund","aliasCreate","aliasDelete"])){
						$this->signatures[$n]=$mt->getParameters();
					}
				}
			}
		}
	}
	
	protected static function array_is_associative(array $arr) {
		$k = array_keys($arr);
		$fk = reset($k);
		if (!ctype_digit($fk))
			return true;
		$fk = intval($fk);
		return (range($fk, count($arr) - 1) != $k);
	}
}