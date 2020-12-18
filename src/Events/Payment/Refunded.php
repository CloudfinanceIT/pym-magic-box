<?php
namespace Mantonio84\pymMagicBox\Events\Payment;
use Mantonio84\pymMagicBox\Models\pmbPayment;

class Refunded extends Base {
	
	public $reason="user-request";
	public $amount=0;
	
	public function __construct(string $merchant_id, pmbPayment $payment){
		parent::__construct($merchant_id,$payment);
		$this->amount=$payment->refundable_amount;
	}
}