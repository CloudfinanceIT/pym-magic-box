<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;

class Alias extends BaseOnModel {
	
        protected $modelClassName = pmbAlias::class;
			
	public function __construct(string $merchant_id, $ref){
		$this->acceptMerchantId($merchant_id);
		if ($ref instanceof pmbAlias){
			$this->managed=$ref;
		}else{
			$this->managed=$this->searchModelOrFail($ref);
		}	
                $this->performer=$this->managed->performer;
		pmbLogger::debug($this->merchant_id, ["re" => $ref, "al" => $this->managed, "pe" => $this->performer, "message" => "Created a 'Alias' class"]);
	}
	
	public function engine(){
            return $this->performer->getEngine();
	}
	
	public function method(){
            return $this->performer->method;
	}
	
	public function delete(){
		return $this->engine()->aliasDelete($this->managed);
	}
	
	protected function isReadableAttribute(string $name) : bool{
		return true;
	}
	
	protected function isWriteableAttribute(string $name, $value) : bool{
		return ($name!="performer_id" && $name!="performer" && $name!="adata");
	}
   
        protected function searchModel($ref) {
            $q=pmbAlias::merchant($this->merchant_id)->notExpired();
            if (is_int($ref) || ctype_digit($ref)){
                return $q->where("id",intval($ref))->first();
            }else if (is_string($ref) && !empty($ref)){
                return $q->where("name",$ref)->first();
            }
            return null;
        }

}