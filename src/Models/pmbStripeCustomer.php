<?php
namespace Mantonio84\pymMagicBox\Models;


/**
 * Associazione tra gli utenti della PYM e i clienti Stripe.
 * 
 * @author Agostino Pagnozzi
 */
class pmbStripeCustomer extends pmbBaseWithPerformer  
{		
    protected $guarded = [ 'id' ];
    
    public function scopePmbCustomer($query, $value)
    {
        return $query->where('pmb_customer_id',$value);
    }
    
    public function scopeStripeCustomer($query, $value)
    {
        return $query->where('stripe_customer_id', $value);
    }
    
    public function getPmbLogData(): array 
    {
        return $this->only(['performer_id', 'pmb_customer_id', 'stripe_customer_id']);
    }
}