<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->unsignedInteger('max_students')->nullable()->comment('null = unlimited');
            $table->unsignedInteger('max_staff')->nullable()->comment('null = unlimited');
            $table->json('modules')->nullable()->comment('enabled module keys; null = all');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 60)->unique()->comment('URL prefix: /{slug}/admin');
            $table->string('domain')->nullable()->unique()->comment('reserved for custom domains');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('logo')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->json('settings')->nullable()->comment('overrides of config(tenancy.default_settings)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
        Schema::dropIfExists('plans');
    }
};
