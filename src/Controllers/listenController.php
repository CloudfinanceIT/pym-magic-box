<?php
namespace Mantonio84\pymMagicBox\Controllers;

use \App\Http\Controllers\Controller;
use \Illuminate\Http\Request;
use \Mantonio84\pymMagicBox\Gateway;
use \Illuminate\Support\Str;
use \Mantonio84\pymMagicBox\Models\pmbLog;

class pymListenController extends Controller
{   
    public function __invoke(Request $request, $merchant, $method, $action){
		if (!$this->isUuid($merchant)){
			return abort(404);
		}
		$method=Str::camel($method);
		$engine=Gateway::of($merchant)->engine(["method" => $method]);
		if (is_null($engine)){
			return abort(404);
		}
		if (strlen($action)>1){
			$action="listen".ucfirst(Str::camel($action));
			if ($engine->canRun($action)){		
				$hRequest=$engine->findSomethingOfThisMagicBox($request->all(), null, false);
				pmbLog::write("INFO", $merchant, array_merge($hRequest,["engine" => $engine, "message" => "Listen request for '$action'", "details" => json_encode($request->all())]));
				return $engine->run($action,["request" => $hRequest]);
			}
		}
		return abort(501);
    }
	
	protected function isUuid($value)
    {
        if (! is_string($value)) {
            return false;
        }

        return preg_match('/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iD', $value) > 0;
    }
	
}