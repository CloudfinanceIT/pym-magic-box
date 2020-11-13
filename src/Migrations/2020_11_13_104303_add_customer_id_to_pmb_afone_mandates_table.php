<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCustomerIdToPmbAfoneMandatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pmb_afone_mandates', function (Blueprint $table) {
            $table->string("customer_id")->nullable()->index();
        });
		DB::transaction(function (){
			$tofill=DB::table("pmb_afone_mandates")->whereNull("customer_id")->get();
			foreach ($tofill as $rec){
				$py=DB::table("pmb_payments")->where("performer_id",$rec->performer_id)->where("transaction_ref",$rec->first_transaction_ref)->whereNotNull("customer_id")->first();
				if ($py){
					DB::table("pmb_afone_mandates")->where("id",$rec->id)->update(["customer_id" => $py->customer_id]);
				}else{
					DB::table("pmb_afone_mandates")->where("id",$rec->id)->delete();
				}
			}
		});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pmb_afone_mandates', function (Blueprint $table) {
            $table->dropColumn("customer_id");
        });
    }
}
