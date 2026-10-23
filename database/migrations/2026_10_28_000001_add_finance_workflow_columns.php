<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the fee, ledger and payroll workflows need on top of the Phase 2
 * schema: waived late fees and cancellation reasons on invoices, the system
 * categories that fee payments and salaries post to, and a link from an
 * expense back to the payroll record that created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // A waived late fee is never charged again on that invoice.
            $table->timestamp('late_fee_waived_at')->nullable()->after('late_fee_amount');
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->string('cancel_reason')->nullable()->after('cancelled_at');
        });

        Schema::table('finance_categories', function (Blueprint $table) {
            // "fees" (income from fee payments) and "salaries" (paid payroll):
            // posted to automatically, so they can be renamed but not removed.
            $table->string('system_key', 20)->nullable()->after('type');

            $table->unique(['school_id', 'system_key']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->nullableMorphs('source', 'expenses_source_index'); // e.g. a paid PayrollRecord
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropMorphs('source', 'expenses_source_index');
        });

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'system_key']);
            $table->dropColumn('system_key');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['late_fee_waived_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
