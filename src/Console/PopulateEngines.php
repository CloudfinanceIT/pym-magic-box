<?php
namespace Mantonio84\pymMagicBox\Console;

use Illuminate\Console\Command;

class PopulateEngines extends Command
{
    protected $signature = 'pmb:populate-engines';

    protected $description = 'Riempie la tabella pmb_methods a partire dalle classe fisicamente presenti nel pacchetto';

    public function handle()
    {
        
        $this->info('Installed BlogPackage');
    }
}