<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 50)->unique()->index();
            $table->string('name', 500);
            $table->decimal('price', 8, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->text('description')->nullable();
            $table->text('description_format')->nullable();
            $table->string('vendor_code', 50)->nullable()->index();
            $table->string('barcode', 50)->nullable()->index();
            $table->boolean('available')->default(true);
            $table->string('currency', 3)->default('UAH');
            $table->foreignId('brand_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};
