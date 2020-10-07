<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PmbCreateLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
			$table->uuid("merchant_id")->index();		
			$table->tinyInteger("level")->unsigned()->default(0)->index(); //["EMERGENCY", "ALERT", "CRITICAL", "ERROR", "WARNING", "NOTICE", "INFO", "DEBUG"]			
			$table->string("session_id",50)->nullable();
			$table->string("method_name",80)->nullable()->index();
			$table->unsignedBigInteger("performer_id")->nullable()->index();		
			$table->unsignedBigInteger("payment_id")->nullable()->index();		
			$table->unsignedBigInteger("alias_id")->nullable()->index();		
			$table->unsignedDecimal('amount', 12, 2)->nullable();
			$table->string("customer_id")->nullable()->index();		
			$table->string("order_ref")->nullable()->index();					
			$table->string("message")->nullable();
			$table->text("details")->nullable();
			
			
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pmb_logs');
    }
}
