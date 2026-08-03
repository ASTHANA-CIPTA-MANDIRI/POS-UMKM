<?php

namespace App\Support;

/**
 * Ringkasan hasil satu batch sinkronisasi. jumlah_duplikat bukan error —
 * itu tanda perangkat mengirim ulang batch yang sebelumnya sudah diterima.
 */
final class SyncResult
{
    /**
     * @param  array<int, array{id: string, alasan: string}>  $detailGagal
     */
    public function __construct(
        public readonly int $dikirim,
        public readonly int $dibuat,
        public readonly int $duplikat,
        public readonly int $gagal,
        public readonly array $detailGagal = [],
        public readonly int $billDibuat = 0,
        public readonly int $billDiperbarui = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'jumlah_dikirim' => $this->dikirim,
            'jumlah_dibuat' => $this->dibuat,
            'jumlah_duplikat' => $this->duplikat,
            'jumlah_gagal' => $this->gagal,
            'detail_gagal' => $this->detailGagal,
            'bill_dibuat' => $this->billDibuat,
            'bill_diperbarui' => $this->billDiperbarui,
        ];
    }

    public function semuaBerhasil(): bool
    {
        return $this->gagal === 0;
    }
}
