<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->text('comment')->nullable();
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->json('new_breaks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropColumn('new_breaks');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('comment');
        });
    }
};
