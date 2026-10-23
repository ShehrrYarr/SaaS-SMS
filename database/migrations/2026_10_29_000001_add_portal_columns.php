<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A slot belongs to one class/section: an allocation can cover every
        // section of a class, but each section has its own week.
        Schema::table('timetables', function (Blueprint $table) {
            $table->foreignId('class_id')->after('subject_class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->after('class_id')->constrained()->cascadeOnDelete();

            $table->index(['school_id', 'academic_year_id', 'class_id', 'section_id'], 'timetables_group_index');
        });

        // Homework set on an every-section allocation can still go to one section.
        Schema::table('homeworks', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('subject_class_id')->comment('null = every section of the allocation')->constrained()->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('homeworks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn('published_at');
        });

        Schema::table('timetables', function (Blueprint $table) {
            $table->dropIndex('timetables_group_index');
            $table->dropConstrainedForeignId('section_id');
            $table->dropConstrainedForeignId('class_id');
        });
    }
};
