<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('is_active');
            $table->timestamp('locked_until')->nullable()->index()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            $table->text('two_factor_secret')->nullable()->after('password_changed_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        Schema::create('roles', function (Blueprint $table) {
            $this->configureTable($table);

            $table->ulid('id');
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('requires_mfa')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->primary('id');
            $table->unique(['property_id', 'name']);
            $table->unique(['id', 'property_id']);
            $table->index(['property_id', 'is_active']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->string('code', 120)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('role_id');
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['role_id', 'permission_id']);
            $table->foreign(['role_id', 'property_id'])
                ->references(['id', 'property_id'])
                ->on('roles')
                ->cascadeOnDelete();
            $table->index(['property_id', 'permission_id', 'role_id']);
        });

        Schema::create('user_role_assignments', function (Blueprint $table) {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('role_id');
            $table->string('scope_type', 16);
            $table->char('scope_id', 26);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->foreign(['role_id', 'property_id'])
                ->references(['id', 'property_id'])
                ->on('roles')
                ->restrictOnDelete();
            $table->unique(
                ['user_id', 'role_id', 'scope_type', 'scope_id'],
                'ura_user_role_scope_unique',
            );
            $table->index(['user_id', 'property_id', 'is_active']);
            $table->index(
                ['property_id', 'scope_type', 'scope_id', 'user_id'],
                'ura_scope_lookup_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE user_role_assignments
            ADD CONSTRAINT chk_user_role_scope_type
            CHECK (scope_type IN ('property', 'outlet', 'department'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE user_role_assignments
            ADD CONSTRAINT chk_property_scope_id
            CHECK (scope_type <> 'property' OR scope_id = property_id)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['locked_until']);
            $table->dropColumn([
                'is_active',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'password_changed_at',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
