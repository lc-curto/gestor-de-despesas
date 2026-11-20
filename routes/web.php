<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialAccountsPageController;
use App\Http\Controllers\FinancialCardsPageController;
use App\Http\Controllers\FinancialCommitmentsPageController;
use App\Http\Controllers\FinancialCoreController;
use App\Http\Controllers\FinancialDataReviewController;
use App\Http\Controllers\FinancialInvoicesPageController;
use App\Http\Controllers\FinancialRouteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    return $request->user()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:6,1')->name('login.store');
    Route::view('/registo', 'auth.login')->name('register');
    Route::post('/registo', [AccountController::class, 'register'])->middleware('throttle:6,1')->name('register.store');
    Route::view('/password/forgot', 'auth.forgot')->name('password.request');
    Route::post('/password/email', [AccountController::class, 'sendReset'])->middleware('throttle:3,1')->name('password.email');
    Route::view('/password/reset/{token}', 'auth.reset')->name('password.reset');
    Route::post('/password/reset', [AccountController::class, 'reset'])->middleware('throttle:3,1')->name('password.update');
});
Route::middleware(['auth', 'financial.review'])->group(function () {
    Route::get('/revisao-dados', [FinancialDataReviewController::class, 'index'])->name('financial.review.index');
    Route::post('/revisao-dados/etapa', [FinancialDataReviewController::class, 'completeStep'])->name('financial.review.step');
    Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/rota-financeira', FinancialRouteController::class)->name('financial.route');
    Route::get('/contas', FinancialAccountsPageController::class)->name('financial.accounts.page');
    Route::get('/cartoes', [FinancialCardsPageController::class, '__invoke'])->name('financial.cards.page');
    Route::get('/cartoes/faturas', FinancialInvoicesPageController::class)->name('financial.invoices.page');
    Route::get('/cartoes/{id}', [FinancialCardsPageController::class, 'show'])->name('financial.cards.show');
    Route::get('/faturas/{id}', [FinancialInvoicesPageController::class, 'show'])->name('financial.invoices.show');
    Route::get('/parcelamentos', FinancialCommitmentsPageController::class)->name('financial.commitments.page');
    Route::redirect('/compromissos', '/parcelamentos', 301);
    Route::delete('/contas/movimentos/{id}', [FinancialAccountsPageController::class, 'destroyMovement'])->name('financial.accounts.movement.destroy');
    Route::get('/contas/{id}', [FinancialAccountsPageController::class, 'show'])->name('financial.accounts.show');
    Route::get('/dashboard/movimentos', [DashboardController::class, 'paginaMovimentos'])->name('dashboard.movimentos');
    Route::get('/dashboard/movimentos/dados', [DashboardController::class, 'movimentos'])->middleware('throttle:60,1')->name('dashboard.movimentos.data');
    Route::post('/session/activity', fn () => response()->noContent())->name('session.activity');

    // Núcleo financeiro: endpoints JSON, sem alterar a interface existente.
    Route::prefix('api/financial')->name('financial.')->group(function () {
        Route::get('/accounts', [FinancialCoreController::class, 'accounts'])->name('accounts.index');
        Route::post('/accounts', [FinancialCoreController::class, 'storeAccount'])->name('accounts.store');
        Route::put('/accounts/{id}', [FinancialCoreController::class, 'updateAccount'])->name('accounts.update');
        Route::put('/accounts/{id}/settings', [FinancialCoreController::class, 'updateAccountSettings'])->name('accounts.settings');
        Route::put('/accounts/{id}/opening-balance', [FinancialCoreController::class, 'setOpeningBalance'])->name('accounts.opening-balance');
        Route::post('/accounts/{id}/close', [FinancialCoreController::class, 'closeAccount'])->name('accounts.close');
        Route::put('/accounts/{id}/card-settings', [FinancialCoreController::class, 'updateCardSettings'])->name('cards.settings');
        Route::post('/cards/{id}/status', [FinancialCoreController::class, 'setCardStatus'])->name('cards.status');
        Route::post('/cards/{id}/reopen', [FinancialCoreController::class, 'reopenCard'])->name('cards.reopen');
        Route::delete('/cards/{id}', [FinancialCoreController::class, 'deleteUnusedCard'])->name('cards.destroy');
        Route::post('/cards/{id}/pay-opening-debt', [FinancialCoreController::class, 'payOpeningCardDebt'])->name('cards.pay-opening-debt');
        Route::get('/movements', [FinancialCoreController::class, 'movements'])->name('movements.index');
        Route::post('/movements', [FinancialCoreController::class, 'storeMovement'])->name('movements.store');
        Route::post('/commitments', [FinancialCoreController::class, 'storeCommitment'])->name('commitments.store');
        Route::put('/commitments/{id}', [FinancialCoreController::class, 'updateCommitment'])->name('commitments.update');
        Route::post('/commitments/{id}/cancel', [FinancialCoreController::class, 'cancelCommitment'])->name('commitments.cancel');
        Route::post('/commitments/{id}/renegotiate', [FinancialCoreController::class, 'renegotiateCommitment'])->name('commitments.renegotiate');
        Route::post('/commitments/{id}/archive', [FinancialCoreController::class, 'archiveCommitment'])->name('commitments.archive');
        Route::post('/commitments/{id}/restore', [FinancialCoreController::class, 'restoreCommitment'])->name('commitments.restore');
        Route::delete('/commitments/{id}', [FinancialCoreController::class, 'deleteCommitment'])->name('commitments.destroy');
        Route::post('/recurrences', [FinancialCoreController::class, 'storeRecurrence'])->name('recurrences.store');
        Route::patch('/recurrences/{id}/status', [FinancialCoreController::class, 'setRecurrenceStatus'])->name('recurrences.status');
        Route::post('/cards/purchases', [FinancialCoreController::class, 'storeCardPurchase'])->name('cards.purchase');
        Route::get('/invoices', [FinancialCoreController::class, 'invoices'])->name('invoices.index');
        Route::post('/invoices/{id}/pay', [FinancialCoreController::class, 'payInvoice'])->name('invoices.pay');
        Route::post('/invoices/{id}/charges', [FinancialCoreController::class, 'addInvoiceCharge'])->name('invoices.charges.store');
        Route::post('/invoices/{id}/credits', [FinancialCoreController::class, 'addInvoiceCredit'])->name('invoices.credits.store');
        Route::put('/invoices/{id}/movements/{movementId}', [FinancialCoreController::class, 'updateInvoiceComponent'])->name('invoices.movements.update');
        Route::get('/commitments', [FinancialCoreController::class, 'commitments'])->name('commitments.index');
        Route::post('/commitments/{id}/installments/{installmentId}/pay', [FinancialCoreController::class, 'payCommitmentInstallment'])->name('commitments.installments.pay');
        Route::put('/movements/{id}/settle', [FinancialCoreController::class, 'settleMovement'])->name('movements.settle');
        Route::put('/movements/{id}/confirm', [FinancialCoreController::class, 'confirmMovement'])->name('movements.confirm');
        Route::put('/movements/{id}', [FinancialCoreController::class, 'updatePendingMovement'])->name('movements.update');
        Route::delete('/movements/{id}/recurrence-scope', [FinancialCoreController::class, 'destroyRecurringOccurrence'])->name('movements.recurrence.destroy');
        Route::delete('/movements/{id}', [FinancialCoreController::class, 'destroyMovement'])->name('movements.destroy');
    });
    Route::get('/categorias', [CategoriaController::class, 'index'])->name('categorias.index');
    Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
    Route::put('/categorias/{id}', [CategoriaController::class, 'update'])->name('categorias.update');
    Route::patch('/categorias/{id}/estado', [CategoriaController::class, 'toggle'])->name('categorias.toggle');
    Route::delete('/categorias/{id}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');
    foreach (['despesas' => 'despesa', 'receitas' => 'receita'] as $uri => $tipo) {
        Route::get("/$uri", [FinanceController::class, 'index'])->defaults('tipo', $tipo)->name("$uri.index");
        Route::get("/$uri/criar", [FinanceController::class, 'create'])->defaults('tipo', $tipo)->name("$uri.create");
        Route::post("/$uri", [FinanceController::class, 'store'])->defaults('tipo', $tipo)->name("$uri.store");
        Route::get("/$uri/{id}/editar", [FinanceController::class, 'edit'])->defaults('tipo', $tipo)->name("$uri.edit");
        Route::put("/$uri/{id}", [FinanceController::class, 'update'])->defaults('tipo', $tipo)->name("$uri.update");
        Route::delete("/$uri/{id}", [FinanceController::class, 'destroy'])->defaults('tipo',$tipo)->name("$uri.destroy");
    }
});
