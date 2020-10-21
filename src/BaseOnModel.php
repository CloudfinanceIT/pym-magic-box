<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;
use \Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use \Illuminate\Support\Str;


abstract class BaseOnModel extends Base implements pmbLoggable, Arrayable, Jsonable, JsonSerializable {
        
        protected $modelClassName = "";
        protected $en;
        protected abstract function searchModel($ref);
        
	
	public static function find(string $merchant_id, $ref){
		
		return new static($merchant_id, is_scalar($ref) ? $ref : null);
	}
        
        protected function searchModelOrFail($ref){
            $ret=$this->searchModel($ref);
            if (!is_null($ret)){
                return $ret;
            }
            
            throw (new ModelNotFoundException)->setModel(
                $this->modelClassName, $ref
            );
        }


        public function toBase(){
		return $this->managed;
	}
	
	public static function query(){
		return $this->managed->newQuery();
	}
	
	public function getPmbLogData(): array{
		if (method_exists($this->managed,"getPmbLogData")){
			return $this->managed->getPmbLogData();
		}else{
			return array();
		}
	}
	
	public function __get($name){
            $mt="getProp".ucfirst(Str::camel($name));
            if (method_exists($this, $mt)){
                return $this->{$mt}();
            }
            return $this->managed->getAttribute($name);	
	}
	
        public function toArray() {
            return $this->managed->toArray();
        }
        
        public function __toString() {
            return $this->toJson();
        }
        
        public function toJson($options = 0) {
            return $this->managed->toJson($options);
        }
        
        public function jsonSerialize(){
            return $this->toArray();
        }
        
        protected function getPropEngine(){
            return new Engine($this->merchant_id, $this->buildEngine());
	}        
        
        protected function getPropMethod(){
            return $this->performer->method;
	}

        
          protected function buildEngine(){
            if (is_null($this->en)){
                $this->en=$this->performer->getEngine();
            }
            return $this->en;
        }

}