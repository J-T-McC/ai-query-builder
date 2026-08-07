<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->date('issued_at');
            $table->decimal('total', 12, 2);
            $table->string('status');
            $table->decimal('internal_margin', 12, 2)->default(0);
            $table->text('customer_notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        // The pivot carries attributes of the link itself, which is what makes
        // a pivot worth exposing separately from the tables it joins.
        Schema::create('invoice_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->date('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            // Deliberately not named deleted_at: the compiler must read the
            // column from the model rather than assume Laravel's default.
            $table->softDeletes('archived_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoice_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('products');
    }
};
