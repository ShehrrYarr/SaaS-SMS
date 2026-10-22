<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homeworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_class_id')->constrained('subject_class')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('attachment')->nullable();
            $table->date('assigned_on');
            $table->dateTime('due_at');
            $table->decimal('max_marks', 6, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'status', 'due_at']);
        });

        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('homework_id')->constrained('homeworks')->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->text('content')->nullable();
            $table->string('attachment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->decimal('marks', 6, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['homework_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_submissions');
        Schema::dropIfExists('homeworks');
    }
};
