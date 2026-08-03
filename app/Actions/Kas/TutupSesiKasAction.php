<?php

namespace App\Actions\Kas;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menutup sesi kas dan mencatat hasil rekonsiliasinya.
 *
 * Hitungan sistem TIDAK dipercayakan pada nilai yang dikirim layar — ia dihitung
 * ulang dari mutasi kas milik sesi itu (modal awal + uang masuk − uang keluar).
 * Yang diterima dari kasir hanyalah hasil hitung fisik uang di laci, dan selisih
 * antara keduanya justru informasi yang dicari.
 *
 * Selisih tidak pernah "dibetulkan" otomatis. Angka minus adalah temuan yang perlu
 * ditindaklanjuti owner, bukan galat yang boleh disembunyikan sistem.
 */
class TutupSesiKasAction
{
    public function execute(CashSession $sesi, float $kasFisik): CashSession
    {
        if (! $sesi->isTerbuka()) {
            throw new RuntimeException('Sesi kas ini sudah ditutup.');
        }

        if ($kasFisik < 0) {
            throw new RuntimeException('Hitungan kas fisik tidak boleh negatif.');
        }

        return DB::transaction(function () use ($sesi, $kasFisik) {
            // Muat ulang mutasi di dalam transaksi supaya tidak memakai angka lama
            // kalau ada penjualan yang masuk beberapa saat sebelum tombol ditekan.
            $sesi->load('movements');

            $kasSistem = $sesi->hitungKasSistem();

            $sesi->update([
                'ditutup_pada' => now(),
                'kas_akhir_sistem' => $kasSistem,
                'kas_akhir_fisik' => $kasFisik,
                'selisih' => $kasFisik - $kasSistem,
                'status' => CashSessionStatus::Ditutup,
            ]);

            return $sesi->fresh();
        });
    }
}
