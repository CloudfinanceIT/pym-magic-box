<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PmbCreatePaymentsTable extends Migration
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
			$table->string("first_transaction_ref")->nullable();
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
