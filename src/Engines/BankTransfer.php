<?php
namespace Mantonio84\pymMagicBox\Engines;
use \Mantonio84\pymMagicBox\Classes\processPaymentResponse;
use \Mantonio84\pymMagicBox\Models\pmbPayment;
use \Mantonio84\pymMagicBox\Models\pmbAlias;
use \Mantonio84\pymMagicBox\Rules\IBAN;
use \Mantonio84\pymMagicBox\Exceptions\genericMethodException;
use \Mantonio84\pymMagicBox\Logger as pmbLogger;
use \Illuminate\Http\File;
use \Illuminate\Support\Str;
use \Illuminate\Support\Arr;


class BankTransfer extends Base {
    
    protected $validMimeTypes = [
        "application/pdf",
        "image/jpeg",
        "image/bmp",
        "image/png",
        "image/tiff",
        "image/x-tiff",
        "image/tiff",
        "image/x-tiff",
        "image/x-pcx",
        "image/gif"
        // "application/x-compressed",
        // "application/x-zip-compressed",
        // "application/zip",
        // "multipart/x-zip"
    ];
    
    public static function autoDiscovery(){        
        return [
            "name" => "bank_transfer",               
        ];        
    }
    
    protected function validateConfig(array $config) {	
        return [
           "bbcode-length" => ["required","integer","between:5,15"],
           "receipt-file-maxsize" => ["required","integer","min:0"],        
           "min-text-length" => ["bail", "nullable", "integer"],
           "excludes" => ["bail","nullable","array"],  
           "ocr-endpoint" => ["required","url"],
           "ocr-username" => ["required","string"],
           "ocr-license-code" => ["required","string"]
        ];
    }
	
    protected function onProcessAliasCreate(array $data, string $name, string $customer_id = "", $expires_at = null) {
        return [];
    }

    protected function onProcessAliasDelete(pmbAlias $alias): bool {
        return false;
    }

    protected function onProcessPaymentConfirm(pmbPayment $payment, array $data = array()): bool {
		if (isset($data['forced'])){
			if (!is_bool($data['forced'])){
				return $this->throwAnError("Invalid forced flag value!");
			}
			return $data['forced'];
		}
        if (!isset($data['filePath'])){
            return $this->throwAnError("No filepath given!");
        }                
        $receiptFile=new File($data['filePath'],true);       
        $maxFileSize=intval($this->cfg("receipt-file-maxsize",0));   
        
        if ($maxFileSize>0 && $receiptFile->getSize() > $maxFileSize){
            return $this->throwAnError("Receipt file size must be less than $maxFileSize bytes!");
        }
        
        if (!$this->isValidMimeType($receiptFile->getMimeType())){
            return $this->throwAnError("'".$data['filePath']."' invalid mime type!");
        }
        
        $parsers=["extractTextUsingParser","extractTextUsingOCR"];
        $value=false;
        $i=0;
        while ($i<count($parsers) && !$value){
            $text=call_user_func([$this,$parsers[$i]],$receiptFile->path(), $payment);
            $value=$this->validateExtractedTextAgaintPayment($text, $payment);
            $i++;
        }
        return $value;
        
    }

    protected function onProcessPayment(pmbPayment $payment, $alias_data, array $data = array(), string $customer_id): processPaymentResponse {        
        if (!isset($data['home-iban']) || empty($data['home-iban'])){
            return $this->throwAnError("Invalid home iban!");            
        }
        $data['home-iban']= str_replace(" ", "", $data['home-iban']);
        $ibanValid=IBAN::validate($data['home-iban']);
        if ($ibanValid!==true){
            return $this->throwAnError($ibanValid);            
        }
        		
		if (isset($data['code']) && $this->checkBBCodeValidAndUnique($data['code'])){
			$tracker=$data['code'];			
		}else{
			$tracker=$tracker=$this->generateBBCode();
		}
		
        return new processPaymentResponse([
            "billed" => true,
            "confirmed" => false,
            "transaction_ref" => $this->generateTransactionRef(),
            "tracker" => $tracker,
            "other_data"  => Arr::only($data,["home-iban","currency"])
        ]);
    }
    
