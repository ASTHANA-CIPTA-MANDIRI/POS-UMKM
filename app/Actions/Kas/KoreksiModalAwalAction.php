<?php

namespace App\Actions\Kas;

use App\Enums\CashSessionStatus;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mengoreksi modal awal sesi kas yang masih berjalan.
 *
 * Salah hitung uang di laci itu kejadian nyata, jadi harus ada jalan keluarnya.
 * Tapi modal awal adalah PEMBANDING yang menahan selisih akhir shift — kalau ia bisa
 * ditimpa tanpa jejak, angka itu berhenti menjaga apa pun: kekurangan kas di akhir
 * shift bisa dihapus hanya dengan "memperbaiki" angka pembukanya.
 *
 * Karena itu koreksi di sini WAJIB beralasan dan selalu meninggalkan catatan di
 * audit_logs berisi nilai lama, nilai baru, dan siapa yang mengubahnya.
 *
 * Yang BELUM ada: persetujuan pemilik. Kasir masih bisa mengoreksi sendiri. Kontrol
 * itu yang seharusnya dipasang sebelum sistem dipakai bersama staf yang tidak
 * sepenuhnya dipercaya — jejaknya ada, tapi jejak saja tidak mencegah.
 */
class KoreksiModalAwalAction
{
    public function execute(CashSession $sesi, float $modalBaru, string $alasan, User $oleh): CashSession
    {
        if ($sesi->status !== CashSessionStatus::Terbuka) {
            throw new RuntimeException('Sesi kas sudah ditutup, modal awalnya tidak bisa diubah lagi.');
        }

        if ($modalBaru < 0) {
            throw new RuntimeException('Modal awal tidak boleh negatif.');
        }

        $alasan = trim($alasan);

        if ($alasan === '') {
            throw new RuntimeException('Tulis dulu alasan koreksinya.');
        }

        $lama = (float) $sesi->modal_awal;

        if (abs($lama - $modalBaru) < 0.01) {
            throw new RuntimeException('Angkanya sama dengan yang sekarang, tidak ada yang perlu dikoreksi.');
        }

        return DB::transaction(function () use ($sesi, $lama, $modalBaru, $alasan, $oleh) {
            $sesi->update(['modal_awal' => $modalBaru]);

            /*
             * Catatan audit ditulis di dalam transaksi yang sama dengan perubahannya.
             * Kalau dipisah, kegagalan di antara keduanya menghasilkan perubahan uang
             * tanpa jejak — keadaan yang justru paling perlu dihindari di sini.
             */
            AuditLog::create([
                'outlet_id' => $sesi->outlet_id,
                'user_id' => $oleh->getKey(),
                'aksi' => 'koreksi_modal_awal',
                'entitas_terkait' => 'cash_sessions',
                'entitas_id' => $sesi->getKey(),
                'detail_json' => [
                    'modal_awal_lama' => $lama,
                    'modal_awal_baru' => $modalBaru,
                    'selisih' => $modalBaru - $lama,
                    'alasan' => $alasan,
                ],
                'ip_address' => request()->ip(),
            ]);

            return $sesi->refresh();
        });
    }

    /**
     * Riwayat koreksi sesi ini, terbaru di atas. Ditampilkan di layar supaya kasir
     * berikutnya dan pemilik tahu angka pembukanya pernah diubah — koreksi yang
     * tersembunyi sama saja dengan tidak tercatat.
     *
     * @return Collection<int, AuditLog>
     */
    public function riwayat(CashSession $sesi)
    {
        return AuditLog::where('aksi', 'koreksi_modal_awal')
            ->where('entitas_terkait', 'cash_sessions')
            ->where('entitas_id', $sesi->getKey())
            ->latest()
            ->get();
    }
}
