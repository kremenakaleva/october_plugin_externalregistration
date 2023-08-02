<?php namespace Pensoft\Externalregistration\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * CreateRegistrationsTable Migration
 */
class CreateRegistrationsTable extends Migration
{
    public function up()
    {
        Schema::create('pensoft_externalregistration_registrations', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('pensoft_externalregistration_registrations');
    }
}