    protected function onProcessRefund(pmbPayment $payment, float $amount, array $data = array()): bool {
        return false;
    }

    public function isRefundable(pmbPayment $payment): float {
        return 0;
    }

    public function supportsAliases(): bool {
        return false;
    }
    
    public function isConfirmable(pmbPayment $payment): bool {
         return $payment->billed && !$payment->confirmed && !$payment->refunded && !empty($payment->tracker);
    }
    
    protected function validateExtractedTextAgaintPayment(&$text, pmbPayment $payment){
        if (empty($text)){
            return false;
        }
        
        // Verifica dimensione minima del testo
        $minSize = $this->cfg("min-text-length");
        if ($minSize !== null) {
            $minSize = intval($minSize);
            if (strlen($text) < $minSize) {
                $this->log("DEBUG", ":::BankTransferReceiptPDFValidator::: Text too shoot: ".strlen($text)." <= ".$minSize);                
                return false;
            }
        }

        // Verifica che non ci sia testo "indesiderato" nel documento
        $excludes = $this->cfg("excludes");
        if (!empty($excludes)) {
            foreach ($excludes as $phrase) {
                $realPhrase = $phrase;
                $phrase = implode("\s*", str_split($phrase));
                $hasPhrase = (preg_match("/$phrase/i", $text) === 1);
                if ($hasPhrase) {                                    
                    $this->log("DEBUG", ":::BankTransferReceiptPDFValidator::: INVALID PHRASE '$realPhrase' matched");           
                    return false;
                }
            }
        }

        // Verifica IBAN
        $iban = $payment->other_data['home-iban'];        
        $ibanRegex = implode('\s*', str_split($iban));
        $hasIban = (preg_match("/$ibanRegex/i", $text) === 1);
        if (!$hasIban) {
            $tokens = explode(" ", $text);
            foreach ($tokens as $token) {
                $diff = levenshtein($iban, $token);
                if ($diff <= 5) {
                    $hasIban = true;                        
                    $this->log("DEBUG", ":::BankTransferReceiptPDFValidator::: Bonifico ritenuto valido con IBAN diverso di $diff caratteri in '$token'");         
                    break;
                }
            }
        }

        if (!$hasIban) {
            $hasIban = strpos($text, substr($iban, -5)) !== false;
        }

        if (!$hasIban) {        
             $this->log("DEBUG", ":::BankTransferReceiptPDFValidator::: IBAN not found");         
            return false;
        }        

        // Verifica Causale
        $causal = $payment->tracker;      
        $casualRegex = implode("\s*", str_split($causal));
        $hasCausal = (preg_match("/$casualRegex/i", $text) === 1);
        if (!$hasCausal) {    
            $this->log("DEBUG", ":::BankTransferReceiptPDFValidator::: CAUSAL not found");         
            return false;
        }
               
        // Verifica importo              
        $amountString = $payment->amount . "";
        $parts = explode(".", $amountString);
        // Rendi la parte decimale opzionale se e solo se è nulla
        $decimalsString = "";
        if (count($parts) > 1) {
            $decimals = intval($parts[1]);
            $decimalsString = "\s*[\.,]\s*" . $parts[1] . "0*";
            if ($decimals === 0) {
                $decimalsString = "(\s*[\.,]\s*0+)?";
            }
        }
        $whole = floatval($parts[0]);
        $wholeString = number_format($whole, 0, ".", "(\s*[\.',]\s*)?");

        $amountRegex = "/$wholeString$decimalsString/i";
        $amountRegexResult = preg_match($amountRegex, $text);
        $hasAmount = ($amountRegexResult === 1);
        if (!$hasAmount) {
            // echo "AMOUNT not found ";
            $this->log("DEBUG", ":::BankTransferReceiptPDFValidator::: AMOUNT not found");     
            return false;
        }
        

        return true;
    }
    
