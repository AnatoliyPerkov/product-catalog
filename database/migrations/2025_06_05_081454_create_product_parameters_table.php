<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('product_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('parameter_id')->constrained()->onDelete('cascade');
            $table->string('value', 255);
            $table->timestamps();

            $table->index('value');
            $table->index(['parameter_id', 'value']);
            $table->unique(['product_id', 'parameter_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_parameters');
    }
};
