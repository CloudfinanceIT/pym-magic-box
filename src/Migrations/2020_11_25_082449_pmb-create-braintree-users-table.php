<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PmbCreateBraintreeUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_braintree_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->unsignedBigInteger("performer_id");            
            $table->string("pmb_customer_id")->index();
            $table->string("bt_customer_id")->index();
            $table->string("bt_user",40)->index();
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
        Schema::dropIfExists('pmb_braintree_users');
    }
}
