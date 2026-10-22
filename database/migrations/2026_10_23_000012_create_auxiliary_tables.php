<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library, transport, hostel and notices. Screens for the first three arrive
 * in Phase 10; notices are used by every portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Library
        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('isbn', 20)->nullable();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('publisher')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('edition', 50)->nullable();
            $table->string('rack_number', 30)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('available_quantity')->default(1);
            $table->decimal('price', 12, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'title']);
            $table->index(['school_id', 'isbn']);
        });

        Schema::create('book_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_book_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->comment('borrower: student or staff')->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('returned_on')->nullable();
            $table->decimal('fine_amount', 12, 2)->default(0);
            $table->boolean('fine_paid')->default(false);
            $table->string('status', 20)->default('issued');
            $table->string('remarks')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'status', 'due_on']);
        });

        // Transport
        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('vehicle_number', 30);
            $table->string('model', 100)->nullable();
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->string('driver_name', 100)->nullable();
            $table->string('driver_phone', 30)->nullable();
            $table->string('driver_license', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'vehicle_number']);
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('start_point')->nullable();
            $table->string('end_point')->nullable();
            $table->json('stops')->nullable();
            $table->decimal('fare', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('student_transports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained()->restrictOnDelete();
            $table->foreignId('transport_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pickup_point')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['school_id', 'transport_route_id', 'status']);
        });

        // Hostel
        Schema::create('hostel_buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warden_staff_profile_id')->nullable()->constrained('staff_profiles')->nullOnDelete();
            $table->string('name', 100);
            $table->string('type', 10)->default('mixed');
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_building_id')->constrained()->cascadeOnDelete();
            $table->string('room_number', 30);
            $table->string('room_type', 50)->nullable();
            $table->unsignedTinyInteger('capacity')->default(1);
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hostel_building_id', 'room_number']);
        });

        Schema::create('student_hostels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_room_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bed_number', 10)->nullable();
            $table->date('check_in');
            $table->date('check_out')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['school_id', 'hostel_room_id', 'status']);
        });

        // Notices
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->string('target_audience', 20)->default('all');
            $table->foreignId('class_id')->nullable()->comment('narrow to one class')->constrained('classes')->nullOnDelete();
            $table->string('attachment')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'target_audience', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::dropIfExists('student_hostels');
        Schema::dropIfExists('hostel_rooms');
        Schema::dropIfExists('hostel_buildings');
        Schema::dropIfExists('student_transports');
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('transport_vehicles');
        Schema::dropIfExists('book_issues');
        Schema::dropIfExists('library_books');
    }
};
