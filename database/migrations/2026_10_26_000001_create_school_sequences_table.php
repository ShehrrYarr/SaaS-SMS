<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-school counters behind generated numbers (admission numbers,
     * employee ids, later invoices and receipts). A row is locked while the
     * next number is taken, so two admissions never get the same one.
     */
    public function up(): void
    {
        Schema::create('school_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_sequences');
    }
};
