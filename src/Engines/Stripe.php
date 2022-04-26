<?php
namespace Mantonio84\pymMagicBox\Engines;

use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Models\pmbStripeCustomer;


/**
 * Classe per il pagemnto con carte di credito tramite
 * Stripe.
 *
 * @author Agostino Pagnozzi
 */
class Stripe extends Base 
{
    /**
     * @var \Stripe\StripeClient
     */
    protected $_client = null;
    
    public static function autoDiscovery()
    {
        return [
            "name" => "stripe",          
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
        return [
            "secret_key" => ["required", "string", "alpha_dash"],
            "public_key" => ["required", "string", "alpha_dash"],
            "endpoint_secret_key" => ["required", "string", "alpha_dash"]
        ];
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
    
    
    /**
     * METODI SPECIFICI DI STRIPE
     */
    
    
    /**
     * Valute non decimali.
     * @var array
     */
    const NON_DECIMAL_CURRENCIES = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
    
    
    /**
     * Crea un intenzione di pagamento.
     *
     * @param string $customerId
     * @param float $amount
     * @param string $currency
     * @param string|array $methodTypes
     * @param string|null $description
     * @param string|null $idempotencyKey       Chiave di idempotenza per evitare pagamenti duplicati
     * @param string|null $setupFutureUsage
     * @param string|null $methodId
     * @param bool $confirm                     Se true, crea e conferma direttamente il pagamento (solo se $methodId != null)
     * 
     * @return \Stripe\PaymentIntent|null
     */
    public function createPaymentIntent($customerId, $amount, $currency = 'EUR', $methodTypes = [], $description = null, $idempotencyKey = null, $setupFutureUsage = null, $methodId = null, $confirm = false)
    {
        // Metodo di pagamento:
        if (!is_array($methodTypes)) {
            $methodTypes = [ $methodTypes ];
        }
        
        // Dati del pagamento:
        $paymentIntentData = [
            'customer'               => $customerId,
            'description'            => $description,
            'amount'                 => $this->_formatAmount($amount, $currency),
            'currency'               => $this->_formatCurrency($currency),
            'payment_method_types'   => $methodTypes
        ];
        
        // Id di un metodo di pagamento salvato:
        if (!empty($methodId)) {
            $paymentIntentData['payment_method'] = $methodId;
        }
        
        // Pagamento confermato immediatamente:
        if (!empty($methodId) && $confirm) {
            $paymentIntentData['confirm'] = true;
            $paymentIntentData['off_session'] = true;
        }
        
        // Salvataggio del metodo per usi futuri:
        if (!empty($setupFutureUsage)) {
            $paymentIntentData['setup_future_usage'] = $setupFutureUsage;
        }
       
        // Crea il payment intent:
        try {
            $paymentIntent = $this->_getClient()->paymentIntents->create($paymentIntentData, [
                'idempotency_key' => $idempotencyKey
            ]);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Payment Intents creation error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $paymentIntent;
    }
       
    
    /**
     * Controlla che la firma di un payload ricevuto da un webhook sia valida.
     *
     * @param string $signature Firma della richiesta.
     * @param string $payload   Payload della richiesta.
     * @param array &$errorMsg  Eventuale messaggio di errore (valorizzato solo nel caso di errore).
     *
     * @return false
     */
    public function getWebhookEvent($signature, $payload = "", &$errorMsg = null)
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $signature, $this->_utils->getEndpointSecretKey()
                );
        } catch(\UnexpectedValueException $e) {
            $errorMsg = "Payload non valido.";
            return false;
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            $errorMsg = "Signature non valida.";
            return false;
        }
        
        return $event;
    }  
    
    
    /**
     * Prende il customer Stripe relativo ad un cliente.
     *
     * @param string $pmbCustomerId
     * @param array $customerData
     *
     * @return \Stripe\Customer|null
     */
    public function createOrUpdateCustomer($pmbCustomerId, $customerData = [])
    {
        $customer = null;
    
        // Dal db verifica se il cliente è già associato ad un account Stripe:
        $pmbStripeCustomer = pmbStripeCustomer::ofPerformers($this->performer)->pmbCustomer($pmbCustomerId)->first();
        if (!empty($pmbStripeCustomer)) {
            // E nel caso ne prende i dati:
            $customer = $this->_getCustomer($pmbStripeCustomer->stripe_customer_id);
        }
        
        // Se il cliente non è stato mai creato oppure è stato cancellato su Stripe, lo crea:
        if (empty($customer)) {
            $customer = $this->_createCustomer($customerData);
        } else {
            // Altrimenti lo aggiorna:
            $customer = $this->_updateCustomer($customer['id'], $customerData);
        }

        // Salva i dati sull'account Stripe del cliente (sempre che sia stato possibile crearlo):
        if (empty($pmbStripeCustomer)) {
            $data = [
                'performer_id'       => $this->performer->id,
                'pmb_customer_id'    => $pmbCustomerId,
                'stripe_customer_id' => $customer['id']
            ];
            pmbStripeCustomer::create($data);
            
            $this->log("INFO", "Created Stripe Customer for '" . $pmbCustomerId . "': '" . $customer['id'] . "'", "", ["cu" => $pmbStripeCustomer]);
        }

        return $customer;
    }
    
    
    /**
     * Crea un nuovo cliente.
     *
     * @param array $customerData
     *
     * @return \Stripe\Customer|null
     */
    protected function _createCustomer($customerData)
    {
        try {
            $customer = $this->_getClient()->customers->create($customerData);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Customer creation error", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $customer;
    }
    
    
    /**
     * Prende un cliente in funzione del suo id.
     *
     * @param string $customerId
     *
     * @return \Stripe\Customer|null
     */
    protected function _getCustomer($customerId)
    {
        try {
            $customer = $this->_getClient()->customers->retrieve($customerId);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Customer retrieving error", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $customer;
    }
    
    
    /**
     * Aggiorna i dati di un cliente.
     *
     * @param string $customerId
     * @param array $newCustomerData
     *
     * @return \Stripe\Customer|null
     */
    protected function _updateCustomer($customerId, $newCustomerData)
    {
        try {
            $customer = $this->_getClient()->customers->update($customerId, $newCustomerData);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Customer updating error", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $customer;
    }
                
    
    /**
     * Ottiene un'instanza del client Stripe.
     * 
     * @return \Stripe\StripeClient
     */
    protected function _getClient() 
    {
        if ($this->_client == null){
            $this->_client = new \Stripe\StripeClient(\Arr::get($this->config, 'secret_key'));
        }
        
        return $this->_client;
    }
    
    
    /**
     * Formatta il valore di un importo per le API di Stripe, in funzione della valuta passata.
     * Per valute decimali moltiplica per 100.
     *
     * Per la lista delle valute supportate e per le politiche di formattazione:
     *
     *      https://stripe.com/docs/currencies#presentment-currencies
     *
     * @param float $amount
     * @param string $currency
     *
     * @return integer
     */
    protected function _formatAmount($amount, $currency)
    {
        // Valuta corrente:
        $currency = strtoupper($currency);
        
        // Per le valute non decimali arrotondo ad intero:
        if (in_array($currency, self::NON_DECIMAL_CURRENCIES)) {
            return intval(round($amount));
        }
        
        return intval(round($amount * 100));
    }
    
    
    /**
     * Trasforma un valore ottenuto dalle API di Stripe
     * in un valore decimale.
     *
     * @param float $amount
     * @param string $currency
     *
     * @return float|integer
     */
    protected function _displayAmountFromStripe($amount, $currency)
    {
        // Valuta corrente:
        $currency = strtoupper($currency);
        
        // Per le valute non decimali arrotondo ad intero:
        if (in_array($currency, self::NON_DECIMAL_CURRENCIES)) {
            return $amount;
        }
        
        return $amount / 100;
    }
    
    
    /**
     * Formatta una currency per le API di Stripe.
     *
     * @param string $currency Codice ISO 4217 della valuta
     *
     * @return string
     */
    protected function _formatCurrency($currency)
    {
        return strtolower($currency);
    }
}
