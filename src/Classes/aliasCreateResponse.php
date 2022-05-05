<?php
namespace Mantonio84\pymMagicBox\Classes;
use \Illuminate\Contracts\Support\Arrayable;

class aliasCreateResponse implements Arrayable 
{
    use \Mantonio84\pymMagicBox\Traits\canBeInteractive;
        
	public $adata=[];
    public $confirmed = false;
    public $tracker = "";
    public $expires_at = null;

    public static function make($data = null) 
    {
        return new static($data);
    }
       
	public function __construct($data=null)
	{
		if (is_array($data)) {
			$this->fill($data);
		}
	}
	
	public function fill(array $data)
	{		
		if (array_key_exists("confirmed", $data)) {
			$this->confirmed=!empty($data['confirmed']);
		}		
		if (array_key_exists("adata", $data)) {
			$this->adata=is_array($data['adata']) ? $data['adata'] : [];
		}	
        if (array_key_exists("tracker", $data)) {
			$this->tracker = $data['tracker'];
		}
		if (array_key_exists("expires_at", $data)) {
		    $this->expires_at = $data['expires_at'];
		}
		return $this;
	}

	public function toArray()
	{
	    return array("adata" => $this->adata, "confirmed" => $this->confirmed, "tracker" => $this->tracker, "expires_at" => $this->expires_at);
	}
}
