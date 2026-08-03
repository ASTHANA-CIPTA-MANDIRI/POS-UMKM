<?php

namespace App\Livewire\Concerns;

/**
 * Mengirim toast ke peramban.
 *
 * Memakai event, BUKAN session()->flash(). Flash message menuntut halaman dimuat
 * ulang untuk terlihat; dengan Livewire yang hanya memperbarui sebagian halaman,
 * pesannya bisa muncul terlambat pada navigasi berikutnya atau tidak muncul sama
 * sekali. Event tiba tepat saat aksinya selesai.
 *
 * Pendengarnya satu untuk seluruh aplikasi — lihat resources/js/toast.js.
 */
trait MengirimToast
{
    /** @param 'sukses'|'galat'|'peringatan'|'info' $jenis */
    protected function toast(string $pesan, string $jenis = 'sukses'): void
    {
        $this->dispatch('toast', pesan: $pesan, jenis: $jenis);
    }
}
