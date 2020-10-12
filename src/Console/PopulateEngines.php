<?php
namespace Mantonio84\pymMagicBox\Console;

use Illuminate\Console\Command;
use \Illuminate\Support\Str;
use \Mantonio84\pymMagicBox\Engines\Base;
use Mantonio84\pymMagicBox\Models\pmbMethod;

class PopulateEngines extends Command
{
    protected $signature = 'pmb:populate-engines';

    protected $description = 'Riempie la tabella pmb_methods a partire dalle classe fisicamente presenti nel pacchetto';

    public function handle()
    {   
        
        $enginesFolder=realpath(__DIR__."/../Engines/");
        if (!is_dir($enginesFolder)){
            $this->error("Engines '$enginesFolder' not found. Please re-install this package!");
            return false;
        }
        $this->info("Scanning for engine classes...");
        $files=scandir($enginesFolder);
                
        foreach ($files as $baseName){
            usleep(500);
            if (in_array($baseName,[".","..","Base.php"])){
                continue;
            }           
            $fullFileName=$enginesFolder."/".$baseName;            
            if (is_file($fullFileName)){
                $fi=pathinfo($fullFileName);
                if ($fi['extension']!="php"){
                    continue;
                }
                $cls="\Mantonio84\pymMagicBox\Engines\\".ucfirst(Str::camel($fi['filename']));
                if (class_exists($cls) && is_a($cls,Base::class,true)){                    
                    $this->info("Found class '".class_basename($cls)."'. Discovering...");                    
                    if (is_callable([$cls,"autoDiscovery"])){
                        $data=$cls::autoDiscovery();
                        if (is_array($data) && !empty($data) && isset($data['name'])){
                            $rec=pmbMethod::where("name",$data['name'])->first();
                            if (is_null($rec)){
                                $isNew=true;
                                $rec=new pmbMethod();                                
                            }else{
                                $isNew=false;
                            }
                            $rec->engine=class_basename($cls);
                            $rec->fill($data);
                            $rec->save();
                            $this->info($isNew ? "Created method #".$rec->getKey()."." : "Updated medthod #".$rec->getKey().".");
                        }else{
                            $this->info("No updates needed.");
                        }
                    }else{
                        $this->warn("No autodiscovery found: skipped.");
                    }                    
                }
            }
        }
        $this->info("Operation completed!");
        
    }
}