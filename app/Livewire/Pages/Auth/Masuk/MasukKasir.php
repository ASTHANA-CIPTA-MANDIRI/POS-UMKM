<?php

namespace App\Livewire\Pages\Auth\Masuk;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Login Kasir & Dapur: username + PIN, ditambah validasi perangkat.
 *
 * Nomor seri perangkat disimpan di localStorage peramban dan dikirim saat login.
 * Pengecekannya TIDAK mempercayai nilai itu sebagai bukti identitas — nilai itu
 * hanya dipakai untuk mencari record device, lalu hak aksesnya diverifikasi ulang
 * terhadap outlet milik user di database (User::canLoginFromDevice).
 *
 * Bagian 3.2.E dokumen: staff Outlet A yang mencoba login di perangkat Outlet B
 * harus DITOLAK, bukan sekadar diberi peringatan.
 */
#[Layout('layouts.tamu')]
class MasukKasir extends Component
{
    #[Validate('required|string|max:64')]
    public string $username = '';

    #[Validate('required|string|min:4|max:12')]
    public string $pin = '';

    /** Nomor seri perangkat, diisi otomatis dari localStorage oleh Blade. */
    public string $serialPerangkat = '';

    public function masuk(): void
    {
        $this->validate();

        $this->pastikanTidakDibanjiri();

        $user = User::where('username', $this->username)->first();

        // Pesan gagal disamakan untuk semua sebab supaya username yang benar tidak
        // bisa dibedakan dari yang salah lewat perbedaan pesan.
        if ($user === null || $user->pin_hash === null || ! Hash::check($this->pin, $user->pin_hash)) {
            RateLimiter::hit($this->kunciPembatas());

            throw ValidationException::withMessages([
                'username' => 'Username atau PIN tidak cocok.',
            ]);
        }

        if ($user->bolehKeBackOffice() || $user->isPlatformLevel()) {
            throw ValidationException::withMessages([
                'username' => 'Akun ini masuk lewat halaman login pemilik.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => 'Akun ini sedang dinonaktifkan. Hubungi pemilik usaha Anda.',
            ]);
        }

        $this->pastikanPerangkatDiizinkan($user);

        RateLimiter::clear($this->kunciPembatas());

        Auth::login($user);
        session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        $this->redirectRoute($user->rutaBeranda(), navigate: true);
    }

    /**
     * Perangkat yang dikenali wajib ada, aktif, dan berada di outlet yang boleh
     * diakses user. Perangkat yang di-lock lewat MDM atau berstatus hilang/rusak
     * juga ditolak di sini.
     */
    private function pastikanPerangkatDiizinkan(User $user): void
    {
        // Tanpa serial (mis. perangkat baru pertama kali dibuka), user yang sudah
        // di-bind ke perangkat tertentu tetap harus ditolak.
        if ($this->serialPerangkat === '') {
            if ($user->device_id_terikat !== null) {
                throw ValidationException::withMessages([
                    'username' => 'Perangkat ini belum terdaftar untuk outlet Anda. Minta Owner mendaftarkannya.',
                ]);
            }

            return;
        }

        $device = Device::withoutGlobalScopes()
            ->where('serial_number', $this->serialPerangkat)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if ($device === null || ! $user->canLoginFromDevice($device)) {
            throw ValidationException::withMessages([
                'username' => 'Perangkat ini tidak diizinkan untuk akun Anda.',
            ]);
        }
    }

    private function pastikanTidakDibanjiri(): void
    {
        // Ambang lebih ketat daripada password: PIN hanya 6 angka, jadi ruang
        // tebakannya jauh lebih kecil.
        if (! RateLimiter::tooManyAttempts($this->kunciPembatas(), 4)) {
            return;
        }

        $detik = RateLimiter::availableIn($this->kunciPembatas());

        throw ValidationException::withMessages([
            'username' => "Terlalu banyak percobaan. Coba lagi dalam {$detik} detik.",
        ]);
    }

    private function kunciPembatas(): string
    {
        return 'masuk-kasir:'.mb_strtolower($this->username).'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.pages.auth.masuk.masuk-kasir');
    }
}
