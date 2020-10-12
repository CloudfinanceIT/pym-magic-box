<?php
namespace Mantonio84\pymMagicBox\Exceptions;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;

class genericMethodException extends pymMagicBoxLoggedException {
	
    public function __construct($level, string $merchant_id, string $message, $details="", array $info=[]) {
        pmbLogger::make()->write($level,$merchant_id,array_merge($info,["details" => $details, "message" => $message]));
        parent::__construct($message);
        
    }
   

}