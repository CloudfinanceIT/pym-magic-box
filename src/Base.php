<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Exceptions\invalidMerchantException;
use \Mantonio84\pymMagicBox\Exceptions\paymentNotFoundException;
use \Mantonio84\pymMagicBox\Exceptions\methodNotFoundException;
use \Mantonio84\pymMagicBox\Exceptions\noPerformerConfiguredException;
use \Mantonio84\pymMagicBox\Exceptions\aliasNotFoundException;

abstract class Base  {
	
	protected $merchant_id;	
	protected $performers=null;	
		
	protected static function isUuid($value)
    {
        if (! is_string($value)) {
            return false;
        }

        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }
	
	
	public function getMerchant(){
		return $this->merchant_id;
	}
	
	protected function acceptMerchantId(string $merchant_id){
		if (!static::isUuid($merchant_id)){
			throw new invalidMerchantException('Invalid UUID given!');
		}
		$this->merchant_id=$merchant_id;	
	}
	
	protected function getEngine(pmbPerformer $performer){
		return $performer->getEngine($this->getPerformersIds());
	}
	
	protected function findPayment($ref){
		if (empty($ref)){
			return null;
		}
		$l=intval(config("pymMagicBox.bb_code.len",5));
		if ($l<3 || $l>16) $l=5;
		$pattern='/^[ABCDEFGHJLMNPQRTUVWXYZ2346789]{'.$l.'}+$/';
		
		$q=pmbPayment::ofPerformers($this->getPerformersIds());
		if (is_int($ref) || ctype_digit($ref)){
			return $q->where("id",intval($ref))->first();
		}else if (preg_match($pattern,$ref)>0){
			return $q->where("bb_code",$ref)->first();
		}else if (is_string($ref) && !empty($ref)) {
			return $q->where("order_ref",$ref)->first();
		}
		return null;		
	}
	
	protected function findPaymentOrFail($ref){
		$ret=$this->findPayment($ref);
		if (is_null($ret)){
			throw new paymentNotFoundException("Payment '$ref' not found!");
		}
		return $ret;
	}
	
	protected function getPerformers(){
		if (is_null($this->performers)){
			$this->performers=$this->performersLoaderQuery($this->merchant_id)->get();
			if ($this->performers->isEmpty()){
				throw new noPerformerConfiguredException("No performer configured, merchant '".$this->merchant_id."'!");
			}
		}
		return $this->performers;
	}
	
	protected function getPerformersIds(){
		return $this->getPerformers()->pluck("id")->all();
	}
	
	protected function findMethod($ref){
		$ret=$this->findMethodPerformer($ref);
		return ($ret instanceof pmbPerformer) ? $ret->method : null;
	}
	
	protected function findMethodOrFail($ref){
		$ret=$this->findMethod($ref);
		if (is_null($ret)){
			throw new methodNotFoundException("Method '$ref' not found for merchant '".$this->merchant_id."'!");
		}
		return $ret;
	}
	
	protected function findMethodPerformer($ref){
		if (empty($ref)){
			return null;
		}
		$a=null;
		if (is_int($ref) || ctype_digit($ref)){
			$a=$this->getPerformers()->firstWhere("pmb_method_id",intval($ref));			
		}else if (!ctype_digit($ref) && strlen($ref)<=80){
			$a=$this->getPerformers()->firstWhere("method.name",$ref);	
		}
		return $a;
	}
	
	protected function findMethodPerformerOrFail($ref){
		$ret=$this->findMethodPerformer($ref);
		if (is_null($ret)){
			throw new methodNotFoundException("Method '$ref' not found for merchant '".$this->merchant_id."'!");
		}
		return $ret;
	}
	
	protected function findAlias($ref){
		$q=pmbAlias::ofPerformers($this->getPerformersIds())->notExpired();
		if (is_int($ref) || ctype_digit($ref)){
			return $q->where("id",intval($ref))->first();
		}else if (is_string($ref) && !empty($ref)){
			return $q->where("name",$ref)->first();
		}
		return null;
	}
	
	protected function findAliasOrFail($ref){
		$ret=$this->findAlias($ref);
		if (is_null($ret)){
			throw new aliasNotFoundException("Method '$ref' not found for merchant '".$this->merchant_id."'!");
		}
		return $ret;
	}
	
	protected function performersLoaderQuery($merchant_id){
		return pmbPerformer::with("method")->merchant($merchant_id)->enabled();
	}
	
	protected function wrapPaymentModel($ret){
		if ($ret instanceof pmbPayment){
			return new Payment($this->merchant_id, $ret);
		}
		return null;
	}
}