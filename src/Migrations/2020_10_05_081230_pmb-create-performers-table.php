<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PmbCreatePerformersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pmb_performers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->uuid("merchant_id")->index();
            $table->string("app_env",30)->nullable()->index();
            $table->integer("method_id")->unsigned();
            $table->json("credentials")->nullable();
            $table->boolean("enabled")->default(true);			
            $table->foreign('method_id')->references('id')->on('pmb_methods')->onDelete('cascade');
            $table->unique(["merchant_id","method_id"]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pmb_performers');
    }
}
