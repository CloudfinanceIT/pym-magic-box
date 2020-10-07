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
        Schema::create('pmb_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();			
			$table->string("customer_id")->index();
			$table->string("order_ref")->index();
			$table->string("bb_code",16)->nullable();
			$table->unsignedDecimal('amount', 12, 2);
			$table->dateTime("billed_at")->nullable();
			$table->dateTime("confirmed_at")->nullable();
			$table->dateTime("refunded_at")->nullable();
			$table->unsignedBigInteger("performer_id");
			$table->json("other_data");
			$table->foreign("performer_id")->references('id')->on('pmb_performers')->onDelete('cascade');
			
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pmb_orders');
    }
}
