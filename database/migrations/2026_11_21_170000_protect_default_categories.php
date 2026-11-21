<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('categorias', function (Blueprint $table): void {
   $table->boolean('is_system')->default(false)->after('is_active');
   $table->boolean('show_in_management')->default(true)->after('is_system');
  });
  $defaults = [
   'despesa' => ['casa','alimentacao','transportes','saude','lazer','compras','educacao','outros'],
   'receita' => ['salario','vendas','trabalho_extra','investimentos','reembolsos','outros'],
  ];
  foreach ($defaults as $type => $slugs) {
   DB::table('categorias')->where('type',$type)->whereIn('slug',$slugs)->update(['is_system'=>true,'show_in_management'=>true]);
  }
  DB::table('categorias')->whereIn('slug',['credito-de-compra','devolucao-de-compra','juros-fatura','comissao-fatura','liquidacao-fatura','transferencia-entre-contas','prestacao-parcelamento'])->update(['is_system'=>true,'show_in_management'=>false]);
 }
 public function down(): void {
  Schema::table('categorias', function (Blueprint $table): void {$table->dropColumn(['show_in_management','is_system']);});
 }
};