<?php

namespace Mantonio84\pymMagicBox\Rules;

use Illuminate\Contracts\Validation\Rule;
use CMPayments\IBAN as IBANEngine;

class IBAN implements Rule
{

    protected $msg='Invalid IBAN!';
    
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (!is_string($value)){
            return false;
        }
        $value=str_replace(" ","",strtoupper($value));
        
        $iban = new IBANEngine($value);        
        
        if (!$iban->validate($this->msg)){
            return false;
        }
       
        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return $this->msg;
    }
    
    public static function validate(string $value){
        $value=str_replace(" ","",strtoupper($value));
        $iban = new IBANEngine($value);
        $msg="";
        if (!$iban->validate($msg)){
            return $msg;
        }
        return true;
    }
}
