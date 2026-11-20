<div class="modal fade" id="movementEntryModal" tabindex="-1" aria-labelledby="recordTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered movement-entry-dialog">
                <div class="modal-content border-0 bg-transparent">
                    <article id="movementEntryCard" class="card border-0 shadow-sm rounded-4 movement-editor-card">
                        <div class="card-body p-3 p-lg-4">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <i data-lucide="plus" class="text-primary" aria-hidden="true"></i>
                                <h2 id="recordTitle" class="h5 fw-bold mb-0">Novo movimento</h2>
                            </div>

                            <div class="btn-group w-100 record-type-buttons mb-3" role="group" aria-label="Tipo de movimento">
                                <button type="button" class="btn btn-danger active" id="expenseTypeButton" aria-pressed="true" aria-controls="recordEditorPanel">
                                    <i data-lucide="arrow-down-right" class="me-1" aria-hidden="true"></i>Despesa
                                </button>
                                <button type="button" class="btn btn-outline-success" id="incomeTypeButton" aria-pressed="false" aria-controls="recordEditorPanel">
                                    <i data-lucide="arrow-up-right" class="me-1" aria-hidden="true"></i>Receita
                                </button>
                            </div>

                            @if ($errors->any())
                                <div class="alert alert-danger small"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                            @endif

                            <div id="recordEditorPanel">
                                <form id="dashboardMovementForm" method="POST" action="{{ route('despesas.store') }}">
                                    @csrf
                                    <input type="hidden" name="_method" id="movementMethod" value="POST">
                                    <input type="hidden" name="payment_condition" id="movementPaymentCondition" value="cash">
                                    <input type="hidden" name="payment_method" id="movementPlannedPaymentMethod" value="">
                                    <input type="hidden" name="expense_behavior" id="movementExpenseBehavior" value="variable">
                                    <input type="hidden" name="due_date" id="dashboardDueDate" value="">
                                    <div id="movementFormError" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

                                    <div class="mb-3">
                                        <label for="movementAmount" class="form-label fw-semibold">Valor</label>
                                        <div class="input-group input-group-lg">
                                            <input type="text" inputmode="decimal" class="form-control fw-semibold" id="movementAmount" name="amount" placeholder="0,00" value="{{ old('amount') }}" required>
                                            <span class="input-group-text" id="amountCurrencySymbol">{{ $simbolos[$moeda] ?? $moeda }}</span>
                                        </div>
                                        
                                    </div>

                                    <div class="mb-3">
                                        <label for="movementDescription" class="form-label fw-semibold" id="descriptionLabel">Descrição</label>
                                        <input type="text" class="form-control" id="movementDescription" name="description" placeholder="Ex.: Supermercado" value="{{ old('description') }}" maxlength="255" required>
                                    </div>

                                    <div class="row g-2 mb-3 movement-date-category">
                                        <div class="col-6">
                                            <label for="movementDate" class="form-label fw-semibold">Data</label>
                                            <input type="date" class="form-control" id="movementDate" name="expense_date" value="{{ old('expense_date', old('income_date', now()->toDateString())) }}" required>
                                        </div>
                                        <div class="col-6">
                                            <label for="movementCategory" class="form-label fw-semibold">Categoria</label>
                                            <select class="form-select" id="movementCategory" name="category_id" required>
                                                <option value="" selected disabled>Seleciona uma categoria</option>
                                                @foreach ($categoriasDespesa as $id => $label)
                                                    <option value="{{ $id }}" @selected((string) old('category_id') === (string) $id)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <fieldset id="dashboardPaidFields" class="border-0 p-0 m-0 mb-3">
                                        <legend class="form-label fw-semibold mb-2">Estado do movimento</legend>
                                        <div class="payment-status-grid movement-status-grid" role="radiogroup" aria-label="Estado do movimento">
                                            <label class="payment-status-option">
                                                <input class="form-check-input" type="radio" name="paid_now" value="0" checked>
                                                <span>Pendente</span>
                                            </label>
                                            <label class="payment-status-option">
                                                <input class="form-check-input" type="radio" name="paid_now" value="1">
                                                <span>Pago</span>
                                            </label>
                                        </div>
                                    </fieldset>

                                    <div id="movementSettlementFields" class="mb-3" hidden>
                                        <label for="movementAccount" class="form-label fw-semibold" id="movementAccountLabel">De onde saiu o dinheiro?</label>
                                        <select class="form-select mb-3 account-picker-native" id="movementAccount" name="account_id">
                                            <option value="">Selecionar conta</option>
                                            @if ($accounts->where('type', 'bank')->isNotEmpty())
                                                <optgroup label="Contas bancárias">
                                                    @foreach ($accounts->where('type', 'bank') as $account)
                                                        @php $accountBalance = $accountBalances[$account->id]['balance'] ?? null; @endphp
                                                        <option value="{{ $account->id }}" data-account-type="bank" @selected($accounts->count() === 1) @if($accountBalance !== null) style="color:{{ $accountBalance < 0 ? '#dc3545' : 'inherit' }}" @endif>{{ $account->name }} · {{ $account->currency }} · {{ $accountBalance !== null ? number_format($accountBalance, 2, ',', '.') . ' ' . $account->currency : 'Saldo indisponível' }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                            @if ($accounts->where('type', 'credit_card')->isNotEmpty())
                                                <optgroup label="Cartões de crédito">
                                                    @foreach ($accounts->where('type', 'credit_card') as $account)
                                                        @php $availableCredit = $accountBalances[$account->id]['available_credit'] ?? null; @endphp
                                                        <option value="{{ $account->id }}" data-account-type="credit_card">{{ $account->name }} · {{ $account->currency }} · {{ $availableCredit !== null ? number_format($availableCredit, 2, ',', '.') . ' ' . $account->currency . ' disponível' : 'Crédito indisponível' }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                            @if ($accounts->where('type', 'cash')->isNotEmpty())
                                                <optgroup label="Dinheiro">
                                                    @foreach ($accounts->where('type', 'cash') as $account)
                                                        @php $accountBalance = $accountBalances[$account->id]['balance'] ?? null; @endphp
                                                        <option value="{{ $account->id }}" data-account-type="cash" @if($accountBalance !== null) style="color:{{ $accountBalance < 0 ? '#dc3545' : 'inherit' }}" @endif>{{ $account->name }} · {{ $account->currency }} · {{ $accountBalance !== null ? number_format($accountBalance, 2, ',', '.') . ' ' . $account->currency : 'Saldo indisponível' }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endif
                                        </select>
                                        @if ($accounts->isEmpty())
                                            <div class="form-text text-warning mb-3"><a href="{{ route('financial.accounts.page') }}">Criar conta</a></div>
                                        @endif
                                        <div id="movementPaymentMethodField">
                                            <label for="dashboardActualPaymentMethod" class="form-label fw-semibold">Método de pagamento</label>
                                            <select id="dashboardActualPaymentMethod" name="actual_payment_method" class="form-select">
                                                <option value="">Selecionar método</option>
                                                <option value="cash" data-account-type="cash">Dinheiro</option>
                                                <option value="debit" data-account-type="bank">Cartão de débito</option>
                                                <option value="transfer" data-account-type="bank">Transferência bancária</option>
                                                <option value="mbway" data-account-type="bank">MB WAY</option>
                                                <option value="direct_debit" data-account-type="bank">Débito direto</option>
                                                <option value="credit" data-account-type="credit_card">Cartão de crédito</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="accordion mb-3 movement-details-accordion" id="dashboardAdditionalDetails">
                                        <div class="accordion-item border rounded-3">
                                            <h3 class="accordion-header" id="dashboardAdditionalDetailsHeading">
                                                <button class="accordion-button collapsed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#dashboardAdditionalDetailsPanel" aria-expanded="false" aria-controls="dashboardAdditionalDetailsPanel">
                                                    <span><strong>Detalhes</strong></span>
                                                </button>
                                            </h3>
                                            <div id="dashboardAdditionalDetailsPanel" class="accordion-collapse collapse" aria-labelledby="dashboardAdditionalDetailsHeading" data-bs-parent="#dashboardAdditionalDetails">
                                                <div class="accordion-body px-3">
                                                    <div class="mb-3">
                                                        <label class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="dashboardDueDateEnabled">
                                                            <span class="form-check-label">Definir data de vencimento</span>
                                                        </label>
                                                        <div id="dashboardDueDateField" class="mt-2" hidden>
                                                            <label for="dashboardDueDateInput" class="form-label">Data de vencimento</label>
                                                            <input id="dashboardDueDateInput" type="date" class="form-control" value="{{ now()->toDateString() }}">
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="recurrence_enabled" value="1" id="dashboardRecurrenceEnabled">
                                                            <span class="form-check-label">Repetir este movimento</span>
                                                        </label>
                                                        <div id="dashboardRecurrenceFields" class="row g-2 mt-1" hidden>
                                                            <div class="col-4">
                                                                <label for="dashboardRecurrenceInterval" class="form-label">A cada</label>
                                                                <input id="dashboardRecurrenceInterval" name="recurrence_interval" type="number" min="1" max="365" value="1" class="form-control">
                                                            </div>
                                                            <div class="col-8">
                                                                <label for="dashboardRecurrenceFrequency" class="form-label">Frequência</label>
                                                                <select id="dashboardRecurrenceFrequency" name="recurrence_frequency" class="form-select">
                                                                    <option value="weekly">Semanal</option>
                                                                    <option value="monthly" selected>Mensal</option>
                                                                    <option value="yearly">Anual</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-12">
                                                                <label for="dashboardRecurrenceEnds" class="form-label">Termina em (opcional)</label>
                                                                <input id="dashboardRecurrenceEnds" name="recurrence_ends_at" type="date" class="form-control">
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="dashboardInstallmentsEnabled">
                                                            <span class="form-check-label">Dividir em prestações</span>
                                                        </label>
                                                        <div id="dashboardInstallmentFields" class="row g-2 mt-1" hidden>
                                                            <div class="col-6">
                                                                <label for="dashboardInstallments" class="form-label">Número de prestações</label>
                                                                <input id="dashboardInstallments" name="installments_count" type="number" min="2" max="120" value="2" class="form-control">
                                                            </div>
                                                            <div class="col-6">
                                                                <label for="dashboardInstallmentFrequency" class="form-label">Periodicidade</label>
                                                                <select id="dashboardInstallmentFrequency" name="installment_frequency" class="form-select">
                                                                    <option value="weekly">Semanal</option>
                                                                    <option value="biweekly">Quinzenal</option>
                                                                    <option value="monthly" selected>Mensal</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-12">
                                                                <div class="small fw-semibold mb-2">Resumo do parcelamento</div>
                                                                <div class="list-group list-group-flush" id="dashboardInstallmentScheduleList" aria-live="polite"></div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="movementNotes" class="form-label">Notas (opcional)</label>
                                                        <textarea id="movementNotes" name="notes" rows="3" class="form-control" placeholder="Observações adicionais">{{ old('notes') }}</textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-column-reverse flex-sm-row gap-2 movement-form-actions">
                                        <button type="button" class="btn btn-outline-secondary flex-fill" id="cancelMovementEdit">Cancelar</button>
                                        <button type="submit" class="btn btn-danger flex-fill" id="saveMovementButton">Guardar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </div>
