<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Illuminate\Support\Str;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

class Payment extends BaseOnModel implements Responsable {
	
        protected $modelClassName = pmbPayment::class;
		
	public static function ofCustomer(string $merchant_id, string $customer_id){
		$ret=collect();				
		$data=pmbPayment::merchant($merchant_id)->where("customer_id", $customer_id)->get();		
		foreach ($data as $rec){
				$ret[]=new static($merchant_id,$rec);
		}		
		return $ret;
	}
	
        
	public $is_refundable=false;
        public $is_confirmable=false;
        
        protected $interactive;
        protected $ir=false;
       
        
	public function __construct(string $merchant_id, $ref, $interactive=null){
		$this->acceptMerchantId($merchant_id);
		if ($ref instanceof pmbPayment){
                    $this->managed=$ref;                        
		}else{
                    $this->managed=$this->searchModelOrFail($ref);
		}
                $this->performer=$this->managed->performer;		      
                $this->interactive=$interactive;               
                $this->updateFlags();
                if ($this->needsUserInteraction()){
                    pmbLogger::info($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => "Created a 'Payment' class: user interaction required!"]);                
                }else{
                    pmbLogger::debug($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => "Created a 'Payment' class"]);                
                }                
	}
        
        public function needsUserInteraction(){
            return !is_null($this->interactive);
        }
        
        public function getUserInteraction(){
            if (!$this->needsUserInteraction()){
                return null;
            }
            if (!$this->ir){
                pmbLogger::debug($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => "Payment user interaction readed first time"]);                
                $this->ir=true;
            }
            return $this->interactive;
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
			
	public function confirm(array $other_data=[]){            
            $o=$this->managed->confirmed;
            $a=$this->buildEngine()->confirm($this->managed,$other_data)->confirmed;
            $this->updateFlags();
            return (!$o && $a);
	}
	
	public function refund(array $other_data=[]){
            $o=$this->managed->refunded;
            $a=$this->buildEngine()->refund($this->managed,$other_data)->refunded;
            $this->updateFlags();
            return (!$o && $a);
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
            $this->is_refundable=$this->buildEngine()->isRefundable($this->managed);		
            $this->is_confirmable=$this->buildEngine()->isConfirmable($this->managed);          
        }

        public function toResponse($request): \Symfony\Component\HttpFoundation\Response {
            if ($this->needsUserInteraction()){
                return $this->getUserInteraction();
            }else{
                return new JsonResponse($this->managed);
            }
        }

}