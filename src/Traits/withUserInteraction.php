<?php
namespace Mantonio84\pymMagicBox\Traits;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Symfony\Component\HttpFoundation\Response;

trait withUserInteraction {            
    protected $interactive;
    protected $ir=false;
    
    public function setUserInteraction($w){        
        $this->interactive=($w instanceof Response) ? $w : null;
        $this->ir=false;
        return $this;
    }
    
    public function needsUserInteraction(){
        return !is_null($this->interactive);
    }

    public function getUserInteraction(){
        if (!$this->needsUserInteraction()){
            return null;
        }
        if (!$this->ir){
            pmbLogger::debug($this->merchant_id, ["re" => $this->managed, "pe" => $this->performer, "message" => class_basename($this). " user interaction readed first time"]);                
            $this->ir=true;
        }
        return $this->interactive;
    }		
    
    public function toResponse($request) {
            if ($this->needsUserInteraction()){
                return $this->getUserInteraction();
            }else{
                return new JsonResponse($this->managed);
            }
    }
}
