<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A place that sells food and drink: a restaurant, a bar, room service. Its prices are quoted without service charge and tax unless it says
        // otherwise, and its charges follow the scheme of `charge_scope` (FR-FBS-008). Outlets are deactivated, never removed.
        Schema::create('fnb_outlets', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 80);
            $table->string('kind', 14);
            $table->string('charge_scope', 16)->default('fnb');
            $table->boolean('prices_include_charges')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        Schema::create('fnb_tables', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('outlet_id', 26);
            $table->string('code', 8);
            $table->string('area', 40)->nullable();
            $table->unsignedSmallInteger('seats');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['outlet_id', 'code']);
            $table->foreign('outlet_id')->references('id')->on('fnb_outlets')->restrictOnDelete();
        });

        // The menu of an outlet is in categories; a category says which station prepares what is in it, so a ticket reaches the kitchen or the bar.
        Schema::create('fnb_menu_categories', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('outlet_id', 26);
            $table->string('code', 12);
            $table->string('name', 80);
            $table->string('station', 8);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['outlet_id', 'code']);
            $table->foreign('outlet_id')->references('id')->on('fnb_outlets')->restrictOnDelete();
        });

        Schema::create('fnb_menu_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('category_id', 26);
            $table->string('code', 16);
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            $table->bigInteger('price_minor');
            $table->string('station', 8)->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
            $table->foreign('category_id')->references('id')->on('fnb_menu_categories')->restrictOnDelete();
        });

        // A size or a kind of the same dish with its own price. An item that has active variants is ordered with one of them.
        Schema::create('fnb_item_variants', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('item_id', 26);
            $table->string('name', 40);
            $table->bigInteger('price_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(precision: 6);

            $table->unique(['item_id', 'name']);
            $table->foreign('item_id')->references('id')->on('fnb_menu_items')->restrictOnDelete();
        });

        // Add-ons, doneness and the like: a group offers choices, an item takes the groups that apply to it (FR-FBS-011).
        Schema::create('fnb_modifier_groups', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 60);
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        Schema::create('fnb_modifiers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('group_id', 26);
            $table->string('name', 40);
            $table->bigInteger('price_delta_minor')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(precision: 6);

            $table->unique(['group_id', 'name']);
            $table->foreign('group_id')->references('id')->on('fnb_modifier_groups')->restrictOnDelete();
        });

        Schema::create('fnb_item_modifier_groups', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('item_id', 26);
            $table->char('group_id', 26);

            $table->primary(['item_id', 'group_id']);
            $table->foreign('item_id')->references('id')->on('fnb_menu_items')->restrictOnDelete();
            $table->foreign('group_id')->references('id')->on('fnb_modifier_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['fnb_item_modifier_groups', 'fnb_modifiers', 'fnb_modifier_groups', 'fnb_item_variants', 'fnb_menu_items', 'fnb_menu_categories', 'fnb_tables', 'fnb_outlets'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
