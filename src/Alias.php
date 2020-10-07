<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Models\pmbLog;

class Alias extends BaseOnModel {
		
			
	public function __construct(string $merchant_id, $ref){
		$this->acceptMerchantId($merchant_id);
		if ($ref instanceof pmbAlias){
			$this->managed=$ref;
		}else{
			$this->managed=$this->findAliasOrFail($ref);
		}		
                
		pmbLog::write("DEBUG", $this->merchant_id, ["re" => $ref, "al" => $this->managed, "pe" => $this->managed->performer, "message" => "Created a 'Alias' class"]);
	}
	
	public function engine(){
		return $this->getEngine($this->managed->performer);
	}
	
	public function method(){
		return $this->managed->performer->method;
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
}