<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties Spatie's team column (school_id) and the activity log to schools, and
 * adds the metadata the role matrix needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description')->nullable()->after('guard_name');
            $table->boolean('is_system')->default(false)->after('description')->comment('built-in role: not deletable');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropIndex(['school_id', 'created_at']);
            $table->dropColumn('school_id');
        });

        Schema::table('model_has_permissions', fn (Blueprint $table) => $table->dropForeign(['school_id']));
        Schema::table('model_has_roles', fn (Blueprint $table) => $table->dropForeign(['school_id']));

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn(['description', 'is_system']);
        });
    }
};
