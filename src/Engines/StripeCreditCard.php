<?php
namespace Mantonio84\pymMagicBox\Engines;

use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;


/**
 * Classe per il pagemnto con carte di credito tramite
 * Stripe.
 *
 * @author Agostino Pagnozzi
 */
class StripeCreditCard extends Base 
{
    public static function autoDiscovery()
    {
        return [
            "name" => "stripe_credit_card",          
        ];
    }
    
    public function isConfirmable(pmbPayment $payment): bool
    {
        return ($payment->billed && !$payment->confirmed && $payment->refunded_amount == 0);
    }

    protected function onProcessPaymentConfirm(pmbPayment $payment, array $data = []): bool
    {
        // TODO...
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool
    {
        // TODO...
    }

    protected function onProcessAliasConfirm(pmbAlias $alias, array $data = []): bool
    {
        return false;
    }

    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null)
    {
        // TODO...
    }

    protected function onProcessRefund(pmbPayment $payment, float $amount, array $data = []): bool
    {
        // TODO...
    }

    protected function validateConfig(array $config)
    {
        // TODO...
    }

    public function isAliasConfirmable(pmbAlias $alias): bool
    {
        return false;
    }

    public function supportsAliases(): bool
    {
        return true;
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = [], string $customer_id): processPaymentResponse
    {
        // TODO...
    }

    public function isRefundable(pmbPayment $payment): float
    {
        if (!$payment->billed && !$payment->confirmed) {
            return 0;
        }
        
        return $payment->refundable_amount;
    }
}
