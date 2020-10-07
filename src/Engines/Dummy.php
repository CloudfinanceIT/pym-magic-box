<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;

class Dummy extends Base {
    
    //TRANQUILLI: E' TUTTO FINTO!!!
    
    protected function onProcessAliasCreate(array $data, string $name = "", string $customer_id = "", $expires_at = null): array {
        return $data;
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        return true;
    }

    protected function onProcessConfirm(pmbPayment $payment, array $data = array()): bool {
        return true;
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array()): processPaymentResponse {
        return new processPaymentResponse([
            "billed" => true,
            "confirmed" => true,            
            "other_data" => ["seed" => uniqid()],
        ]);
    }
    
    protected function onProcessRefund(pmbPayment $payment, array $data = array()): bool {
        return true;
    }

    public function isRefundable(): bool {
        return true;
    }

    public function supportsAliases(): bool {
        return true;
    }

}
