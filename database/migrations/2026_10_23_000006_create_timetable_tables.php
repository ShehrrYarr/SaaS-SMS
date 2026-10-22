<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who teaches which subject to which class/section in a given year.
        Schema::create('subject_class', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->comment('null = all sections')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('staff_profiles')->nullOnDelete();
            $table->unsignedTinyInteger('periods_per_week')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academic_year_id', 'class_id', 'section_id', 'subject_id'], 'subject_class_allocation_unique');
            $table->index(['school_id', 'teacher_id']);
        });

        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_class_id')->constrained('subject_class')->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week')->comment('ISO: 1 = Monday ... 7 = Sunday');
            $table->unsignedTinyInteger('period_number');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['school_id', 'academic_year_id', 'day_of_week'], 'timetables_day_index');
            $table->index(['classroom_id', 'day_of_week', 'start_time'], 'timetables_room_clash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetables');
        Schema::dropIfExists('subject_class');
    }
};
