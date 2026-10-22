<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_id', 30);
            $table->string('designation', 100)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->date('joining_date')->nullable();
            $table->string('qualification')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('dob')->nullable();
            $table->text('address')->nullable();
            $table->decimal('salary', 12, 2)->default(0);
            $table->text('bank_details')->nullable()->comment('encrypted JSON');
            $table->json('emergency_contact')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'employee_id']);
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('admission_number', 30);
            $table->string('roll_number', 20)->nullable();
            // Current placement; history lives in student_enrollments.
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gender', 10)->nullable();
            $table->date('dob')->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->text('address')->nullable();
            $table->date('admission_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('previous_school')->nullable();
            $table->text('medical_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'admission_number']);
            $table->index(['school_id', 'class_id', 'section_id', 'status'], 'student_profiles_placement_index');
        });

        Schema::create('parent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('occupation')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('parent_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 20)->default('guardian');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['parent_profile_id', 'student_profile_id']);
        });

        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('roll_number', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('enrolled_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['student_profile_id', 'academic_year_id']);
            $table->index(['school_id', 'academic_year_id', 'class_id', 'section_id'], 'student_enrollments_roster_index');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('class_teacher_id')->nullable()->after('classroom_id')->constrained('staff_profiles')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_staff_profile_id')->nullable()->after('campus_id')->constrained('staff_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_staff_profile_id');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_teacher_id');
        });

        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('parent_student');
        Schema::dropIfExists('parent_profiles');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('staff_profiles');
    }
};
