<?php
namespace Mantonio84\pymMagicBox\Models;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;
use \Illuminate\Database\Eloquent\Model;


abstract class pmbBase extends Model implements pmbLoggable {
        abstract public function getPmbLogData(): array;
    
        public static function make(array $data=[]){
            return new static($data);
        }        	
}
	