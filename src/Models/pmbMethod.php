<?php
namespace Mantonio84\pymMagicBox\Models;
use \Illuminate\Support\Str;


class pmbMethod extends pmbBase {
		
	protected $guarded=["id"];
	
	protected $casts=["auto" => "boolean"];
	
	public function getPmbLogData(): array {
		return ["method_name" => $this->name];
	}
	
	public function getEngineClassNameAttribute(){		
		return Str::start("\Mantonio84\pymMagicBox\Engines\\",$this->engine);		
	}
}