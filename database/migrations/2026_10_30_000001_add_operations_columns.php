<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: library loans and fines, and transport and hostel charges on
 * fee invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_issues', function (Blueprint $table) {
            $table->dropColumn('fine_paid');
        });

        Schema::table('book_issues', function (Blueprint $table) {
            $table->unsignedTinyInteger('renewals')->default(0)->after('due_on');
            $table->foreignId('received_by')->nullable()->after('issued_by')->constrained('users')->nullOnDelete();
            // none | unpaid | paid | waived | invoiced
            $table->string('fine_status', 20)->default('none')->after('fine_amount');
            $table->foreignId('invoice_id')->nullable()->after('fine_status')->comment('fine added to a fee invoice')->constrained()->nullOnDelete();
            $table->timestamp('fine_settled_at')->nullable()->after('invoice_id');
            $table->foreignId('fine_settled_by')->nullable()->after('fine_settled_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable()->comment('"due tomorrow" notice sent');
            $table->timestamp('overdue_notified_at')->nullable();

            $table->index(['school_id', 'user_id', 'status']);
            $table->index(['school_id', 'fine_status']);
        });

        // What an invoice line bills when it isn't a fee structure: a transport
        // assignment, a hostel stay or a library fine.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('billable_type', 50)->nullable()->after('fee_structure_id');
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');

            $table->index(['billable_type', 'billable_id']);
        });

        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->string('floor', 30)->nullable()->after('room_number');
        });

        Schema::table('student_transports', function (Blueprint $table) {
            $table->index(['school_id', 'student_profile_id', 'status']);
        });

        Schema::table('student_hostels', function (Blueprint $table) {
            $table->index(['school_id', 'student_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('student_hostels', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'student_profile_id', 'status']);
        });

        Schema::table('student_transports', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'student_profile_id', 'status']);
        });

        Schema::table('hostel_rooms', function (Blueprint $table) {
            $table->dropColumn('floor');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['billable_type', 'billable_id']);
            $table->dropColumn(['billable_type', 'billable_id']);
        });

        Schema::table('book_issues', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'user_id', 'status']);
            $table->dropIndex(['school_id', 'fine_status']);
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('fine_settled_by');
            $table->dropColumn(['renewals', 'fine_status', 'fine_settled_at', 'reminded_at', 'overdue_notified_at']);
        });

        Schema::table('book_issues', function (Blueprint $table) {
            $table->boolean('fine_paid')->default(false)->after('fine_amount');
        });
    }
};
