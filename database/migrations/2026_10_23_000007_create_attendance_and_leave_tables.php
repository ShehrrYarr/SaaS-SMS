<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->string('applies_to', 10)->default('staff');
            $table->unsignedSmallInteger('days_per_year')->nullable()->comment('null = no quota');
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        // Per-staff yearly quota; defaults from leave_types.days_per_year, editable by HR.
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->decimal('allocated', 5, 1)->default(0);
            $table->decimal('used', 5, 1)->default(0);
            $table->timestamps();

            $table->unique(['staff_profile_id', 'leave_type_id', 'academic_year_id'], 'leave_balances_unique');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->morphs('applicant'); // staff_profile | student_profile
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1);
            $table->text('reason');
            $table->string('attachment')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->comment('staff themself, or the parent')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'status']);
        });

        Schema::create('student_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('status', 10);
            $table->string('remarks')->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_profile_id', 'date']);
            $table->index(['school_id', 'class_id', 'section_id', 'date'], 'student_attendances_roster_index');
            $table->index(['school_id', 'date', 'status']);
        });

        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 10);
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['staff_profile_id', 'date']);
            $table->index(['school_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendances');
        Schema::dropIfExists('student_attendances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
