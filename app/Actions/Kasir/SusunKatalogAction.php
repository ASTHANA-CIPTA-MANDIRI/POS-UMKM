<?php

namespace App\Actions\Kasir;

use App\Models\Pelanggan\Customer;
use App\Models\Produk\Product;
use App\Models\Tenant\User;

/**
 * Menyusun seluruh bekal yang dibutuhkan layar kasir dalam satu bentuk.
 *
 * Dipakai DUA tempat dengan bentuk yang harus sama persis: ditanam di halaman saat
 * pertama dimuat, dan dilayani sebagai JSON untuk penyegaran tanpa memuat ulang.
 * Karena itu penyusunannya ditaruh di satu tempat ini saja.
 *
 * Pelanggan ikut dibawa karena kasbon mensyaratkan pelanggan, dan kasir harus tetap
 * bisa mencatat kasbon saat jaringan mati — jadi daftarnya perlu ada di perangkat
 * sebelum dibutuhkan.
 *
 * Daftar bill terbuka TIDAK dikirim. Isi sebuah bill tersimpan di perangkat yang
 * membukanya, jadi perangkat lain tidak bisa melayaninya — mengirimkan datanya hanya
 * mengundang layar menampilkan bill yang tidak bisa disentuh, dan itu membuat kasir
 * menempelkan pesanan ke meja yang salah.
 */
class SusunKatalogAction
{
    /** @return array<string, mixed> */
    public function execute(User $user): array
    {
        return [
            'produk' => $this->produk(),
            'pelanggan' => $this->pelanggan(),
            // Mode yang boleh dipilih kasir dibatasi pada yang diaktifkan outlet.
            // Pilihannya per-transaksi, bukan dikunci satu untuk seluruh outlet.
            'mode' => $user->outlet?->active_modes ?? ['langsung'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function produk(): array
    {
        return Product::query()
            ->with('kategori:id,nama,urutan')
            ->where('is_active', true)
            ->orderBy('nama_produk')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->getKey(),
                'nama' => $p->nama_produk,
                'harga' => (float) $p->harga_default,
                'satuan' => $p->satuan?->value,
                /*
                 * Barcode & SKU ikut dikirim supaya pemindaian bekerja OFFLINE.
                 *
                 * Kalau pencocokannya ditanyakan ke server tiap kali barang dipindai,
                 * memindai akan berhenti bekerja persis saat jaringan mati — padahal
                 * itu keadaan yang paling harus tetap jalan di kasir. Ukurannya kecil:
                 * dua kolom teks pendek per produk.
                 */
                'barcode' => $p->barcode,
                'sku' => $p->sku,
                // Satuan pecahan (kg, liter) perlu input desimal di layar kasir.
                'pecahan' => (bool) $p->satuan?->allowsFraction(),
                'kategori' => $p->kategori?->nama ?? 'Lain-lain',
                /*
                 * URL penuh, bukan path mentah. Klien menyimpan katalog ini di
                 * localStorage dan memakainya lagi saat offline — kalau yang
                 * dikirim path relatif, klien harus tahu cara menyusun URL-nya,
                 * dan itu pengetahuan yang tidak perlu dititipkan ke perangkat.
                 *
                 * asset(), BUKAN Storage::url(): Storage::url() selalu memakai APP_URL,
                 * jadi tablet yang membuka aplikasi lewat alamat LAN akan menerima URL
                 * ke "localhost" — yaitu ke tablet itu sendiri — dan seluruh gambar
                 * produk gagal dimuat. asset() mengikuti alamat yang sedang dipakai.
                 */
                'gambar' => $p->gambar_path !== null
                    ? asset('storage/'.$p->gambar_path)
                    : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pelanggan(): array
    {
        return Customer::query()
            ->orderBy('nama')
            ->limit(500)
            ->get(['id', 'nama', 'no_hp'])
            ->map(fn (Customer $c) => [
                'id' => $c->getKey(),
                'nama' => $c->nama,
                'no_hp' => $c->no_hp,
            ])
            ->all();
    }
}
