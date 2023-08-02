<?php namespace RainLab\User\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class UsersAddTopics extends Migration
{
    public function up()
    {
        Schema::table('users', function($table)
        {
            $table->string('topics')->nullable();
        });
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'topics')) {
            Schema::table('users', function($table)
            {
                $table->dropColumn('topics');
            });
        }
    }
}
