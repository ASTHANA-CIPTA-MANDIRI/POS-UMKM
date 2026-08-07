<?php

namespace Database\Seeders;

use App\Models\Langganan\Plan;
use Illuminate\Database\Seeder;

/**
 * Paket langganan platform (bagian 5 dokumen bisnis).
 *
 * Harga diposisikan di bawah kisaran pasar POS Indonesia per Juli 2026 pada tiap
 * tingkatan yang sebanding. Angka pembanding yang dipakai saat menyusun ini:
 * tingkat dasar Rp 42rb–79rb/bln, tingkat multi-outlet Rp 129rb–166rb/bln, dan
 * paket per-outlet Rp 299rb/outlet/bln. Harus dikonfirmasi ulang sebelum rilis
 * karena harga vendor berubah.
 *
 * `branding_struk` sengaja ada di SEMUA paket — itu pembeda utama, karena penyedia
 * lain umumnya menahannya di paket tertinggi. Yang tetap eksklusif untuk Enterprise
 * adalah `white_label` (seluruh tampilan aplikasi memakai merek merchant), sesuai
 * pembedaan dua tingkat kustomisasi di dokumen bisnis.
 *
 * harga_bulanan_device = harga paket + sewa tablet & printer (Rp 129rb/bln).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $paket = [
            [
                'nama_paket' => 'Basic',
                'slug' => 'basic',
                'limit_outlet' => 1,
                'limit_user' => 2,
                // Dinaikkan dari 3.000: 5.000/bulan setara ~165 transaksi/hari,
                // cukup longgar untuk warung sehingga batas ini tidak terasa.
                'limit_transaksi_bulanan' => 5000,
                'harga_bulanan' => 39000,
                'harga_bulanan_device' => null, // Basic: BYOD saja.
                'fitur_json' => [
                    'kasir', 'tiga_mode', 'kasbon', 'stok_dasar',
                    'mode_offline', 'branding_struk', 'laporan_dasar',
                ],
                'urutan' => 1,
            ],
            [
                'nama_paket' => 'Pro',
                'slug' => 'pro',
                'limit_outlet' => 3,
                'limit_user' => 5,
                'limit_transaksi_bulanan' => null,
                'harga_bulanan' => 119000,
                'harga_bulanan_device' => 248000,
                'fitur_json' => [
                    'kasir', 'tiga_mode', 'kasbon', 'stok_lengkap', 'mode_offline',
                    'branding_struk', 'resep_bom', 'transfer_stok', 'purchase_order',
                    'laporan_lengkap', 'export', 'multi_outlet',
                ],
                'urutan' => 2,
            ],
            [
                'nama_paket' => 'Enterprise',
                'slug' => 'enterprise',
                // NULL = unlimited. Dijual per tenant, bukan per outlet.
                'limit_outlet' => null,
                'limit_user' => null,
                'limit_transaksi_bulanan' => null,
                'harga_bulanan' => 279000,
                'harga_bulanan_device' => 408000,
                'fitur_json' => [
                    'kasir', 'tiga_mode', 'kasbon', 'stok_lengkap', 'mode_offline',
                    'branding_struk', 'resep_bom', 'transfer_stok', 'purchase_order',
                    'laporan_lengkap', 'export', 'multi_outlet', 'forecasting',
                    'api', 'white_label', 'integrasi_marketplace', 'manajemen_device',
                ],
                'urutan' => 3,
            ],
        ];

        foreach ($paket as $data) {
            // updateOrCreate supaya seeder aman dijalankan ulang tanpa migrate:fresh.
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
