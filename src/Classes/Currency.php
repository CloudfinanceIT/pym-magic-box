<?php
namespace Mantonio84\pymMagicBox\Classes;
use Illuminate\Support\Str;

class Currency {
    
    protected static $data=[];
    
    public static function get($code){
        if (static::isValidCodeName($code)){
            if (!array_key_exists($code, static::$data)){
                static::$data[$code]=null;
                $file=static::getDBPath($code.".json");
                if (is_file($file)){
                    static::$data[$code]=static::loadCodeFile($file);
                }
            }
            return static::$data[$code];
        }      
        return null;
    }
    
    public static function all(){
        static::$data=[];
        $path=static::getDBPath();        
        if (is_dir($path)){
            $files=scandir($path);
            foreach ($files as $f){
                $code=basename($f);
                if (static::isValidCodeName($code)){
                    static::$data[$code]=static::loadCodeFile($path.DIRECTORY_SEPARATOR.$f);
                }
            }
        }
        return static::$data;
    }
    
    public static function exists(string $code){
        return (!is_null(static::get($code)));
    }
    
    protected static function loadCodeFile(string $file){
        return json_decode(file_get_contents($file),true);
    }
    
    protected static function isValidCodeName($str){
        return (preg_match('/[A-Z]{3}$/', $str)>0);
    }
    protected static function getDBPath($filename=""){
        $path=realpath(Str::finish(__DIR__,DIRECTORY_SEPARATOR).implode(DIRECTORY_SEPARATOR,["..","Resources","Currencies"]));                
        if (!empty($filename)){
            $path.=Str::start($filename, DIRECTORY_SEPARATOR);
        }
        return $path;
    }
 
    protected $info;
    
    public function __construct(string $code) {        
        $this->info=static::get($code);
        if (is_null($this->info)){
            throw new \Exception("Invalid currency code '$code'!");
        }
    }
    
    public function __get($name){
        return array_key_exists($name, $this->info) ? $this->info[$name] : null;
    }
    
    public function numberFormat(float $amount, string $dec_point = ".", string $thousands_sep = ""){
        return number_format($amount,$this->info['decimals'],$dec_point,$thousands_sep);
    }
    
    public function format(float $amount, string $dec_point = ".", string $thousands_sep = ""){
        return $this->info['code']." ".$this->numberFormat($amount,$dec_point,$thousands_sep);
    }
}
