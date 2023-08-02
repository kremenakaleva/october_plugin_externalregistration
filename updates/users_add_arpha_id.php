<?php namespace RainLab\User\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class UsersAddArphaId extends Migration
{
    public function up()
    {
        Schema::table('users', function($table)
        {
            $table->integer('arpha_id')->nullable();
        });
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'arpha_id')) {
            Schema::table('users', function($table)
            {
                $table->dropColumn('arpha_id');
            });
        }
    }
}
