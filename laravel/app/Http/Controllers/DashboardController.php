<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
class DashboardController extends Controller
{
    public function __invoke(Request $r) {
        $u=$r->user(); $mes=$r->query('mes',now()->format('Y-m'));
        if(!preg_match('/^\d{4}-\d{2}$/',$mes)) $mes=now()->format('Y-m');
        $inicio=Carbon::createFromFormat('Y-m-d',$mes.'-01')->startOfMonth(); $fim=$inicio->copy()->endOfMonth();
        $despesas=$u->despesas()->whereBetween('expense_date',[$inicio,$fim])->orderByDesc('expense_date')->limit(6)->get();
        $receitas=$u->receitas()->whereBetween('income_date',[$inicio,$fim])->orderByDesc('income_date')->limit(6)->get();
        $td=$u->despesas()->whereBetween('expense_date',[$inicio,$fim])->selectRaw('currency,SUM(amount) total')->groupBy('currency')->pluck('total','currency');
        $tr=$u->receitas()->whereBetween('income_date',[$inicio,$fim])->selectRaw('currency,SUM(amount) total')->groupBy('currency')->pluck('total','currency');
        $moeda=$u->currency; $totalDespesas=(float)($td[$moeda]??0); $totalReceitas=(float)($tr[$moeda]??0);
        return view('dashboard',compact('inicio','despesas','receitas','td','tr','moeda','totalDespesas','totalReceitas'));
    }
}