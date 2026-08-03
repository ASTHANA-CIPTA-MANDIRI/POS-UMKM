<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KeluarController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): RedirectResponse
    {
        Auth::logout();

        // Sesi di-invalidate dan token diputar ulang supaya sesi lama tidak bisa
        // dipakai kembali (session fixation).
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Context tenant hidup selama request; dibersihkan agar tidak ada sisa
        // kalau ada proses lain yang berjalan setelah ini.
        $context->forget();

        return redirect()->route('beranda');
    }
}
