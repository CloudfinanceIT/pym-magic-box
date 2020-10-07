<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Exceptions\invalidMerchantException;


abstract class Base {


    protected $merchant_id;
    protected $performer=null;	    
    protected $managed=null;
    
    protected static function isUuid($value) {
        if (! is_string($value)) {
            return false;
        }
        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }	
	
    public function getMerchant(){
	return $this->merchant_id;
    }   	
    
    public function getPerformer(){
        return $this->performer;
    }
    
    protected function acceptMerchantId(string $merchant_id){
	if (!static::isUuid($merchant_id)){
            throw new invalidMerchantException('Invalid UUID given!');
	}
	$this->merchant_id=$merchant_id;	
    }   
    
    
}
