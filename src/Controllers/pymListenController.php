<?php
namespace Mantonio84\pymMagicBox\Controllers;

use \App\Http\Controllers\Controller;
use \Illuminate\Http\Request;
use \Mantonio84\pymMagicBox\Gateway;
use \Illuminate\Support\Str;
use \Mantonio84\pymMagicBox\Models\pmbMethod;
use \Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Illuminate\Http\Response;

class pymListenController extends Controller
{   
    public function merchantAction(Request $request, $merchant, $method, $action){
		if (!$this->isUuid($merchant)){
			return abort(404);
		}		
		$engine=Gateway::of($merchant)->build($method);
		if (is_null($engine)){
                    return abort(404);
		}
		if (strlen($action)>1){
			$action="listen".ucfirst(Str::camel($action));
			if ($engine->canRun($action)){						
                            pmbLogger::info($merchant, ["engine" => $engine, "message" => "Listen request for '$action'", "details" => $request]);
                            return $engine->run($action,["request" => $request->all()]);
			}
		}
		return abort(501);
    }
	
	public function webhook(Request $request, $merchant, $method){
		if (!$this->isUuid($merchant)){
			return abort(404);
		}	
		pmbLogger::info($merchant,["message" => "[WB] Incoming request for '$method'","details" => $request->all()]);	
		$engine=Gateway::of($merchant)->build($method);
		if (is_null($engine)){
			pmbLogger::error($merchant,["message" => "[WB] Reject: engine not found!","details" => $request->all(), "pe" => $engine->getPerformer()]);
			return abort(404);
		}
		if (!$engine->canRun("webhook")){
			pmbLogger::error($merchant,["message" => "[WB] Reject: this engine has does not support webhook!","details" => $request->all(), "pe" => $engine->getPerformer()]);
			return abort(404);
		}				
		pmbLogger::info($merchant,["message" => "[WB] Accepted","details" => $request->all(), "me" => $method]);
		$ret=$engine->webhook($request);
		if ($ret instanceof Response){
			return $ret;
		}
		pmbLogger::warning($merchant,["message" => "[WB] Accepted webhook returns incorrect response!","details" => $ret, "pe" => $engine->getPerformer()]);
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