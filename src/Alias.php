<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;

class Alias extends BaseOnModel {
	
    protected $modelClassName = pmbAlias::class;
	
	public static function findMany(string $merchant_id, string $customer_id){
		$q=static::createSearchQuery($merchant_id,$customer_id);
		$ret=collect();
		if (!empty($q)){
			$data=$q->get();
			foreach ($data as $rec){
				$ret[]=new static($merchant_id,$rec);
			}
		} 
		return $ret;
	}
	
			
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
            $q=static::createSearchQuery($this->merchant_id,$ref);
			return is_null($q) ? null : $q->first();            
        }
		
		protected static function createSearchQuery($merchant_id, $ref){
			$q=pmbAlias::merchant($merchant_id)->notExpired();
            if (is_int($ref) || ctype_digit($ref)){
                return $q->where("id",intval($ref));
            }else if (is_string($ref) && !empty($ref)){
                return $q->where("name",$ref);
            }
			return null;
		}

}