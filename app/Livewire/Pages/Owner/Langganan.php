<?php

namespace App\Livewire\Pages\Owner;

use App\Livewire\Concerns\TerikatTenant;
use App\Models\Invoice;
use App\Models\Subscription;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Halaman langganan & tagihan merchant.
 *
 * Sengaja TIDAK dipagari middleware PastikanTenantAktif: justru ke sini merchant
 * yang sedang suspend dialihkan, dan kalau halaman ini ikut diblokir ia tidak punya
 * jalan untuk membayar tagihannya.
 */
#[Layout('layouts.aplikasi')]
class Langganan extends Component
{
    use TerikatTenant;

    public function render()
    {
        return view('livewire.pages.owner.langganan', [
            'tenant' => auth()->user()->tenant,
            'langganan' => Subscription::with('plan')->latest('tanggal_mulai')->first(),
            'tagihan' => Invoice::with('payments')->latest('periode_mulai')->limit(12)->get(),
        ]);
    }
}
