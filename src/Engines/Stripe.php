<?php
namespace Mantonio84\pymMagicBox\Engines;

use \Mantonio84\pymMagicBox\Classes\aliasCreateResponse;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Models\pmbStripeCustomer;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Stripe\PaymentIntent;
use Carbon\Carbon;


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
        // Recupera il payment intent:
        $paymentIntent = $this->getPaymentIntent($payment->tracker);
        
        // Il pagamento è confermato solo se il paymentIntent ha stato SUCCEEDED:
        return ($paymentIntent && $paymentIntent->status == PaymentIntent::STATUS_SUCCEEDED);
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool
    {        
        // Id del metodo di pagamento:
        $paymentMethodId = $alias->tracker ?? null;
        
        return $this->detachPaymentMethod($paymentMethodId);
    }

    protected function onProcessAliasConfirm(pmbAlias $alias, array $data = []): bool
    {
        return false;
    }

    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null)
    {
        // Cerca un paymentIntet oppure un setup intent:
        $paymentIntentId = Arr::get($data, "payment_intent");
        $setupIntentId = Arr::get($data, "setup_intent");
        
        if (!empty($setupIntentId)) {
            // Recupera il setup intent:
            $setupIntent = $this->getSetupIntent($setupIntentId);
            if (empty($setupIntent)) {
                return $this->throwAnError("Invalid setup intent for alias create!");
            }
            
            $usage = $setupIntent->usage;
            $customerId = $setupIntent->customer;
            $paymentMethodId = $setupIntent->payment_method;
        } else if (!empty($paymentIntentId)) {
            // Recupera il payment intent:
            $paymentIntent = $this->getPaymentIntent($paymentIntentId);
            if (empty($paymentIntent)) {
                return $this->throwAnError("Invalid payment intent for alias create!");
            }
            
            $usage = $paymentIntent->setup_future_usage;
            $customerId = $paymentIntent->customer;
            $paymentMethodId = $paymentIntent->payment_method;
        } else {
            return $this->throwAnError("Payment intent or setup intent not found for alias create!");
        }
        
        // Tipo di uso:
        if (empty($usage)) {
            return $this->throwAnError("Invalid usage for alias create!");
        }
                
        // Dettagli del metodo di pagamento:
        $paymentMethod = $this->getPaymentMethod($paymentMethodId);
        if (empty($paymentMethod)) {
            return $this->throwAnError("Invalid payment method for alias create!");
        }        
        $paymentMethodType = $paymentMethod->type;
        $paymentMethodDetails = $paymentMethod->{$paymentMethodType}->toArray();
        
        // Dati aggiuntivi: 
        $paymentMethodDetails['customer'] = $customerId;
        
        // Scadenza:
        $expires_at = null;
        switch ($paymentMethodType) {
            case 'card':
                $expires_at = Carbon::create($paymentMethodDetails['exp_year'], $paymentMethodDetails['exp_month'], 1, 12, 0, 0)->endOfMonth();
                break;
        }
                
        return aliasCreateResponse::make([
            "tracker"    => $paymentMethodId,
            "adata"      => $paymentMethodDetails,
            "expires_at" => $expires_at,
            "confirmed"  => true
        ]);
        
        return [ 'payment_method' => $paymentMethodId, 'payment_method_options' => $options ];
    }

    protected function onProcessRefund(pmbPayment $payment, float $amount, array $data = []): bool
    {        
        // Emette un rimborso su Stripe:
        $refund = $this->createRefund($payment->tracker, $payment->amount, $payment->currency_code);
        if (null != $refund) {
            $this->registerARefund($payment, $amount, $refund->id, $refund);
            return true;
        }
        
        return false;        
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
        // Pagamento con metodo salvato:
        if (!empty($alias_data)) {
            // Id del metodo di pagamento:
            $paymentMethodId = $alias_data->tracker ?? null;
            if (empty($paymentMethodId)) {
                return $this->throwAnError("Invalid payment method id for alias!");
            }
            
            // Dettagli del metodo di pagamento:
            $paymentMethod = $this->getPaymentMethod($paymentMethodId);
            if (empty($paymentMethod) || empty($paymentMethod->customer)) {
                return $this->throwAnError("Invalid payment method for alias!");
            }
            
            // Descrizione del pagamento:
            $description = $data['payment_description'] ?? null;
            
            // Crea il paymentIntent su Stripe con i dati del cliente:
            $paymentIntent = $this->createPaymentIntent($paymentMethod->customer, $payment->amount, $payment->currency_code, $paymentMethod->type ?? null, $description, null, $paymentMethodId, true);
        } else {        
            // Dati di risposta:
            $paymentIntentId = $data['payment_intent'];
            $paymentIntentClientSecret = $data['payment_intent_client_secret'];
            
            // Payment intent:
            $paymentIntent = $this->getPaymentIntent($paymentIntentId);
            
            // Payment intent non valido:
            if ($paymentIntent == null || $paymentIntent['client_secret'] != $paymentIntentClientSecret) {
                return $this->throwAnError(__("Token di pagamento non valido."));
            }
            
            // Altrimenti controllo il paymentIntent per vedere se è andato tutto bene:
            
            // Dati del pagamento:
            $currency = strtoupper($paymentIntent->currency);
            $amount = $this->_displayAmountFromStripe($paymentIntent->amount, $currency);
                    
            // Controlla che il pagamento effettuato abbia valuta e importo corretti:
            if ($amount < $payment->amount || $currency != $payment->currency_code) {
                return $this->throwAnError(__("onProcessPayment: Importo o valuta del pagamento non valida. Ottenuto: " . $amount . " " . $currency . ", atteso: " . $payment->amount . " " . $payment->currency_code));
            }
        }
        
        // Dettagli del pagamento:
        $billed = false;
        $confirmed = false;
        
        // Id della transazione:
        $balanceTransactionId = null;
        
        if ($paymentIntent) {
            if ($paymentIntent->status == PaymentIntent::STATUS_PROCESSING) {
                $billed = true;                
            } elseif ($paymentIntent->status == PaymentIntent::STATUS_SUCCEEDED) {
                $billed = true;  
                $confirmed = true;
            }
            
            // Prima "charge" associata:
            $charge = $paymentIntent->charges->first();
            
            // Id della transazione:
            $balanceTransactionId = $charge->balance_transaction;
        }
        
        return processPaymentResponse::make([
            "billed"        => $billed,
            "confirmed"     => $confirmed,
            "tracker"       => optional($paymentIntent)->id,
            "transaction_ref" => $balanceTransactionId,
            "other_data"    => []
        ]);  
    }
    

    public function isRefundable(pmbPayment $payment): float
    {
        if (!$payment->billed && !$payment->confirmed) {
            return 0;
        }
        
        return $payment->refundable_amount;
    }
    
    
    public function webhook(Request $request)
    {   
        // Signature inviata da Stripe:
        $signature = $request->header('stripe-signature');
        
        // Payload della request:
        $payload = $request->getContent();
        
        // Eventuale messaggio di errore di validazione della richiesta:
        $errorMsg = null;
        
        // Validazione della richiesta:
        $event = $this->_getWebhookEvent($signature, $payload, $errorMsg);
                
        // Se c'è un errore lo logga e termina:
        if (false === $event) {
            $this->log("ERROR", "[WB] Stripe webhook not valid!", $errorMsg, ['signature' => $signature, 'payload' => $payload ]);
            return null;
        }
        
        // Log dell'evento:
        $this->log("INFO", "[WB] Stripe webhook event", [  'object' => $event->object, 'id' => $event->id, 'type' => $event->type, 'account' => $event->account, 'api_version' => $event->api_version, 'created' => $event->created, 'data' => $event->data->toArray() ]);
        
        $result = $this->_processWebhookEvent($event);
        if (null === $result) {
            return response("Webhook not implemented!");
        } elseif (false === $result) {
            return response("Error");
        }
        
        return response("Ok");
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
     * @param string|null $setupFutureUsage
     * @param string|null $methodId
     * @param bool $confirm                     Se true, crea e conferma direttamente il pagamento (solo se $methodId != null)
     * 
     * @return \Stripe\PaymentIntent|null
     */
    public function createPaymentIntent($customerId, $amount, $currency = 'EUR', $methodTypes = [], $description = null, $setupFutureUsage = null, $methodId = null, $confirm = false)
    {
        // Metodo di pagamento:
        if (!is_array($methodTypes)) {
            $methodTypes = [ $methodTypes ];
        }
        
        // Dati del pagamento:
        $paymentIntentData = [
            'customer'             => $customerId,
            'description'          => $description,
            'amount'               => $this->_formatAmount($amount, $currency),
            'currency'             => $this->_formatCurrency($currency),
            'payment_method_types' => $methodTypes
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
            $paymentIntent = $this->_getClient()->paymentIntents->create($paymentIntentData);
        } catch (\Exception $ex) {    
            $this->log("ERROR", "Stripe PYM Engine - Payment Intents creation error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $paymentIntent;
    }
    
    
    /**
     * Prende un payment_intent da Stripe.
     * 
     * @param string $paymentIntentId
     * 
     * @return \Stripe\PaymentIntent|null
     */
    public function getPaymentIntent($paymentIntentId)
    {
        try {
            $paymentIntent = $this->_getClient()->paymentIntents->retrieve($paymentIntentId);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Payment Intents retrieving error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $paymentIntent;
    }
    
    
    /**
     * Crea un intenzione di pagamento.
     *
     * @param string $customerId
     * @param string|array $methodTypes
     * @param string $usage Valori ammessi: 'on_session', 'off_session'
     *
     * @return \Stripe\SetupIntent|null
     */
    public function createSetupIntent($customerId, $methodTypes = [], $usage = 'off_session')
    {
        // Metodo di pagamento:
        if (!is_array($methodTypes)) {
            $methodTypes = [ $methodTypes ];
        }
        
        $setupIntentData = [
            'customer'             => $customerId,
            'payment_method_types' => $methodTypes
        ];
        
        // Salvataggio del metodo per usi futuri:
        if (!empty($usage)) {
            $paymentIntentData['usage'] = $usage;
        }
        
        // Crea il setup intent:
        try {
            $setupIntent = $this->_getClient()->setupIntents->create($setupIntentData);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Setup Intents creation error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $setupIntent;
    }
    
    
    /**
     * Prende un setup_intent da Stripe.
     *
     * @param string $setupIntentId
     *
     * @return \Stripe\SetupIntent|null
     */
    public function getSetupIntent($setupIntentId)
    {
        try {
            $setupIntent = $this->_getClient()->setupIntents->retrieve($setupIntentId);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Setup Intents retrieving error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $setupIntent;
    }
       
    
    /**
     * Prende un payment_method da Stripe.
     *
     * @param string $paymentMethodId
     *
     * @return \Stripe\PaymentMethod|null
     */
    public function getPaymentMethod($paymentMethodId)
    {
        try {
            $paymentMethod = $this->_getClient()->paymentMethods->retrieve($paymentMethodId);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Payment Methods retrieving error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $paymentMethod;
    }
    
    
    /**
     * Scollega un metodo di pagamento da un utente.
     * 
     * @param unknown $paymentMethodId
     * 
     * @return bool
     */
    public function detachPaymentMethod($paymentMethodId)
    {
        try {
            $paymentMethod = $this->_getClient()->paymentMethods->detach($paymentMethodId);
        } catch (\Exception $ex) {
            $this->log("ERROR", "Stripe PYM Engine - Payment Methods detaching error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return false;
        }
        
        return true;
    }
    
    
    /**
     * Emette un rimborso.
     * 
     * @param string $paymentIntentId
     * @param float $amount
     * @param string|null $currency
     * 
     * @return \Stripe\Refund|null
     */
    public function createRefund($paymentIntentId, $amount, $currency = 'EUR')
    {
        // TODO: occorrerebbe controllare che la currency passata sia la stessa
        // di quella usata per il pagamento...
        
        try {
            $refund = $this->_getClient()->refunds->create([
                'payment_intent' => $paymentIntentId, 
                'amount'         => $this->_formatAmount($amount, $currency)
            ]);
        } catch (\Exception $ex) {            
            $this->log("ERROR", "Stripe PYM Engine - Refund creating error.", $ex->getMessage() . "\n\n" . $ex->getTraceAsString());
            return null;
        }
        
        return $refund;
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
    protected function _getWebhookEvent($signature, $payload = "", &$errorMsg = null)
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $signature, $this->config['endpoint_secret_key']
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
            $customer = $this->getCustomer($pmbStripeCustomer->stripe_customer_id);
            
            // Se il cliente è stato cancellato da Stripe, lo cancella anche sulla piattaforma:
            // (ATTENZIONE: perchè questo caso dovrebbe già essere satto gestito dai webhook...)
            if (!empty($customer) && $customer->isDeleted()) {
                $this->_webhookCustomerDeleted($customer);
                $customer = null;
                $pmbStripeCustomer = null;
            }
        }
        
        // Se il cliente non è stato mai creato oppure è stato cancellato su Stripe, lo crea:
        if (empty($customer)) {
            $customer = $this->createCustomer($customerData);
        } else {
            // Altrimenti lo aggiorna:
            $customer = $this->updateCustomer($customer->id, $customerData);
        }

        // Salva i dati sull'account Stripe del cliente (sempre che sia stato possibile crearlo):
        if (empty($pmbStripeCustomer)) {
            $data = [
                'performer_id'       => $this->performer->id,
                'pmb_customer_id'    => $pmbCustomerId,
                'stripe_customer_id' => $customer->id
            ];
            pmbStripeCustomer::create($data);
            
            $this->log("INFO", "Created Stripe Customer for '" . $pmbCustomerId . "': '" . $customer->id . "'", "", ["cu" => $pmbStripeCustomer]);
        }

        return $customer;
    }
    
    
    /**
     * Chiave Stripe pubblicabili.
     * 
     * @return string
     */
    public function getPublicKey()
    {
        return $this->config['public_key'] ?? '';
    }
        
    
    /**
     * Crea un nuovo cliente.
     *
     * @param array $customerData
     *
     * @return \Stripe\Customer|null
     */
    public function createCustomer($customerData)
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
    public function getCustomer($customerId)
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
    public function updateCustomer($customerId, $newCustomerData)
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
        
    
    /**
     * Processa un'evento Stripe.
     * 
     * @param \Stripe\Event $event
     *
     * @return true se l'evento è stato gestito, false altrimenti.
     */
    protected function _processWebhookEvent(\Stripe\Event $event)
    {
        switch ($event->type) {
            case \Stripe\Event::PAYMENT_INTENT_SUCCEEDED:
                /**
                 * "payment_intent.succeeded": pagamento riuscito.
                 */
                
                /**
                 * @var \Stripe\PaymentIntent $paymentIntent
                 */
                $paymentIntent = $event->data->object;      
                
                return $this->_webhookPaymentIntentSucceeded($paymentIntent);
                break;
                    
            case \Stripe\Event::PAYMENT_INTENT_PAYMENT_FAILED:
                /**
                 * "payment_intent.payment_failed": pagamento non andato a buon fine
                 */
                                
                /**
                 * @var \Stripe\PaymentIntent $paymentIntent
                 */
                $paymentIntent = $event->data->object;
                
                return $this->_webhookPaymentIntentFailed($paymentIntent);
                break;
                
            case \Stripe\Event::CHARGE_DISPUTE_CREATED:
                /**
                 * "charge.dispute.created": contestazione iniziata
                 */                
                // TODO: avvertire l'utente che una disputa è stata creata...
                break;
                
            case \Stripe\Event::CHARGE_DISPUTE_CLOSED:
                /**
                 * "charge.dispute.closed": contestazione persa
                 */
                                
                /**
                 * @var \Stripe\Dispute $dispute
                 */
                $dispute = $event->data->object;
                
                return $this->_webhookChargeDisputeClosed($dispute);                
                break;
                
            case \Stripe\Event::MANDATE_UPDATED:
                /**
                 * "mandate.updated": mandato di pagamento modificato
                 */
                
                /**
                 * @var \Stripe\Mandate $mandate
                 */
                $mandate = $event->data->object;
                
                // Stato del mandato:
                $status = $mandate->status;
                                
                // Se il mandato è cancellato, cancella il metodo di pagamento:
                if ($mandate->status == 'inactive') {
                    return $this->_deletePaymentMethod($mandate->payment_method);
                }
                
                return true;
                break;
                
            case \Stripe\Event::PAYMENT_METHOD_DETACHED:
                /**
                 * "payment_method.detached": metodo di pagamento "staccato"
                 */
                
                // Questo evento può avvenire quando ad es. una carta di credito è staccata su Stripe.
                
                /**
                 * @var \Stripe\PaymentMethod $paymentMethod
                 */
                $paymentMethod = $event->data->object;
                
                return $this->_deletePaymentMethod($paymentMethod);                
                break;
                
            case \Stripe\Event::CUSTOMER_DELETED:
                /**
                 * "customer.deleted": cliente cancellato
                 */
                
                /**
                 * @var \Stripe\Customer $customer
                 */
                $customer = $event->data->object;
                
                return $this->_webhookCustomerDeleted($customer);
                break;
                    
            default:
                break;
        }
        
        return null;
    }
    
    
    /**
     * Chiama una funzione finchè non restituisce un risultato non nullo.
     * 
     * @param callable $callback
     * @param float $waitMillis - Tempo di attesa tra una chiamata e l'altra in millisecondi. 
     * @param float $maxTimeMillis - Numero massimo di millisecondi da aspettare.
     * 
     * @return mixed|null
     */
    protected function _callUntilNotNull(callable $callback, $waitMillis = 0, $maxTimeMillis = 10000) 
    {
        // Cerca il pagamento:
        $timeStart = floor(microtime(true) * 1000);
        while (true) {
            $ris = $callback();
            if (null !== $ris) {
                return $ris;
            }
            
            // Dopo 3 sec. esce lo stesso:
            $time = floor(microtime(true) * 1000);
            if ($time - $timeStart > $maxTimeMillis) {
                break;
            }
            
            // Aspetta il tempo indicato prima di ripetere la chiamata:
            while(floor(microtime(true) * 1000) - $time < $waitMillis) { };
        }
        
        return null;
    }
    
    
    /**
     * Pagamento effettuato con successo.
     * 
     * @param \Stripe\PaymentIntent $paymentIntent
     * 
     * @return boolean
     */
    protected function _webhookPaymentIntentSucceeded(\Stripe\PaymentIntent $paymentIntent)
    {   
        // Cerca il pagamento:
        $payment = $this->_callUntilNotNull(function() use ($paymentIntent) {
            return pmbPayment::ofPerformers($this->performer)->billed()->where("tracker", $paymentIntent->id)->first();            
        }, 1000, 7500);
               
        // Se non lo trova lo segnala:
        if (null == $payment) {
            $this->log("NOTICE", "[WB] 'payment_intent.succeeded': suitable payment not found!", $paymentIntent, ['performer' => $this->performer, 'paymentIntentId' => $paymentIntent->id]);
            return false;
        }
        
        // Pagamento trovato:
        $this->log("DEBUG", "[WB] 'payment_intent.succeeded': found payment #" . $payment->getKey() . "...", $paymentIntent, ["py" => $payment]);
        
        // Se il pagamento era già confermato, tutto ok:
        if ($payment->confirmed) {
            $this->log("NOTICE", "[WB] 'payment_intent.succeeded': payment #" . $payment->getKey() . " was already confirmed: skipped", $paymentIntent, ["py" => $payment]);
            return true;
        }
        
        // Altrimenti lo conferma:
        $confirmedPayment = $this->confirm($payment);
        if ($confirmedPayment->confirmed) {
            return true;
        }
        
        return false;
    }
    
    
    /**
     * Pagamento rifiutato.
     *
     * @param \Stripe\PaymentIntent $paymentIntent
     *
     * @return boolean
     */
    protected function _webhookPaymentIntentFailed(\Stripe\PaymentIntent $paymentIntent)
    {
        // Cerca il pagamento:
        $payment = $this->_callUntilNotNull(function() use ($paymentIntent) {
            return pmbPayment::ofPerformers($this->performer)->billed()->where("tracker", $paymentIntent->id)->first();
        }, 1000, 7500);
            
        // Se non lo trova lo segnala:
        if (null == $payment) {
            $this->log("NOTICE", "[WB] 'payment_intent.failed': suitable payment not found!", $paymentIntent, ['performer' => $this->performer, 'paymentIntentId' => $paymentIntent->id]);
            return false;
        }
        
        // Pagamento trovato:
        $this->log("DEBUG", "[WB] 'payment_intent.failed': found payment #" . $payment->getKey() . "...", $paymentIntent, ["py" => $payment]);
                
        // Scatena un evento di pagamento rigettato:
        event(new \Mantonio84\pymMagicBox\Events\Payment\Rejected($this->merchant_id, $payment));
        
        return true;
    }
    
    
    /**
     * Disputa conclusa.
     *
     * @param \Stripe\Dispute $dispute
     *
     * @return boolean
     */
    protected function _webhookChargeDisputeClosed(\Stripe\Dispute $dispute)
    {
        // La disputa non si è conclusa con uno storno:
        if ($dispute->status != \Stripe\Dispute::STATUS_LOST) {
            $this->log("DEBUG", "[WB] 'charge.dispute_closed': dispute closed with status " . $dispute->status . "... Payment not refunded.");
            return true;
        }
        
        // Payment intent:
        $paymentIntent = $dispute->payment_intent;
        
        // Cerca il pagamento:
        $payment = pmbPayment::ofPerformers($this->performer)->billed()->where("tracker", $paymentIntent)->first();
            
        // Se non lo trova lo segnala:
        if (null == $payment) {
            $this->log("NOTICE", "[WB] 'charge.dispute_closed': suitable payment not found!", $paymentIntent, ['performer' => $this->performer, 'paymentIntentId' => $paymentIntent->id]);
            return false;
        }
        
        // Pagamento trovato:
        $this->log("DEBUG", "[WB] 'charge.dispute_closed': found payment #" . $payment->getKey() . "...", $paymentIntent, ["py" => $payment]);
        
        // Scatena un evento di disputa persa:
        event(new \Mantonio84\pymMagicBox\Events\Payment\DisputeLost($this->merchant_id, $payment));
        
        return true;
    }
    
    
    /**
     * Un utente è stato cancellato da Stripe.
     * 
     * @param \Stripe\Customer $customer
     * 
     * @return boolean
     */
    protected function _webhookCustomerDeleted(\Stripe\Customer $customer)
    {
        // Cerca eventuali utenti salvati:
        $pmbStripeCustomer = pmbStripeCustomer::stripeCustomer($customer->id)->first();
        if (empty($pmbStripeCustomer)) {
            return false;
        }
        
        // Id:
        $pmbCustomerId = $pmbStripeCustomer->pmb_customer_id;
        
        // Cancella l'utente Stripe dalla PYM:
        $pmbStripeCustomer->delete();
        
        // Cancella i metodi di pagamento salvati associati a quell'utente Stripe:
        PmbAlias::where('customer_id', $pmbCustomerId)
            ->get()
            ->each(function($pmbAlias) use ($customer) {
                $options = $pmbAlias->adata ?? [];
               
                if (is_array($options) && isset($options['customer']) && $options['customer'] == $customer->id) {
                    $this->aliasDelete($pmbAlias);
                }
            });
            
        return true;
    }
    
    
    /**
     * Metodo di pagamento cancellato su Stripe.
     * 
     * @param \Stripe\PaymentMethod $paymentMethod
     * 
     * @return boolean
     */
    protected function _deletePaymentMethod(\Stripe\PaymentMethod $paymentMethod)
    {
        // Aspetta alcuni secondi per evitare loop:
        sleep(3);
                
        // Cancella i metodi di pagamento salvati associati a quell'utente Stripe:
        $alias = PmbAlias::where('tracker', $paymentMethod->id)->first();
                
        // Lo cancella:
        if (null != $alias && !$alias->trashed()) {
            $this->aliasDelete($alias);
        }
        
        return true;
    }
}
