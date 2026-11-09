<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
class DashboardController extends Controller
{
    public function __invoke(Request $r)
    {
        $u=$r->user(); $mes=$r->query('mes',now()->format('Y-m'));
        try { $inicio=Carbon::createFromFormat('!Y-m',$mes)->startOfMonth(); }
        catch(\Throwable) { $inicio=now()->startOfMonth(); }
        if($inicio->format('Y-m')!==$mes) $inicio=now()->startOfMonth();
        $fim=$inicio->copy()->endOfMonth();
        $despesas=$u->despesas()->whereBetween('expense_date',[$inicio->toDateString(),$fim->toDateString()])->orderByDesc('expense_date')->limit(6)->get();
        $receitas=$u->receitas()->whereBetween('income_date',[$inicio->toDateString(),$fim->toDateString()])->orderByDesc('income_date')->limit(6)->get();
        $td=$u->despesas()->whereBetween('expense_date',[$inicio->toDateString(),$fim->toDateString()])->selectRaw('currency,SUM(amount) total')->groupBy('currency')->pluck('total','currency');
        $tr=$u->receitas()->whereBetween('income_date',[$inicio->toDateString(),$fim->toDateString()])->selectRaw('currency,SUM(amount) total')->groupBy('currency')->pluck('total','currency');
        $moeda=$u->currency; $totalDespesas=(float)($td[$moeda]??0); $totalReceitas=(float)($tr[$moeda]??0);
        return view('dashboard',compact('inicio','despesas','receitas','td','tr','moeda','totalDespesas','totalReceitas'));
    }
}