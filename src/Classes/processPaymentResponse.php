<?php
namespace Mantonio84\pymMagicBox\Classes;
use \Illuminate\Contracts\Support\Arrayable;

class processPaymentResponse implements Arrayable {
	public $billed=false;
	public $confirmed=false;	
	public $other_data=array();
	
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
		return $this;
	}

	public function toArray(){
		return array("billed" => $this->billed, "confirmed" => $this->confirmed, "other_data" => $this->other_data);
	}
	
	
}