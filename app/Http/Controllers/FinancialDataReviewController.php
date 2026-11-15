<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class FinancialDataReviewController extends Controller
{
    private const STEPS = ['accounts', 'categories', 'movements', 'classification', 'final'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $review = DB::table('financial_data_reviews')->where('user_id', $user->id)->first();

        if (!$review) {
            DB::table('financial_data_reviews')->insert([
                'user_id' => $user->id,
                'review_version' => 1,
                'completed_steps' => json_encode([]),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $review = DB::table('financial_data_reviews')->where('user_id', $user->id)->first();
        }

        $completed = json_decode($review->completed_steps ?: '[]', true) ?: [];
        $counts = [
            'accounts' => $user->contasFinanceiras()->count(),
            'categories' => $user->categorias()->count(),
            'movements' => $user->movimentos()->count(),
            'legacy_expenses' => $user->despesas()->count(),
            'legacy_incomes' => $user->receitas()->count(),
        ];

        return view('financial-data-review', compact('completed', 'counts'));
    }

    public function completeStep(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', Rule::in(self::STEPS)],
            'confirmed' => ['required', 'accepted'],
        ]);

        $user = $request->user();
        $review = DB::table('financial_data_reviews')->where('user_id', $user->id)->firstOrFail();
        $completed = json_decode($review->completed_steps ?: '[]', true) ?: [];

        if ($data['step'] === 'final') {
            $required = array_values(array_diff(self::STEPS, ['final']));
            if (array_diff($required, $completed)) {
                return back()->withErrors(['review' => 'Conclui primeiro todas as etapas de revisão.']);
            }

            DB::transaction(function () use ($user, $review, $completed): void {
                DB::table('financial_data_reviews')->where('id', $review->id)->update([
                    'completed_steps' => json_encode(array_values(array_unique([...$completed, 'final']))),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $user->id)->update(['financial_review_required' => false]);
            });

            return redirect()->route('dashboard')->with('success', 'Revisão dos dados concluída. Já podes utilizar a nova versão do Meu Bolso.');
        }

        if (!in_array($data['step'], $completed, true)) {
            $completed[] = $data['step'];
        }

        DB::table('financial_data_reviews')->where('id', $review->id)->update([
            'completed_steps' => json_encode($completed),
            'updated_at' => now(),
        ]);

        return redirect()->route('financial.review.index')->with('success', 'Etapa registada. Continua a revisão.');
    }
}
