<?php

namespace Mantonio84\pymMagicBox\Rules;

use Illuminate\Contracts\Validation\Rule;

class EqualsTo implements Rule
{
    
    protected $against;
    protected $strict=false;
    
    public function __construct($against, bool $strict=false) {
        $this->against=$against;
        $this->strict=$strict;
    }
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return (($value == $this->against && !$this->strict) || ($value === $this->against));
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return "Must be equal to '".$this->against."'";
    }
    
   
}
