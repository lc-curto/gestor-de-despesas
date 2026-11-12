<?php
use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;
Route::get('/',fn()=>auth()->check()?redirect()->route('dashboard'):redirect()->route('login'));
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
 Route::get('/dashboard/movimentos',[DashboardController::class,'movimentos'])->middleware('throttle:60,1')->name('dashboard.movimentos');
 Route::post('/session/activity',fn()=>response()->noContent())->name('session.activity');\n\n // Núcleo financeiro: endpoints JSON, sem alterar a interface existente.\n Route::prefix('api/financial')->name('financial.')->group(function () {\n  Route::get('/accounts', [FinancialCoreController::class, 'accounts'])->name('accounts.index');\n  Route::post('/accounts', [FinancialCoreController::class, 'storeAccount'])->name('accounts.store');\n  Route::put('/accounts/{id}/opening-balance', [FinancialCoreController::class, 'setOpeningBalance'])->name('accounts.opening-balance');\n  Route::post('/accounts/{id}/close', [FinancialCoreController::class, 'closeAccount'])->name('accounts.close');\n  Route::get('/movements', [FinancialCoreController::class, 'movements'])->name('movements.index');\n  Route::post('/movements', [FinancialCoreController::class, 'storeMovement'])->name('movements.store');\n  Route::put('/movements/{id}/settle', [FinancialCoreController::class, 'settleMovement'])->name('movements.settle');\n  Route::put('/movements/{id}', [FinancialCoreController::class, 'updatePendingMovement'])->name('movements.update');\n  Route::put('/movements/{id}/void', [FinancialCoreController::class, 'voidMovement'])->name('movements.void');\n });
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