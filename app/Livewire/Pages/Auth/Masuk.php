<?php

namespace App\Livewire\Pages\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Login email + password untuk Super Admin, Owner, Regional Manager, dan Manager
 * Outlet. Kasir dan Dapur memakai jalur terpisah (username + PIN) karena mereka
 * tidak punya email dan login dari perangkat outlet.
 */
#[Layout('layouts.tamu')]
class Masuk extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $ingatSaya = false;

    public function masuk(): void
    {
        $this->validate();

        $this->pastikanTidakDibanjiri();

        $user = User::where('email', $this->email)->first();

        /*
         * Kasir & Dapur ditolak di sini walau kredensialnya benar. Kalau tidak,
         * akun kasir bisa dipakai masuk dari perangkat mana pun lewat jalur email,
         * melewati validasi device binding yang justru jadi inti pengamanannya.
         */
        if ($user !== null && ! $user->bolehKeBackOffice() && ! $user->isPlatformLevel()) {
            throw ValidationException::withMessages([
                'email' => 'Akun kasir masuk lewat halaman kasir, bukan halaman ini.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->ingatSaya)) {
            RateLimiter::hit($this->kunciPembatas());

            throw ValidationException::withMessages([
                'email' => 'Email atau password tidak cocok.',
            ]);
        }

        // Akun nonaktif tidak boleh lanjut walau kredensialnya benar.
        if (! Auth::user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Akun ini sedang dinonaktifkan. Hubungi pemilik usaha Anda.',
            ]);
        }

        RateLimiter::clear($this->kunciPembatas());

        session()->regenerate();

        Auth::user()->forceFill(['last_login_at' => now()])->save();

        $this->redirectRoute(Auth::user()->rutaBeranda(), navigate: true);
    }

    /**
     * Pembatasan percobaan per email + IP. Tanpa ini, password bisa ditebak
     * berulang tanpa hambatan.
     */
    private function pastikanTidakDibanjiri(): void
    {
        if (! RateLimiter::tooManyAttempts($this->kunciPembatas(), 5)) {
            return;
        }

        $detik = RateLimiter::availableIn($this->kunciPembatas());

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.",
        ]);
    }

    private function kunciPembatas(): string
    {
        return 'masuk:'.mb_strtolower($this->email).'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.pages.auth.masuk.masuk');
    }
}
