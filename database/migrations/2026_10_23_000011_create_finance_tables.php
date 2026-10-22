<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->comment('income | expense');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'type', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('payment_method', 20)->nullable();
            $table->string('reference')->nullable();
            $table->string('proof_receipt')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'expense_date']);
        });

        Schema::create('income_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_category_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('source'); // e.g. an auto-posted fee Payment
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('income_date');
            $table->string('payment_method', 20)->nullable();
            $table->string('reference')->nullable();
            $table->string('proof_receipt')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'income_date']);
        });

        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->char('month_year', 7)->comment('YYYY-MM');
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('bonuses', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2);
            $table->json('breakdown')->nullable()->comment('itemised allowances & deductions');
            $table->unsignedTinyInteger('working_days')->nullable();
            $table->decimal('present_days', 4, 1)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('paid_on')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['staff_profile_id', 'month_year']);
            $table->index(['school_id', 'month_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('income_ledgers');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('finance_categories');
    }
};
