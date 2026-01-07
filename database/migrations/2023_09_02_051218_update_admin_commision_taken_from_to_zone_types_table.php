<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateAdminCommisionTakenFromToZoneTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('zone_types')) {
            
            if (!Schema::hasColumn('zone_types', 'admin_commision_taken_from')) {
                Schema::table('zone_types', function (Blueprint $table) {
                   $table->enum('admin_commision_taken_from',['user','driver'])->after('admin_commision_type')->default('user');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('to_zone_types', function (Blueprint $table) {
            //
        });
    }
}
