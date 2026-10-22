<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('grade_scale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_scale_id')->constrained()->cascadeOnDelete();
            $table->string('grade', 5);
            $table->decimal('min_percentage', 5, 2);
            $table->decimal('max_percentage', 5, 2);
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->string('remark', 100)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['grade_scale_id', 'grade']);
        });

        Schema::create('examinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_scale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('results_published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'academic_year_id', 'status']);
        });

        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('max_marks', 6, 2);
            $table->decimal('passing_marks', 6, 2);
            $table->date('exam_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['examination_id', 'class_id', 'subject_id']);
        });

        Schema::create('marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->decimal('marks_obtained', 6, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->string('grade', 5)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_schedule_id', 'student_profile_id']);
            $table->index(['school_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marks');
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('examinations');
        Schema::dropIfExists('grade_scale_items');
        Schema::dropIfExists('grade_scales');
    }
};
