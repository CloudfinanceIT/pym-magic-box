<?php
namespace Mantonio84\pymMagicBox\Events\Payment;
use Mantonio84\pymMagicBox\Models\pmbPayment;

class Error extends Base {
	public $error_type;
	
	public function __construct(string $merchant_id, pmbPayment $payment, string $error_type){
		parent::__construct($merchant_id,$payment);
		$this->error_type=$error_type;
	}
}