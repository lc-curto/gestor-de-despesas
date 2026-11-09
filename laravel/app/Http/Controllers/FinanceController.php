<?php
namespace App\Http\Controllers;
use App\Support\Categorias;
use Illuminate\Http\Request;
class FinanceController extends Controller
{
    private function config(Request $r): array {
        $tipo=$r->route('tipo'); abort_unless(in_array($tipo,['despesa','receita'],true),404);
        return [$tipo,$tipo==='despesa'?'despesas':'receitas',$tipo==='despesa'?'expense_date':'income_date',$tipo==='despesa'?Categorias::DESPESAS:Categorias::RECEITAS];
    }
    private function query(Request $r) { [, $rel]=$this->config($r); return $r->user()->{$rel}(); }
    private function rules(Request $r): array {
        [,,$data,$cats]=$this->config($r);
        return ['description'=>'required|string|max:255','amount'=>'required|numeric|gt:0|decimal:0,2|max:9999999999.99','currency'=>'required|in:EUR,USD,GBP,BRL','category'=>'required|in:'.implode(',',array_keys($cats)),$data=>'required|date'];
    }
    public function index(Request $r) {
        [$tipo,$rel,$data,$cats]=$this->config($r); $q=$this->query($r)->orderByDesc($data)->orderByDesc('created_at');
        if($r->filled('categoria')) $q->where('category',$r->query('categoria'));
        if($r->filled('inicio')) $q->whereDate($data,'>=',$r->query('inicio'));
        if($r->filled('fim')) $q->whereDate($data,'<=',$r->query('fim'));
        $registos=$q->paginate(10)->withQueryString();
        return view('finance.index',compact('tipo','data','cats','registos'));
    }
    public function create(Request $r) { [$tipo,,$data,$cats]=$this->config($r); $registo=null; return view('finance.form',compact('tipo','data','cats','registo')); }
    public function store(Request $r) { [,,$data]=$this->config($r); $this->query($r)->create($r->validate($this->rules($r))); return redirect()->route($r->route('tipo').'.index')->with('success','Registo criado.'); }
    public function edit(Request $r,string $id) { [$tipo,,$data,$cats]=$this->config($r); $registo=$this->query($r)->findOrFail($id); return view('finance.form',compact('tipo','data','cats','registo')); }
    public function update(Request $r,string $id) { [,,$data]=$this->config($r); $registo=$this->query($r)->findOrFail($id); $registo->update($r->validate($this->rules($r))); return redirect()->route($r->route('tipo').'.index')->with('success','Registo atualizado.'); }
    public function destroy(Request $r,string $id) { $this->query($r)->findOrFail($id)->delete(); return back()->with('success','Registo eliminado.'); }
}