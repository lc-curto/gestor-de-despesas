<?php
use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialCoreController;
use App\Http\Controllers\FinancialAccountsPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
Route::get('/', function (Request $request) {
    return $request->user()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});
Route::middleware('guest')->group(function(){
 Route::view('/login','auth.login')->name('login');
 Route::post('/login',[AccountController::class,'login'])->middleware('throttle:6,1')->name('login.store');
 Route::view('/registo','auth.login')->name('register');
 Route::post('/registo',[AccountController::class,'register'])->middleware('throttle:6,1')->name('register.store');
 Route::view('/password/forgot','auth.forgot')->name('password.request');
 Route::post('/password/email',[AccountController::class,'sendReset'])->middleware('throttle:3,1')->name('password.email');
 Route::view('/password/reset/{token}','auth.reset')->name('password.reset');
 Route::post('/password/reset',[AccountController::class,'reset'])->middleware('throttle:3,1')->name('password.update');
});
Route::middleware('auth')->group(function(){
 Route::post('/logout',[AccountController::class,'logout'])->name('logout');
 Route::get('/dashboard',DashboardController::class)->name('dashboard');
 Route::get('/contas', FinancialAccountsPageController::class)->name('financial.accounts.page');
 Route::delete('/contas/movimentos/{id}', [FinancialAccountsPageController::class, 'destroyMovement'])->name('financial.accounts.movement.destroy');
 Route::post('/contas/movimentos/{id}/estornar', [FinancialAccountsPageController::class, 'reverseMovement'])->name('financial.accounts.movement.reverse');
 Route::get('/contas/{id}', [FinancialAccountsPageController::class, 'show'])->name('financial.accounts.show');
 Route::get('/dashboard/movimentos',[DashboardController::class,'movimentos'])->middleware('throttle:60,1')->name('dashboard.movimentos');
 Route::post('/session/activity',fn()=>response()->noContent())->name('session.activity');

 // Núcleo financeiro: endpoints JSON, sem alterar a interface existente.
 Route::prefix('api/financial')->name('financial.')->group(function () {
  Route::get('/accounts', [FinancialCoreController::class, 'accounts'])->name('accounts.index');
  Route::post('/accounts', [FinancialCoreController::class, 'storeAccount'])->name('accounts.store');
  Route::put('/accounts/{id}', [FinancialCoreController::class, 'updateAccount'])->name('accounts.update');
  Route::put('/accounts/{id}/settings', [FinancialCoreController::class, 'updateAccountSettings'])->name('accounts.settings');
  Route::put('/accounts/{id}/opening-balance', [FinancialCoreController::class, 'setOpeningBalance'])->name('accounts.opening-balance');
  Route::post('/accounts/{id}/close', [FinancialCoreController::class, 'closeAccount'])->name('accounts.close');
  Route::get('/movements', [FinancialCoreController::class, 'movements'])->name('movements.index');
  Route::post('/movements', [FinancialCoreController::class, 'storeMovement'])->name('movements.store');
  Route::post('/commitments', [FinancialCoreController::class, 'storeCommitment'])->name('commitments.store');
  Route::put('/movements/{id}/settle', [FinancialCoreController::class, 'settleMovement'])->name('movements.settle');
  Route::put('/movements/{id}', [FinancialCoreController::class, 'updatePendingMovement'])->name('movements.update');
  Route::put('/movements/{id}/void', [FinancialCoreController::class, 'voidMovement'])->name('movements.void');
  Route::post('/movements/{id}/reverse', [FinancialCoreController::class, 'reverseMovement'])->name('movements.reverse');
  Route::delete('/movements/{id}', [FinancialCoreController::class, 'destroyMovement'])->name('movements.destroy');
 });
 Route::get('/categorias',[CategoriaController::class,'index'])->name('categorias.index');
 Route::post('/categorias',[CategoriaController::class,'store'])->name('categorias.store');
 Route::put('/categorias/{id}',[CategoriaController::class,'update'])->name('categorias.update');
 Route::patch('/categorias/{id}/estado',[CategoriaController::class,'toggle'])->name('categorias.toggle');
 Route::delete('/categorias/{id}',[CategoriaController::class,'destroy'])->name('categorias.destroy');
 foreach(['despesas'=>'despesa','receitas'=>'receita'] as $uri=>$tipo){
  Route::get("/$uri",[FinanceController::class,'index'])->defaults('tipo',$tipo)->name("$uri.index");
  Route::get("/$uri/criar",[FinanceController::class,'create'])->defaults('tipo',$tipo)->name("$uri.create");
  Route::post("/$uri",[FinanceController::class,'store'])->defaults('tipo',$tipo)->name("$uri.store");
  Route::get("/$uri/{id}/editar",[FinanceController::class,'edit'])->defaults('tipo',$tipo)->name("$uri.edit");
  Route::put("/$uri/{id}",[FinanceController::class,'update'])->defaults('tipo',$tipo)->name("$uri.update");
  Route::delete("/$uri/{id}",[FinanceController::class,'destroy'])->defaults('tipo',$tipo)->name("$uri.destroy");
 }
});
