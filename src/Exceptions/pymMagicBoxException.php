<?php
namespace Mantonio84\pymMagicBox\Exceptions;

abstract class pymMagicBoxException extends \Exception {
    
    public static function make(...$args){
        return new static(...$args);
    }
}