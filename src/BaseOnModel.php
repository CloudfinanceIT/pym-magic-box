<?php
namespace Mantonio84\pymMagicBox;
use \Mantonio84\pymMagicBox\Interfaces\pmbLoggable;

abstract class BaseOnModel extends Base implements pmbLoggable {
	protected $managed;
	
	protected abstract function isReadableAttribute(string $name): bool;
	protected abstract function isWriteableAttribute(string $name, $value): bool;
	
	public static function find(string $merchant_id, $ref){
		
		return new static($merchant_id, is_scalar($ref) ? $ref : null);
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
		if ($this->isReadableAttribute($name)){
			return $this->managed->getAttribute($name);
		}
	}
	
	public function __set($name, $value){
		if ($this->isWriteableAttribute($name,$value)){
			$this->managed->setAttribute($name,$value);			
		}
	}
	
	public function save(){
		return $this->managed->save();
	}
	
	public function delete(){
		return $this->managed->delete();
	}
}