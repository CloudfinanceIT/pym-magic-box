<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Illuminate\Support\Str;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

class Payment extends BaseOnModel implements Responsable {
	use \Mantonio84\pymMagicBox\Traits\withUserInteraction;
    
        protected $modelClassName = pmbPayment::class;        
        
	public static function ofCustomer(string $merchant_id, string $customer_id){
		$ret=collect();				
		$data=pmbPayment::merchant($merchant_id)->where("customer_id", $customer_id)->get();		
		foreach ($data as $rec){
				$ret[]=new static($merchant_id,$rec);
		}		
		return $ret;
	}	
        
	public $refundable=0;
        public $confirmable=false;       
        
	public function __construct(string $merchant_id, $ref){
		$this->acceptMerchantId($merchant_id);
		if ($ref instanceof pmbPayment){
                    $this->managed=$ref;                        
		}else{
                    $this->managed=$this->searchModelOrFail($ref);
		}
                $this->performer=$this->managed->performer;		                                
                $this->updateFlags();
                if ($this->needsUserInteraction()){
                    pmbLogger::info($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => "Created a 'Payment' class: user interaction required!"]);                
                }else{
                    pmbLogger::debug($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => "Created a 'Payment' class"]);                
                }                
	}
        
        
        protected function getPropAlias(){
            return $this->managed->alias;
        }
        
		protected function getPropResolverKey(){
			return $this->merchant_id."-".$this->managed->getKey();
		}
                  
        protected function getPropMandate(){
            if (isset($this->managed->other_data["mandate"])){
                $m=$this->managed->other_data["mandate"];
                $cls=Str::start($m[0],"\Mantonio84\pymMagicBox\Models\pmbAfoneMandate");
                return $cls::find($m[1]);
            }
        }
		
        protected function getPropRefunds(){
            return $this->managed->refunds;
        }
        
	public function confirm(array $other_data=[]){            
            $o=$this->managed->confirmed;
            $a=$this->buildEngine()->confirm($this->managed,$other_data)->confirmed;
            $this->updateFlags();
            return (!$o && $a);
	}
	
	public function refund($amount=null,array $other_data=[]){           
            $o=$this->refundable;
            $this->buildEngine()->refund($this->managed,$amount,$other_data);
            $this->updateFlags();
            return ($o-$this->refundable!=0);
	}	        
        	
        
        protected function searchModel($ref) {
            if (empty($ref)){
                    return null;
            }

            $q=pmbPayment::merchant($this->merchant_id);
            if (is_int($ref) || ctype_digit($ref)){
                    return $q->where("id",intval($ref))->first();
            }else if (is_string($ref) && !empty($ref)) {
                    return $q->where("order_ref",$ref)->first();
            }
            return null;		
        }
        
        protected function updateFlags(){            
            $this->refundable=$this->buildEngine()->isRefundable($this->managed);		
            $this->confirmable=$this->buildEngine()->isConfirmable($this->managed);          
        }

        

}