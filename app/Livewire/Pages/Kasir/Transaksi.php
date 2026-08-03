<?php

namespace App\Livewire\Pages\Kasir;

use App\Actions\Kas\BukaSesiKasAction;
use App\Actions\Kas\TutupSesiKasAction;
use App\Actions\Kasir\SusunKatalogAction;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\CashSession;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Layar transaksi kasir.
 *
 * Komponen ini SENGAJA hanya mengurus dua hal yang memang butuh server: membuka dan
 * menutup sesi kas. Seluruh interaksi transaksi — memilih produk, mengubah jumlah,
 * menghitung total, dan membayar — dijalankan di sisi klien oleh Alpine.
 *
 * Alasannya: setiap aksi Livewire adalah satu perjalanan ke server. Kalau grid
 * produk dan keranjang dibangun dengan Livewire, kasir tidak bisa menekan apa pun
 * saat jaringan mati — padahal justru itu keadaan yang paling harus tetap jalan di
 * warung. Transaksinya dikirim lewat endpoint sinkronisasi yang idempoten, jalur
 * yang sama baik saat online maupun saat antrean menyusul belakangan.
 */
#[Layout('layouts.kasir', ['penuh' => true])]
class Transaksi extends Component
{
    use TerikatTenant;

    /**
     * Tanpa nilai bawaan — alasannya sama seperti di Beranda: kolom yang sudah terisi
     * membuat kasir bisa membuka laci tanpa pernah menghitung isinya.
     */
    public ?float $modalAwal = null;

    public float $kasFisik = 0;

    public ?string $galat = null;

    public function bukaSesi(BukaSesiKasAction $buka): void
    {
        try {
            if ($this->modalAwal === null) {
                $this->galat = 'Hitung uang di laci dulu, lalu isi jumlahnya.';

                return;
            }

            $buka->execute(auth()->user(), $this->modalAwal);
            $this->galat = null;
        } catch (RuntimeException $e) {
            $this->galat = $e->getMessage();
        }
    }

    public function tutupSesi(TutupSesiKasAction $tutup, BukaSesiKasAction $buka): void
    {
        $sesi = $buka->sesiBerjalan(auth()->user());

        if ($sesi === null) {
            $this->galat = 'Tidak ada sesi kas yang terbuka.';

            return;
        }

        try {
            $tutup->execute($sesi, $this->kasFisik);
            $this->galat = null;
            $this->kasFisik = 0;
        } catch (RuntimeException $e) {
            $this->galat = $e->getMessage();
        }
    }

    public function render(BukaSesiKasAction $buka, SusunKatalogAction $susun)
    {
        $user = auth()->user();
        $sesi = $buka->sesiBerjalan($user);

        return view('livewire.pages.kasir.transaksi', [
            'sesi' => $sesi,
            'kasSistem' => $sesi ? $this->kasSistem($sesi) : 0.0,
            'outletId' => $user->outlet_id,
            'deviceId' => $user->device_id_terikat,
            // Kepala struk. Branding di struk termasuk di SEMUA paket harga, jadi
            // nama usaha diambil dari tenant, bukan dari nama aplikasi.
            'usaha' => $user->tenant?->business_name ?? 'Usaha',
            'outletNama' => $user->outlet?->outlet_name ?? '-',
            'kasirNama' => $user->name,
            // Ditanam di halaman supaya grid produk dan daftar pelanggan langsung
            // tersedia pada paint pertama tanpa permintaan kedua. Bill TIDAK ikut:
            // isinya milik perangkat yang membukanya.
            'bekalAwal' => $sesi !== null ? $susun->execute($user) : ['produk' => [], 'pelanggan' => [], 'mode' => []],
        ]);
    }

    /** Hitungan sistem ditampilkan agar kasir tahu angka pembanding saat tutup kasir. */
    private function kasSistem(CashSession $sesi): float
    {
        return $sesi->loadMissing('movements')->hitungKasSistem();
    }
}
