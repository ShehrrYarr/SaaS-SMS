<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The class teacher's overall remark for a student in an examination,
     * printed on the report card. Subject remarks live on marks.
     */
    public function up(): void
    {
        Schema::create('exam_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->text('remarks');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['examination_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_remarks');
    }
};
