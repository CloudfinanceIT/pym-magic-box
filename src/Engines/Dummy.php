<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;

class Dummy extends Base {
    
    //TRANQUILLI: E' TUTTO FINTO!!!
    
    public static function autoDiscovery(){
        if (config("app.env")!="production"){
            return [
                "name" => "dummy",               
            ];
        }
    }
    
    protected function validateConfig(array $config) {	
    }
	
    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null) {
        return array_merge($data,["seed" => uniqid()]);
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        return true;
    }

    protected function onProcessPaymentConfirm(pmbPayment $payment, array $data = array()): bool {
        return true;
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array(), string $customer_id): processPaymentResponse {       
        return new processPaymentResponse([
            "billed" => true,
            "confirmed" => true,            
			"transaction_ref" => uniqid(),
            "other_data" => ["seed" => uniqid()],
        ]);
    }
    
    protected function onProcessRefund(pmbPayment $payment, float $amount, array $data = array()): bool {
        $this->registerARefund($payment, $amount);
        return true;
    }

    public function isRefundable(pmbPayment $payment): float {
        if (!$payment->billed && !$payment->confirmed){
            return 0;
        }
        return $payment->refundable_amount;
    }

    public function supportsAliases(): bool {
        return true;
    }
    
    public function isConfirmable(pmbPayment $payment): bool {
        return $payment->billed && !$payment->confirmed && !$payment->refuned;
    }

    protected function onProcessAliasConfirm(pmbAlias $alias, array $data = array()): bool {
        return !$alias->confirmed;
    }

    public function isAliasConfirmable(pmbAlias $alias): bool {
        return true;
    }

}
