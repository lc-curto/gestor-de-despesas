<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_financeiras', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name', 120);
            $table->string('type', 20); // bank, cash, credit_card, legacy
            $table->string('currency', 3);
            $table->decimal('opening_balance', 12, 2)->nullable();
            $table->date('opening_balance_date')->nullable();
            $table->boolean('is_operational')->default(true);
            $table->string('status', 20)->default('setup_pending');
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'contas_user_id_id_unique');
            $table->index(['user_id', 'is_operational', 'status'], 'contas_user_operational_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('faturas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('account_id');
            $table->date('reference_month');
            $table->date('closing_date')->nullable();
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'faturas_user_id_id_unique');
            $table->unique(['user_id', 'account_id', 'reference_month'], 'faturas_user_account_month_unique');
            $table->foreign(['user_id', 'account_id'], 'faturas_user_account_fk')
                ->references(['user_id', 'id'])->on('contas_financeiras')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('recorrencias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type', 10); // income, expense
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->uuid('category_id')->nullable();
            $table->uuid('account_id')->nullable();
            $table->string('frequency', 20); // monthly, yearly, custom
            $table->unsignedSmallInteger('interval_value')->default(1);
            $table->date('next_occurrence');
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'recorrencias_user_id_id_unique');
            $table->foreign(['user_id', 'category_id'], 'recorrencias_user_category_fk')
                ->references(['user_id', 'id'])->on('categorias')->restrictOnDelete();
            $table->foreign(['user_id', 'account_id'], 'recorrencias_user_account_fk')
                ->references(['user_id', 'id'])->on('contas_financeiras')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('dividas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name', 160);
            $table->string('type', 30); // credit_card, loan, purchase, personal
            $table->uuid('account_id')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'dividas_user_id_id_unique');
            $table->foreign(['user_id', 'account_id'], 'dividas_user_account_fk')
                ->references(['user_id', 'id'])->on('contas_financeiras')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('parcelas_divida', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('debt_id');
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('principal_amount', 12, 2)->nullable();
            $table->decimal('interest_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'parcelas_user_id_id_unique');
            $table->unique(['user_id', 'debt_id', 'installment_number'], 'parcelas_user_debt_number_unique');
            $table->foreign(['user_id', 'debt_id'], 'parcelas_user_debt_fk')
                ->references(['user_id', 'id'])->on('dividas')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('movimentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type', 20); // income, expense, transfer, adjustment
            $table->string('status', 20)->default('settled'); // pending, settled, void
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->date('movement_date');
            $table->uuid('source_account_id')->nullable();
            $table->uuid('destination_account_id')->nullable();
            $table->uuid('category_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->uuid('recurrence_id')->nullable();
            $table->uuid('debt_id')->nullable();
            $table->uuid('installment_id')->nullable();
            $table->string('adjustment_direction', 10)->nullable(); // increase, decrease
            $table->boolean('is_historical')->default(false);
            $table->boolean('requires_account_assignment')->default(false);
            $table->string('legacy_type', 20)->nullable();
            $table->uuid('legacy_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'id'], 'movimentos_user_id_id_unique');
            $table->unique(['user_id', 'legacy_type', 'legacy_id'], 'movimentos_user_legacy_unique');
            $table->index(['user_id', 'movement_date', 'status'], 'movimentos_user_date_status_index');
            $table->index(['user_id', 'is_historical', 'requires_account_assignment'], 'movimentos_user_history_index');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign(['user_id', 'source_account_id'], 'movimentos_user_source_account_fk')
                ->references(['user_id', 'id'])->on('contas_financeiras')->restrictOnDelete();
            $table->foreign(['user_id', 'destination_account_id'], 'movimentos_user_destination_account_fk')
                ->references(['user_id', 'id'])->on('contas_financeiras')->restrictOnDelete();
            $table->foreign(['user_id', 'category_id'], 'movimentos_user_category_fk')
                ->references(['user_id', 'id'])->on('categorias')->restrictOnDelete();
            $table->foreign(['user_id', 'invoice_id'], 'movimentos_user_invoice_fk')
                ->references(['user_id', 'id'])->on('faturas')->restrictOnDelete();
            $table->foreign(['user_id', 'recurrence_id'], 'movimentos_user_recurrence_fk')
                ->references(['user_id', 'id'])->on('recorrencias')->restrictOnDelete();
            $table->foreign(['user_id', 'debt_id'], 'movimentos_user_debt_fk')
                ->references(['user_id', 'id'])->on('dividas')->restrictOnDelete();
            $table->foreign(['user_id', 'installment_id'], 'movimentos_user_installment_fk')
                ->references(['user_id', 'id'])->on('parcelas_divida')->restrictOnDelete();
        });

        Schema::create('historico_movimentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('movement_id');
            $table->uuid('changed_by')->nullable();
            $table->string('action', 30);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['user_id', 'movement_id'], 'historico_user_movement_fk')
                ->references(['user_id', 'id'])->on('movimentos')->restrictOnDelete();
            $table->foreign(['user_id', 'changed_by'], 'historico_user_actor_fk')
                ->references(['user_id', 'id'])->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'movement_id', 'created_at'], 'historico_user_movement_date_index');
        });

        DB::statement("ALTER TABLE contas_financeiras ADD CONSTRAINT contas_opening_balance_nonnegative CHECK (opening_balance IS NULL OR opening_balance >= 0)");
        DB::statement("ALTER TABLE movimentos ADD CONSTRAINT movimentos_amount_positive CHECK (amount > 0)");
        DB::statement("ALTER TABLE dividas ADD CONSTRAINT dividas_total_positive CHECK (total_amount > 0)");
        DB::statement("ALTER TABLE parcelas_divida ADD CONSTRAINT parcelas_amount_positive CHECK (amount > 0)");
        DB::statement("ALTER TABLE parcelas_divida ADD CONSTRAINT parcelas_paid_valid CHECK (paid_amount >= 0 AND paid_amount <= amount)");
        DB::statement("ALTER TABLE parcelas_divida ADD CONSTRAINT parcelas_interest_nonnegative CHECK (interest_amount >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_movimentos');
        Schema::dropIfExists('movimentos');
        Schema::dropIfExists('parcelas_divida');
        Schema::dropIfExists('dividas');
        Schema::dropIfExists('recorrencias');
        Schema::dropIfExists('faturas');
        Schema::dropIfExists('contas_financeiras');
    }
};
