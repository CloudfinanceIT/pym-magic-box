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
    
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = NULL) {        
        parent::__construct($message, $code, $previous);        
        if (!empty($message)){
            $this->loggable(null, null, ["message" => class_basename($this)." ::: ".$this->getMessage(), "details" => $this->getTraceAsString()]);
        }
    }
    
    public function loggable($level=null, $merchant_id=null, $params=null){
        $this->plog_level=$this->plog_level ?? $level;
        $this->plog_merchant_id=$this->plog_merchant_id ?? $merchant_id;
        $this->plog_params=array_merge(is_array($this->plog_params) ? $this->plog_params : [], is_array($params) ? $params : []);
        return $this;
    }
    
    
    public function report() {
        if (!is_null($this->plog_merchant_id) && !is_null($this->plog_level)){
            pmbLogger::make()->write($this->plog_level, $this->plog_merchant_id, $this->plog_params);
        }        
    }
}