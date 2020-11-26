<?php
namespace Mantonio84\pymMagicBox\Exceptions;
use Illuminate\Support\MessageBag;
use \Illuminate\Support\Arr;

class pymMagicBoxValidationException extends pymMagicBoxException {
    
    public $errors;
    
    public function render(){
        if (config("app.debug")===true){
            $h="<html><body><h1>".get_class($this)."</h1>";
            $h.="<h4>".$this->message."</h4>";
            $errors=Arr::flatten($this->errors->getMessages());
            $h.="<ul>";
            foreach ($errors as $e){
                $h.="<li>".$e."</li>";
            }
            $h.="</ul>";
            $h.="</body></html>";
            return $h;
            
        }
    }
    
    public function withErrors(MessageBag $errors){
        $this->errors=$errors;        
        return $this->loggable("CRITICAL",null,["message" => $this->message, "details" => $errors]);        
    }
}