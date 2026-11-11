<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
class AccountController extends Controller
{
    public function login(Request $r) {
        $d=$r->validate(['email'=>'required|email','password'=>'required|string']);
        $d['email']=Str::lower($d['email']);
        if(!Auth::attempt($d,$r->boolean('remember'))) return back()->withErrors(['email'=>'Credenciais inválidas.'])->onlyInput('email');
        $r->session()->regenerate(); return redirect()->intended(route('dashboard'));
    }
    public function register(Request $r) {
        $d=$r->validate(['name'=>'required|string|max:80','email'=>'required|email|max:255|unique:users,email','password'=>['required',PasswordRule::min(6)],'currency'=>'required|in:EUR,USD,GBP,BRL']);
        $u=User::create(['name'=>$d['name'],'email'=>Str::lower($d['email']),'password'=>$d['password'],'currency'=>$d['currency']]);

        // Cada nova conta recebe o mesmo conjunto inicial de categorias da migração.
        $categoriasIniciais = [
            ['name'=>'Casa','slug'=>'casa','type'=>'despesa','icon'=>'house','color'=>'#D97706'],
            ['name'=>'Alimentação','slug'=>'alimentacao','type'=>'despesa','icon'=>'utensils','color'=>'#16A085'],
            ['name'=>'Transporte','slug'=>'transportes','type'=>'despesa','icon'=>'car-front','color'=>'#2563EB'],
            ['name'=>'Saúde','slug'=>'saude','type'=>'despesa','icon'=>'heart-pulse','color'=>'#DC2626'],
            ['name'=>'Lazer','slug'=>'lazer','type'=>'despesa','icon'=>'gamepad-2','color'=>'#9333EA'],
            ['name'=>'Compras','slug'=>'compras','type'=>'despesa','icon'=>'shopping-bag','color'=>'#D97706'],
            ['name'=>'Educação','slug'=>'educacao','type'=>'despesa','icon'=>'graduation-cap','color'=>'#0891B2'],
            ['name'=>'Outros','slug'=>'outros','type'=>'despesa','icon'=>'ellipsis','color'=>'#64748B'],
            ['name'=>'Salário','slug'=>'salario','type'=>'receita','icon'=>'wallet','color'=>'#16A34A'],
            ['name'=>'Vendas','slug'=>'vendas','type'=>'receita','icon'=>'store','color'=>'#2563EB'],
            ['name'=>'Trabalho extra','slug'=>'trabalho_extra','type'=>'receita','icon'=>'briefcase-business','color'=>'#0891B2'],
            ['name'=>'Investimentos','slug'=>'investimentos','type'=>'receita','icon'=>'chart-no-axes-combined','color'=>'#9333EA'],
            ['name'=>'Reembolsos','slug'=>'reembolsos','type'=>'receita','icon'=>'undo-2','color'=>'#D97706'],
            ['name'=>'Outros','slug'=>'outros','type'=>'receita','icon'=>'ellipsis','color'=>'#64748B'],
        ];
        $u->categorias()->createMany($categoriasIniciais);

        Auth::login($u); $r->session()->regenerate(); return redirect()->route('dashboard');
    }
    public function logout(Request $r) { Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect()->route('login'); }
    public function sendReset(Request $r) {
        $r->validate(['email'=>'required|email']);
        $s=Password::sendResetLink(['email'=>Str::lower($r->string('email')->toString())]);
        return $s===Password::RESET_LINK_SENT ? back()->with('status',__($s)) : back()->withErrors(['email'=>__($s)]);
    }
    public function reset(Request $r) {
        $d=$r->validate(['token'=>'required','email'=>'required|email','password'=>['required','confirmed',PasswordRule::min(8)]]);
        $s=Password::reset($d,function(User $u,string $p){$u->forceFill(['password'=>$p])->save();event(new PasswordReset($u));});
        return $s===Password::PASSWORD_RESET ? redirect()->route('login')->with('status',__($s)) : back()->withErrors(['email'=>__($s)]);
    }
}