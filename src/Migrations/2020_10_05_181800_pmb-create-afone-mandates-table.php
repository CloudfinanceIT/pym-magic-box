<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PmbCreateAfoneMandatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_afone_mandates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();			
            $table->string("iban",32)->index();
            $table->string("rum");            
            $table->dateTime("confirmed_at")->nullable();
            $table->integer("demande_signature_id");
            $table->unsignedBigInteger("performer_id");            
            $table->foreign("performer_id")->references('id')->on('pmb_performers')->onDelete('cascade');            		
            $table->unique(["iban","performer_id"]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pmb_payments');
    }
}
