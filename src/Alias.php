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
		pmbLogger::debug($this->merchant_id, ["re" => $this->managed, "al" => $this->managed, "pe" => $this->performer, "message" => "Created a 'Alias' class"]);
	}	
	
	public function delete(){
            return $this->engine()->aliasDelete($this->managed);
	}
	
        public function charge($amount, array $other_data=[], string $order_ref=""){
            return $this->getPropEngine()->payWithAlias($amount,$this->managed, $other_data, $order_ref);
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