<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use Illuminate\Contracts\Support\Responsable;

class Alias extends BaseOnModel implements Responsable {
	use \Mantonio84\pymMagicBox\Traits\withUserInteraction;
        
        protected $modelClassName = pmbAlias::class;
        public $is_confirmable=false;    
        
	public static function ofCustomer(string $merchant_id, string $customer_id){
		$ret=collect();				
		$data=pmbAlias::merchant($merchant_id)->notExpired()->where("customer_id", $customer_id)->get();		
		foreach ($data as $rec){
				$ret[]=new static($merchant_id,$rec);
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
            return $this->buildEngine()->aliasDelete($this->managed);
	}
	
        public function charge($amount, array $other_data=[], string $order_ref=""){
            return $this->getPropEngine()->payWithAlias($amount,$this->managed, $other_data, $order_ref);
        }
        
        public function confirm(array $other_data=[]){            
            $o=$this->managed->confirmed;
            $a=$this->buildEngine()->aliasConfirm($this->managed,$other_data)->confirmed;
            $this->updateFlags();
            return (!$o && $a);
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
        
        protected function updateFlags(){
            $this->is_confirmable=$this->buildEngine()->isAliasConfirmable($this->managed);        
        }

}