<?php
namespace Mantonio84\pymMagicBox\Exceptions;
use Mantonio84\pymMagicBox\Logger as pmbLogger;

class pymMagicBoxException extends \Exception {
    
    public static function make(...$args){
        return new static(...$args);
    }
   
    protected $plog_merchant_id=null;
    protected $plog_level=null;
    protected $plog_params=[];
	protected $logged="";
    protected $last_logged;
	
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = NULL) {        
        parent::__construct($message, $code, $previous);        
        if (!empty($message)){
			$this->plog_params=[
				"message" => class_basename($this)." ::: ".$this->getMessage(),
				"details" => $this->getTraceAsString()
			];            
        }
    }
    
    public function loggable($level=null, $merchant_id=null, $params=null){		
        $this->plog_level=$this->plog_level ?? $level;
        $this->plog_merchant_id=$this->plog_merchant_id ?? $merchant_id;
        $this->plog_params=array_merge(is_array($this->plog_params) ? $this->plog_params : [], is_array($params) ? $params : []);		
		$this->writeToPymLog();		
        return $this;
    }
    
    
    public function report() {
        $this->writeToPymLog();
    }
	
	public function getLastLoggedEvent(){
		$this->writeToPymLog();
		return $this->last_logged;
	}
	
	protected function writeToPymLog(bool $forced=false){
		$p=$this->logDataFingerpint();
		if (!empty($p) && ($this->logged!=$p || $forced)){
            $this->last_logged=pmbLogger::make()->write($this->plog_level, $this->plog_merchant_id, $this->plog_params);
			$this->logged=$p;
        }        
	}
	
	protected function isLoggable(){
		return (!is_null($this->plog_merchant_id) && !is_null($this->plog_level) && isset($this->plog_params['message']));
	}
	
	protected function logDataFingerpint(){
		if (!$this->isLoggable()){
			return "";
		}
		return md5(json_encode([$this->plog_merchant_id,$this->plog_level,$this->plog_params]));
	}
}