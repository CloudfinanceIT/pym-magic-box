<?php
namespace Mantonio84\pymMagicBox\Classes;
use Mantonio84\pymMagicBox\Logger as pmbLogger;
use Mantonio84\pymMagicBox\Models\pmbPerformer;
use \Illuminate\Support\Str;
use \Illuminate\Support\Facades\Validator;
use \Mantonio84\pymMagicBox\Exceptions\httpClientException;
    
class HttpClient extends \Mantonio84\pymMagicBox\Base {
    
    protected $base_uri="";
    public $options=[];
    protected $rules=false;   
    protected $logdata=[];    
    protected $methods=["get","delete","head","options","path","post","put"];
    
    public static function make(string $merchant_id, string $base_uri, $options=null){
        return new static($merchant_id, $base_uri, $options);
    }
    
    public function __construct(string $merchant_id, string $base_uri, $options=null) {        
        $this->acceptMerchantId($merchant_id);
        $this->base_uri=filter_var($base_uri,FILTER_VALIDATE_URL);                
        if ($this->base_uri===false){            
            throw httpClientException::make("Invalid base uri ($base_uri) given!")->loggable("ALERT", $merchant_id);
        }
        if (is_array($options)){
            $this->options=$options;
        }
    }
    
    public function validateResponsesWith($w){
        if ($w===false || $w === null || is_array($w) || $w instanceof \Closure){
            $this->rules=empty($w) ? false : $w;
        }
        return $this;
    }  
    
    public function withLogData(array $w){
        $this->logdata=array_merge($this->logdata,$w);
        return $this;
    }
    
    
    public function getEndPointURL(string $uri=""){        
        $bs=$this->base_uri;
        if (Str::endsWith($bs, "/")){
            $bs=substr($bs,-1);
        }
        if (!empty($uri)){
            if (Str::endsWith($uri, "/")){
                $uri=substr($uri,-1);
            }      
            $bs.=Str::start($uri,"/");
        }        
        return $bs;
    }
    
    public function request(string $method, string $uri, array $data, array $headers = []){      
        if (!in_array(strtolower($method),$this->methods)){
            throw httpClientException::make("Invalid request method '$method'!")->loggable("WARNING", $merchant_id);
            return false;
        }
        $pid=uniqid();
        $this->log("DEBUG","[$pid] $method REQUEST  TO ".$this->getEndPointURL($uri),$data);
        $mp=["form_params" => $data];
        if (!empty($headers)){
            $mp['headers']=$headers;
        }
        $response=$this->client()->request($method, $uri,$mp);                
        $statusCode=$response->getStatusCode();        
        $lgs="[$pid] $method RESPONSE FROM ".$this->getEndPointURL($uri)." (".$statusCode.")";
        $rawResponse=(string) $response->getBody();        
        if ($statusCode!=200){            
            throw httpClientException::make($lgs)->loggable("CRITICAL",$this->merchant_id,["details" => $rawResponse]);
            return false;
        }
        $rpdata=json_decode($rawResponse, true);
        if (!is_array($rpdata)){
            $lgs.=" INVALID JSON DATA!";            
            throw httpClientException::make($lgs)->loggable("CRITICAL",$this->merchant_id,["details" => $rawResponse]);
            return false;
        }
        $valid=true;
        if (is_array($this->rules) && !empty($this->rules)){
            $valid=Validator::make($rpdata,$this->rules)->passes();            
        }
        if ($this->rules instanceof \Closure){
            $a=call_user_func_array($this->rules,[$rpdata,$rawResponse,$uri,$this]);
            if (is_bool($a)){
                $valid=$a;
            }else if (is_array($a)){
                  $valid=Validator::make($rpdata,$a)->passes();      
            }
        }
        if (!$valid){
            $lgs.=" RESPONSE VALIDATION FAILED!";            
            throw httpClientException::make($lgs)->loggable("CRITICAL",$this->merchant_id,["details" => $rpdata]);
            return false;
        }
        $this->log("DEBUG",$lgs,$rpdata);
        return $rpdata;
    }
    
    public function __call($name, $arguments){
        if ((ctype_lower($name) || ctype_upper($name)) && (count($arguments) == 2 || count($arguments) == 3)){
            $name=strtolower($name);
            if (in_array(strtolower($name),$this->methods)){
                array_unshift($arguments,$name);
                return $this->request(...$arguments);
            }
        }
    }
    
    public function client(){        
        if (is_null($this->managed)){
            $this->managed=new \GuzzleHttp\Client(array_merge(config("pymMagicBox.http_client_options",[]),is_array($this->options) ? $this->options : [],[            
                'base_uri' => $this->base_uri,                                
            ]));
        }
        return $this->managed;
    }
    
    protected function log($level, string $message, $details=null){        
        pmbLogger::make()->write($level,$this->merchant_id,array_merge($this->logdata,["message" => $message, "details" => $details]));        
    }
   
}
