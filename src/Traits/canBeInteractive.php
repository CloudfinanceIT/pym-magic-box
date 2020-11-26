<?php

namespace Mantonio84\pymMagicBox\Traits;

trait canBeInteractive {
    protected $interactive=null;
    
    public function isInteractive(){
            return is_null($this->interactive);
        }
        
        public function needsUserInteraction($w){
            if (is_null($w) || $w === false){
                $this->interactive=null;
            }else{
                $this->interactive=($w instanceof \Illuminate\Http\Response) ? $w : response($w);
            }
            return $this;
        }
        
        public function getUserInteraction(){
            return $this->interactive;
        }
}
