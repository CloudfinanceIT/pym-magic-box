<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PmbCreateAliasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_aliases', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
			$table->string("customer_id")->index();		
			$table->string("name");
			$table->dateTime("expires_at")->nullable();
			$table->json("adata")->nullable();
			$table->unsignedBigInteger("performer_id");
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
        Schema::dropIfExists('pmb_aliases');
    }
}
