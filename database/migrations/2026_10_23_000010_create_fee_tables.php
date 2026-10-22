<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_id')->nullable()->comment('null = every class')->constrained('classes')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('frequency', 20)->default('monthly');
            $table->unsignedTinyInteger('due_day')->nullable()->comment('day of month for recurring fees');
            $table->date('due_date')->nullable()->comment('for one-time fees');
            // Late fee: null type = use the school default from settings.
            $table->string('late_fee_type', 20)->nullable();
            $table->decimal('late_fee_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('late_fee_grace_days')->nullable();
            $table->decimal('late_fee_max', 12, 2)->nullable();
            $table->boolean('allow_discounts')->default(true);
            $table->boolean('allow_sibling_discount')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'class_id', 'is_active']);
        });

        // Discount & scholarship definitions, assigned to students below.
        Schema::create('fee_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->comment('null = any fee that allows discounts')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->string('type', 20)->default('percentage');
            $table->decimal('value', 12, 2);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('student_fee_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_discount_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_profile_id', 'fee_discount_id']);
        });

        // "2nd child gets 10%, 3rd and later get 15%". The highest position at or
        // below a child's position applies.
        Schema::create('sibling_discount_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->comment('null = any fee that allows sibling discount')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('child_position')->comment('2 = second child');
            $table->string('type', 20)->default('percentage');
            $table->decimal('value', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'fee_structure_id', 'child_position'], 'sibling_discount_rules_unique');
        });

        Schema::create('invoice_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->char('billing_period', 7)->nullable()->comment('YYYY-MM');
            $table->json('filters')->nullable()->comment('classes / fee structures included');
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_students')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('invoices_created')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->comment('set when the invoice is for a single fee')->constrained()->nullOnDelete();
            $table->string('invoice_number', 40);
            $table->char('billing_period', 7)->nullable()->comment('YYYY-MM');
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('late_fee_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'invoice_number']);
            $table->index(['school_id', 'status', 'due_date']);
            $table->index(['student_profile_id', 'billing_period']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->json('discount_breakdown')->nullable()->comment('which discounts / sibling rules applied');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 40);
            $table->decimal('amount_paid', 12, 2);
            $table->string('payment_method', 20);
            $table->string('transaction_id')->nullable();
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            // Payments are voided, never edited: the receipt trail stays intact.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'receipt_number']);
            $table->index(['school_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_batches');
        Schema::dropIfExists('sibling_discount_rules');
        Schema::dropIfExists('student_fee_discounts');
        Schema::dropIfExists('fee_discounts');
        Schema::dropIfExists('fee_structures');
    }
};
