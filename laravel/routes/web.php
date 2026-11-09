<?php
use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;
Route::get('/',fn()=>auth()->check()?redirect()->route('dashboard'):redirect()->route('login'));
Route::middleware('guest')->group(function(){
 Route::view('/login','auth.login')->name('login');
 Route::post('/login',[AccountController::class,'login'])->middleware('throttle:6,1')->name('login.store');
 Route::view('/registo','auth.register')->name('register');
 Route::post('/registo',[AccountController::class,'register'])->middleware('throttle:6,1')->name('register.store');
 Route::view('/password/forgot','auth.forgot')->name('password.request');
 Route::post('/password/email',[AccountController::class,'sendReset'])->middleware('throttle:3,1')->name('password.email');
 Route::view('/password/reset/{token}','auth.reset')->name('password.reset');
 Route::post('/password/reset',[AccountController::class,'reset'])->middleware('throttle:3,1')->name('password.update');
});
Route::middleware('auth')->group(function(){
 Route::post('/logout',[AccountController::class,'logout'])->name('logout');
 Route::get('/dashboard',DashboardController::class)->name('dashboard');
 Route::post('/session/activity',fn()=>response()->noContent())->name('session.activity');
 foreach(['despesas'=>'despesa','receitas'=>'receita'] as $uri=>$tipo){
  Route::get("/$uri",[FinanceController::class,'index'])->defaults('tipo',$tipo)->name("$uri.index");
  Route::get("/$uri/criar",[FinanceController::class,'create'])->defaults('tipo',$tipo)->name("$uri.create");
  Route::post("/$uri",[FinanceController::class,'store'])->defaults('tipo',$tipo)->name("$uri.store");
  Route::get("/$uri/{id}/editar",[FinanceController::class,'edit'])->defaults('tipo',$tipo)->name("$uri.edit");
  Route::put("/$uri/{id}",[FinanceController::class,'update'])->defaults('tipo',$tipo)->name("$uri.update");
  Route::delete("/$uri/{id}",[FinanceController::class,'destroy'])->defaults('tipo',$tipo)->name("$uri.destroy");
 }
});