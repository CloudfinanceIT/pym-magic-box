<?php
namespace Mantonio84\pymMagicBox\Classes;
use \Illuminate\Contracts\Support\Arrayable;

class processPaymentResponse implements Arrayable {
	public $billed=false;
	public $confirmed=false;	
	public $other_data=array();
	public $transaction_ref=null;       
        public $tracker=null;
        protected $interactive=null;
	
        public static function make($data=null){
            return new static($data);
        }
       
	public function __construct($data=null){
		if (is_array($data)){
			$this->fill($data);
		}
	}
	
	public function fill(array $data){
		if (array_key_exists("billed",$data)){
			$this->billed=!empty($data['billed']);
		}
		if (array_key_exists("confirmed",$data)){
			$this->confirmed=!empty($data['confirmed']);
		}		
		if (array_key_exists("other_data",$data)){
			$this->other_data=is_array($data['other_data']) ? $data['other_data'] : [];
		}
		if (array_key_exists("transaction_ref",$data)){
			$this->transaction_ref=$data['transaction_ref'];
		}
                if (array_key_exists("tracker",$data)){
			$this->tracker=$data['tracker'];
		}
		return $this;
	}

	public function toArray(){
		return array("billed" => $this->billed, "confirmed" => $this->confirmed, "other_data" => $this->other_data, "transaction_ref" => $this->transaction_ref, "tracker" => $this->tracker);
	}
 	
        public function isInteractive(){
            return is_null($this->interactive);
        }
        
        public function needsUserInteraction($w){
            if (is_null($w) || $w === false){
                $this->interactive=null;
            }else{
                $this->interactive=($w instanceof \Illuminate\Http\Response) ? $w : response($w);
            }
            return $this;
        }
        
        public function getUserInteraction(){
            return $this->interactive;
        }
	
}
