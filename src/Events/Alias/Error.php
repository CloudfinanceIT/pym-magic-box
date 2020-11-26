<?php
namespace Mantonio84\pymMagicBox\Events\Alias;
use Mantonio84\pymMagicBox\Models\pmbAlias;

class Error extends Base {
	public $error_type;
	
	public function __construct(string $merchant_id, pmbAlias $alias, string $error_type){
		parent::__construct($merchant_id,$alias);
		$this->error_type=$error_type;
	}
}