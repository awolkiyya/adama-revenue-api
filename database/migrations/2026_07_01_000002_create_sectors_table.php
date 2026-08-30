<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {


            /**
             * 🧠 UUID Primary Key
             */
            $table->uuid('id')->primary();



            /**
             * 🧠 Cluster relation
             *
             * clusters.id = UUID
             */
            $table->uuid('cluster_id')
                ->index();


            $table->foreign('cluster_id')
                ->references('id')
                ->on('clusters')
                ->cascadeOnDelete();



            /**
             * 🧠 Sector identity
             */
            $table->string('name');


            $table->string('code')
                ->nullable()
                ->unique();


            $table->text('description')
                ->nullable();



            /**
             * 🧠 Contact information
             */
            $table->string('phone')
                ->nullable();


            $table->string('email')
                ->nullable();



            /**
             * 🧠 Status
             */
            $table->boolean('is_active')
                ->default(true)
                ->index();



            /**
             * 🧠 Audit fields
             *
             * users.id = UUID
             */
            // $table->uuid('created_by')
            //     ->nullable();


            // $table->uuid('updated_by')
            //     ->nullable();



            // $table->foreign('created_by')
            //     ->references('id')
            //     ->on('users')
            //     ->nullOnDelete();


            // $table->foreign('updated_by')
            //     ->references('id')
            //     ->on('users')
            //     ->nullOnDelete();



            $table->timestamps();


            $table->softDeletes();



            /**
             * 🧠 Prevent duplicate sectors in same cluster
             */
            $table->unique(
                [
                    'cluster_id',
                    'name'
                ],
                'unique_sector_cluster_name'
            );


            $table->index('name');

        });
    }


    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }

};