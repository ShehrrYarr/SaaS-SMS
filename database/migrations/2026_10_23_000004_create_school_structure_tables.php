<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_main')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('room_number', 30);
            $table->string('name', 100)->nullable();
            $table->string('building', 100)->nullable();
            $table->string('floor', 20)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('type', 20)->default('classroom');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'campus_id', 'room_number']);
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->string('name', 50)->comment('e.g. Grade 10');
            $table->string('code', 20)->nullable();
            $table->unsignedTinyInteger('numeric_level')->nullable()->comment('orders classes and drives promotion');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'campus_id', 'name']);
            $table->index(['school_id', 'numeric_level']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 30)->comment('e.g. A');
            $table->unsignedSmallInteger('capacity')->nullable();
            // class_teacher_id is added with staff_profiles.
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('type', 20)->default('theory');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('campuses');
    }
};
