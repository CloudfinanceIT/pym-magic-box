<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;

class Payment extends BaseOnModel {
	
        protected $modelClassName = pmbPayment::class;
    
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
		$this->is_refundable=$this->engine()->isRefundable($this->managed);		
                $this->is_confirmable=$this->engine()->isConfirmable($this->managed);
                $this->interactive=$interactive;               
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
                pmbLogger::debug($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => "Payment user interaction readed"]);                
                $this->ir=true;
            }
            return $this->interactive;
        }
			
	public function engine(){
		return $this->performer->getEngine();
	}
	
	public function method(){
		return $this->performer->method;
	}
        
        public function alias(){
            return $this->managed->alias;
        }
			
	public function confirm(array $other_data=[]){
            if (!$this->is_confirmable){
                return $this;
            }
            return $this->wrapPaymentModel($this->engine()->confirm($this->managed,$other_data));
	}
	
	public function refund(array $other_data=[]){
            if (!$this->is_refundable){
                return $this;
            }
            return  $this->wrapPaymentModel($this->engine()->refund($this->managed,$other_data));
	}
	
	protected function isReadableAttribute(string $name) : bool{
		return true;
	}
	
	protected function isWriteableAttribute(string $name, $value) : bool{
		return false;
	}
        
        protected function wrapPaymentModel($ret){
            if ($ret instanceof pmbPayment){
                $this->managed=$ret;
                $this->performer=$this->managed->performer;
            }
            return $this;
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

}