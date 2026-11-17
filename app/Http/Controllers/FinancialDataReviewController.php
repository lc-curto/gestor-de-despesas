<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinancialDataReviewController extends Controller
{
    private const STEPS = ['accounts', 'categories', 'movements', 'classification'];

    private function snapshot($user, string $step): array
    {
        $data = match ($step) {
            'accounts' => $user->contasFinanceiras()
                ->orderBy('id')
                ->get(['id', 'name', 'type', 'currency', 'opening_balance', 'opening_balance_date', 'is_operational', 'status'])
                ->toArray(),
            'categories' => $user->categorias()
                ->orderBy('id')
                ->get(['id', 'name', 'slug', 'type', 'is_active'])
                ->toArray(),
            'movements' => [
                'canonical' => $user->movimentos()->orderBy('id')
                    ->get(['id', 'type', 'status', 'description', 'amount', 'movement_date', 'source_account_id', 'destination_account_id', 'category_id', 'is_historical', 'requires_account_assignment', 'legacy_type', 'legacy_id'])
                    ->toArray(),
                'legacy_expenses' => $user->despesas()->orderBy('id')->get()->toArray(),
                'legacy_incomes' => $user->receitas()->orderBy('id')->get()->toArray(),
            ],
            'classification' => $user->movimentos()->where('type', 'expense')->orderBy('id')
                ->get(['id', 'expense_behavior', 'recurrence_id'])
                ->toArray(),
            default => [],
        };

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return [
            'hash' => hash('sha256', $json ?: ''),
            'count' => match ($step) {
                'accounts' => count($data),
                'categories' => count($data),
                'movements' => count($data['canonical']) + count($data['legacy_expenses']) + count($data['legacy_incomes']),
                'classification' => count($data),
            },
        ];
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $review = DB::table('financial_data_reviews')->where('user_id', $user->id)->first();

        if (! $review) {
            DB::table('financial_data_reviews')->insert([
                'user_id' => $user->id,
                'review_version' => 1,
                'completed_steps' => json_encode([]),
                'step_evidence' => json_encode([]),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $review = DB::table('financial_data_reviews')->where('user_id', $user->id)->first();
        }

        $evidence = json_decode($review->step_evidence ?: '{}', true) ?: [];
        $completed = json_decode($review->completed_steps ?: '[]', true) ?: [];

        // Captura a referência inicial de cada etapa na primeira apresentação.
        foreach (self::STEPS as $step) {
            if (! isset($evidence[$step]['baseline_hash'])) {
                $snapshot = $this->snapshot($user, $step);
                $evidence[$step] = [
                    'baseline_hash' => $snapshot['hash'],
                    'baseline_count' => $snapshot['count'],
                    'captured_at' => now()->toISOString(),
                    'decision' => null,
                    'reviewed_hash' => null,
                    'reviewed_at' => null,
                    'state' => 'pending',
                ];
            } else {
                $current = $this->snapshot($user, $step);
                if (in_array($step, $completed, true) && ($evidence[$step]['reviewed_hash'] ?? null) !== $current['hash']) {
                    $evidence[$step]['state'] = 'changed_after_review';
                    $completed = array_values(array_diff($completed, [$step, 'final']));
                }
            }
        }

        DB::table('financial_data_reviews')->where('id', $review->id)->update([
            'step_evidence' => json_encode($evidence),
            'completed_steps' => json_encode($completed),
            'updated_at' => now(),
        ]);

        $counts = [
            'accounts' => $user->contasFinanceiras()->count(),
            'categories' => $user->categorias()->count(),
            'movements' => $user->movimentos()->count(),
            'legacy_expenses' => $user->despesas()->count(),
            'legacy_incomes' => $user->receitas()->count(),
        ];

        $stepStates = [];
        foreach (self::STEPS as $step) {
            $current = $this->snapshot($user, $step);
            $item = $evidence[$step];
            $item['changed'] = $current['hash'] !== $item['baseline_hash'];
            $item['current_count'] = $current['count'];
            $item['done'] = in_array($step, $completed, true)
                && ($item['reviewed_hash'] ?? null) === $current['hash'];
            $stepStates[$step] = $item;
        }

        return view('financial-data-review', compact('completed', 'counts', 'stepStates'));
    }

    public function completeStep(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', Rule::in([...self::STEPS, 'final'])],
            'decision' => ['required_if:step,accounts,categories,movements,classification', Rule::in(['confirmed_correct', 'changes_reviewed'])],
            'confirmed' => ['required', 'accepted'],
        ]);

        $user = $request->user();
        $review = DB::table('financial_data_reviews')->where('user_id', $user->id)->firstOrFail();
        $completed = json_decode($review->completed_steps ?: '[]', true) ?: [];
        $evidence = json_decode($review->step_evidence ?: '{}', true) ?: [];

        if ($data['step'] === 'final') {
            $finalized = DB::transaction(function () use ($user): bool {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
                $lockedReview = DB::table('financial_data_reviews')
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $completed = json_decode($lockedReview->completed_steps ?: '[]', true) ?: [];
                $evidence = json_decode($lockedReview->step_evidence ?: '{}', true) ?: [];

                foreach (self::STEPS as $step) {
                    $current = $this->snapshot($user, $step);
                    if (! in_array($step, $completed, true)
                        || ! isset($evidence[$step]['reviewed_hash'])
                        || $evidence[$step]['reviewed_hash'] !== $current['hash']) {
                        return false;
                    }
                }

                DB::table('financial_data_reviews')->where('id', $lockedReview->id)->update([
                    'completed_steps' => json_encode(array_values(array_unique([...$completed, 'final']))),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $user->id)->update(['financial_review_required' => false]);

                return true;
            });

            if (! $finalized) {
                return back()->withErrors(['review' => 'Existem etapas por rever ou dados que mudaram depois da revisão. Volta a validar essas etapas.']);
            }

            return redirect()->route('dashboard')->with('success', 'Revisão dos dados concluída. Já podes utilizar a nova versão do Meu Bolso.');
        }

        $step = $data['step'];
        $current = $this->snapshot($user, $step);
        $baselineHash = $evidence[$step]['baseline_hash'] ?? null;
        $changed = $baselineHash !== null && $baselineHash !== $current['hash'];

        if (! $changed && $data['decision'] === 'changes_reviewed') {
            return back()->withErrors(['review' => 'Não foi detetada nenhuma alteração nesta etapa. Confirma que os dados já estavam corretos.']);
        }

        if ($changed && $data['decision'] !== 'changes_reviewed') {
            return back()->withErrors(['review' => 'Foram detetadas alterações. Confirma que as correções foram revistas antes de concluir.']);
        }

        $evidence[$step] = array_merge($evidence[$step] ?? [], [
            'decision' => $data['decision'],
            'reviewed_hash' => $current['hash'],
            'reviewed_count' => $current['count'],
            'reviewed_at' => now()->toISOString(),
            'state' => $changed ? 'changes_reviewed' : 'confirmed_correct',
        ]);

        if (! in_array($step, $completed, true)) {
            $completed[] = $step;
        }

        DB::table('financial_data_reviews')->where('id', $review->id)->update([
            'step_evidence' => json_encode($evidence),
            'completed_steps' => json_encode($completed),
            'updated_at' => now(),
        ]);

        return redirect()->route('financial.review.index')->with('success', 'Etapa validada e evidência guardada.');
    }
}
