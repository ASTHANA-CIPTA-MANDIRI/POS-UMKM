<?php

namespace App\Livewire\Pages\Owner\Pengaturan;

use App\Actions\Biaya\HitungBiayaHarianAction;
use App\Actions\Produk\SaranHargaAction;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Pengaturan warung: rumah SEMUA angka yang berlaku untuk seluruh aplikasi.
 *
 * KENAPA HALAMAN INI ADA, dan ini keputusan yang lahir dari keluhan pemilik langsung
 * (2026-08-10): target untung dulu disetel di layar Biaya operasional padahal yang
 * terpengaruh layar Produk. Angka yang DIPAKAI di satu layar tapi DISETEL di layar lain
 * memaksa orang berpindah-pindah untuk mengerti satu hal — dan orang yang harus berpindah
 * tiga layar untuk mengubah satu angka akan berhenti mengubahnya.
 *
 * DUA JALAN, bukan satu, dan keduanya perlu:
 *
 *  1. DI TEMPAT IA DIPAKAI. Target untung bisa diubah langsung dari formulir produk, tepat di
 *     sebelah saran harga yang dihasilkannya. Ini jalan yang dipakai sehari-hari, dan ia
 *     menghapus perpindahan layar sama sekali.
 *  2. DI SINI. Satu halaman yang memuat semuanya, supaya pertanyaan "di mana saya mengubah
 *     ini?" punya satu jawaban yang bisa diingat. Tanpa ini, setelan yang cuma bisa diubah
 *     di tempat pemakaiannya jadi tersembunyi bagi orang yang belum tahu di mana tempat itu.
 *
 * Halaman ini juga MENUNJUK ke angka perencanaan lain yang punya layarnya sendiri (biaya
 * operasional), bukan menyalinnya ke sini. Menyalin berarti dua tempat mengubah hal yang
 * sama, dan itu justru menambah kebingungan yang mau dihilangkan.
 */
#[Layout('layouts.aplikasi')]
class Pengaturan extends Component
{
    use MengirimToast, TerikatTenant;

    public string $targetMargin = '30';

    public function mount(): void
    {
        $this->targetMargin = (string) (float) auth()->user()->tenant->target_margin;
    }

    public function simpanTargetMargin(): void
    {
        $data = $this->validate([
            'targetMargin' => ['required', 'numeric', 'min:0', 'max:'.SaranHargaAction::MAKS_MARGIN],
        ], attributes: ['targetMargin' => 'target untung'], messages: [
            'targetMargin.max' => 'Target untung paling tinggi '.(int) SaranHargaAction::MAKS_MARGIN
                .'%. Di atas itu hitungannya menghasilkan harga yang mustahil.',
        ]);

        // Kolomnya sengaja tidak fillable di model Tenant — lihat catatan di sana.
        auth()->user()->tenant->forceFill(['target_margin' => (float) $data['targetMargin']])->save();

        $this->toast('Tersimpan. Saran harga di layar Produk ikut menyesuaikan.');
    }

    public function render()
    {
        $target = (float) auth()->user()->tenant->target_margin;

        return view('livewire.pages.owner.pengaturan.pengaturan', [
            /*
             * Contoh HIDUP dengan angka bulat, dihitung pakai aksi yang sama dengan layar
             * Produk. Persentase adalah bentuk yang paling sering salah dipahami; "30%"
             * jadi berarti begitu ia berwujud "modal Rp 10.000 → jual Rp 14.500".
             *
             * Memakai aksi yang sama, bukan menghitung ulang di sini: contoh yang berbeda
             * dari hasil sebenarnya lebih buruk daripada tidak ada contoh.
             */
            'contoh' => app(SaranHargaAction::class)->untuk(10000, $target),
            'bebanHarian' => app(HitungBiayaHarianAction::class)
                ->untuk(auth()->user()->scopedOutletId())['perHari'],
        ]);
    }
}
