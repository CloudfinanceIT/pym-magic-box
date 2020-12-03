<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PmbCreatePaymentsRefundsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string("transaction_ref")->nullable();
            $table->text("details")->nullable();
            $table->unsignedDecimal('amount', 12, 2);
            $table->unsignedBigInteger("payment_id");            
			$table->string("reason")->nullable();
            $table->foreign("payment_id")->references('id')->on('pmb_payments')->onDelete('cascade');    
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pmb_refunds');
    }
}
