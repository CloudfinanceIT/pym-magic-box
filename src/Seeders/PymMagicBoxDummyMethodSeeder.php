<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use DB;

class PymMagicBoxDummyMethodSeeder extends Seeder
{
    
   public function run()
    {
        $merchant_id = (string) Str::uuid();
        $now=date("Y-m-d H:i:s");
        DB::table("pmb_performers")->where("method_id",1)->delete();
        DB::table("pmb_methods")->where("id",1)->delete();        
        DB::table("pmb_methods")->insert([
            "id" => 1,
            "created_at" => $now,
            "updated_at" => $now,
            "name" => "dummy",
            "engine" => "Dummy",
            "auto" => 1
        ]);
        DB::table("pmb_performers")->insert([
            "created_at" => $now,
            "updated_at" => $now,
            "merchant_id" => $merchant_id,
            "method_id" => 1,            
            "enabled" => 1
        ]);
        $this->command->getOutput()->writeln("PymMagicBox dummy method created with merchant_id <info>$merchant_id</info>");
    }
}
