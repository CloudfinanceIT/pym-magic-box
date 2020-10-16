<?php

namespace Mantonio84\pymMagicBox\Rules;

use Illuminate\Contracts\Validation\Rule;

class RouteName implements Rule
{

    
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
        return \Route::has($value);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return "This route does not exists!";
    }
    
}