    protected function extractTextUsingParser($filePath, pmbPayment $payment){
        try {
            $parser = new \Smalot\PdfParser\Parser();        
            $pdf = $parser->parseFile($filePath);        

            $pages = $pdf->getPages();
            $pdfText = "";
            foreach ($pages as $page) {
                $pdfText .= $page->getText() . " ";
            }

            // $pdfText = preg_replace('/[^a-zA-Z0-9\xA2-\xA5\x80\s\.,\']/u', ' ', $pdfText);
            $pdfText = preg_replace("/\s+/u", " ", $pdfText);
            $pdfText = trim($pdfText);
            return $pdfText;
        } catch (\Exception $ex) {            
            pmbLogger::make()->reportAnException($ex,"ERROR",$this->merchant_id,["pe" => $this->performer, "py" => $payment]);
            return "";
        }
    }
    
    protected function extractTextUsingOCR($filePath, pmbPayment $payment){
        $client = new \SoapClient($this->cfg("ocr-endpoint"),[
                "trace" => 1,
                "exceptions" => 1
        ]);
        
        $params = new \StdClass();
        $params->user_name = $this->cfg("ocr-username");
        $params->license_code = $this->cfg("ocr-license-code");
        $inimage = new \StdClass();

        $handle = fopen($filePath, 'r');
        $card_image = fread($handle, filesize($filePath));
        fclose($handle);

        $inimage->fileName = "sample_image.jpg";
        $inimage->fileData = $card_image;
        unset($card_image);
        $params->OCRWSInputImage = $inimage;

        $settings = new \StdClass();
        $settings->ocrLanguages = array("ITALIAN");
        $settings->outputDocumentFormat = "TXT";
        $settings->convertToBW = false;
        $settings->getOCRText = true;
        $settings->createOutputDocument = false;
        $settings->multiPageDoc = false;
        $settings->ocrWords = false;

        $params->OCRWSSetting = $settings;

        try {
            $result = $client->OCRWebServiceRecognize($params);
        } catch (\SoapFault $fault) {            
			pmbLogger::make()->reportAnException($ex,"ERROR",$this->merchant_id,["pe" => $this->performer, "py" => $payment]);
            return "";
        }
        $arr_str = json_decode(json_encode($result), true);

        if (!isset($arr_str['OCRWSResponse']['ocrText']['ArrayOfString'])) {
            return "";
        }
        if (!is_array($arr_str['OCRWSResponse']['ocrText']['ArrayOfString'])) {
            return "";
        }

        $strings = array_values($arr_str['OCRWSResponse']['ocrText']['ArrayOfString']);
        $pdfText = implode(" ", $strings);

        
        $pdfText = preg_replace("/\s+/u", " ", $pdfText);
        $pdfText = trim($pdfText);
        return $pdfText;

    }
    
    protected function generateBBCode(){
        $r=null;
        while (!$this->checkBBCodeValidAndUnique($r)){
            if (!is_null($r)){
                usleep(100);
            }
            $r=substr(str_shuffle("ABCDEFGHJLMNPQRTUVWXYZ2346789"), 0, $this->cfg("bbcode-length"));            
        }
        return $r;
    }
    
    protected function checkBBCodeValidAndUnique($code){
        if (!$this->checkBBCodeValid($code)){
			return false;
		}
        return !pmbPayment::ofPerformers($this->performer)->where("tracker",$code)->exists();        
    }
	
	protected function checkBBCodeValid($code){
		$pattern='/^[ABCDEFGHJLMNPQRTUVWXYZ2346789]{'.$this->cfg("bbcode-length").'}$/';
		return (preg_match($pattern,$code)>0);
	}
    
    protected function generateTransactionRef(){
        return Str::random(32);
    }
    
    protected function isValidMimeType($mimeType)
    {        
        return in_array(strtolower($mimeType), $this->validMimeTypes);
    }

    protected function onProcessAliasConfirm(pmbAlias $alias, array $data = array()): bool {
        return false;
    }

    public function isAliasConfirmable(pmbAlias $alias): bool {
        return false;
    }

}
