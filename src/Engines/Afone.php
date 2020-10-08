<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;

class Afone extends Base {
        
    
	protected function validateConfig(array $config): bool {
		
	}
	
    protected function onProcessAliasCreate(array $data, string $name = "", string $customer_id = "", $expires_at = null): array {
        
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        
    }

    protected function onProcessConfirm(pmbPayment $payment, array $data = array()): bool {
        
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array()): processPaymentResponse {
        
    }
    
    protected function onProcessRefund(pmbPayment $payment, array $data = array()): bool {
        
    }

    public function isRefundable(): bool {
        
    }

    public function supportsAliases(): bool {
        
    }

}
