<?php
namespace Mantonio84\pymMagicBox\Models;
use \Illuminate\Support\Str;


class pmbMethod extends pmbBase {
		
	protected $guarded=["id"];
		
	public function getPmbLogData(): array {
		return ["method_name" => $this->name];
	}
	
	public function getEngineClassNameAttribute(){		
		return Str::start($this->engine,"\Mantonio84\pymMagicBox\Engines\\");		
	}
        
        public function performers(){
            return $this->hasMany(pmbPerformer::class,"method_id");
        }
}