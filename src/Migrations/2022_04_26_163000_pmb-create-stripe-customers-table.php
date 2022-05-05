<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PmbCreateStripeCustomersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_stripe_customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            $table->unsignedBigInteger('performer_id');            
            $table->foreign('performer_id')->references('id')->on('pmb_performers')->onDelete('cascade');
            
            $table->string('pmb_customer_id', 255)->index();
            $table->string('stripe_customer_id', 255)->index();
                        
            $table->timestamps();
            $table->softDeletes();
        });
    }
    

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pmb_stripe_customers');
    }
}
