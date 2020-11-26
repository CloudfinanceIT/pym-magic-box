<?php
namespace Mantonio84\pymMagicBox\Events\Alias;

use Mantonio84\pymMagicBox\Models\pmbAlias;
use \Illuminate\Support\Arr;
use Mantonio84\pymMagicBox\Alias;

abstract class Base {
	
	public $id;
	public $data;
	public $when;
	public $merchant_id;
	protected $model;
        
        public static function make(string $merchant_id, pmbAlias $alias){
            return new static($merchant_id,$alias);
        }
	
	public function __construct(string $merchant_id, pmbAlias $alias){
		$this->when=now();
		$this->id=intval($alias->getKey());
		$this->merchant_id=$merchant_id;
		$this->data=Arr::except($alias->toArray(),["performer","id"]);		
		$this->model=$alias;
	}
	
	public function getAlias(){
		return new Alias($this->merchant_id,$this->model);
	}
        
        public function with($key,$value=null){
            if (is_array($key)){
                foreach ($key as $k => $v){
                    $this->with($k,$v);
                }
                return $this;
            }
            if (property_exists($this, $key)){
                $this->{$key}=$value;
            }
            return $this;
        }
}