<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data demo untuk pengembangan lokal.
 *
 * CATATAN: JANGAN tambahkan trait WithoutModelEvents di sini. Trait itu mematikan
 * model event, sedangkan trait BelongsToTenant mengisi tenant_id lewat event
 * "creating" — mematikannya membuat seluruh data demo tersimpan tanpa tenant_id.
 *
 * Urutan penting: paket langganan harus ada sebelum tenant dibuat, dan antrean
 * offline disinkronkan paling akhir karena butuh outlet, kasir, produk, serta sesi
 * kas yang masih terbuka.
 *
 * ───────────────────────────────────────────────────────────────────────────────
 * KREDENSIAL DEMO (khusus lokal)
 *
 * Halaman masuk pemilik  → /masuk        (email + password, semuanya "password")
 *   superadmin@pos-umkm.test   Super Admin      — dasbor platform, lintas merchant
 *   benjamin@warteg.test       Owner            Warung Makan Benjamin (2 outlet)
 *   rina@warteg.test           Manager Outlet   dikunci ke Benjamin Pusat saja
 *   sari@kelontong.test        Owner            Toko Sembako Sari
 *   hendra@depot.test          Owner            Depot Air & Laundry (status TRIAL)
 *
 * Halaman masuk kasir    → /masuk/kasir  (username + PIN, semuanya "123456")
 *   ani.pusat      Kasir  Benjamin Pusat            perangkat WAJIB TAB-BJM-0001
 *   dapur.pusat    Dapur  Benjamin Pusat            perangkat boleh dikosongkan
 *   sri.seturan    Kasir  Benjamin Cabang Seturan   boleh kosong / TAB-BJM-0003
 *   dewi.sari      Kasir  Toko Sari                 perangkat WAJIB BYOD-SARI-0001
 *   yuli.depot     Kasir  Depot Bersih              perangkat WAJIB BYOD-BRSH-0001
 *
 * Akun bertanda WAJIB terikat ke satu perangkat lewat kolom device_id_terikat, jadi
 * nomor seri kosong akan DITOLAK — itu perilaku yang diminta bagian 3.2.E dokumen
 * bisnis, bukan bug. Akun tanpa binding boleh mengosongkannya, tapi kalau diisi
 * nomor serinya harus perangkat milik outletnya sendiri.
 *
 * Contoh untuk menguji penolakan: masuk sebagai ani.pusat dengan TAB-BJM-0003
 * (perangkat outlet Cabang) — harus gagal.
 * ───────────────────────────────────────────────────────────────────────────────
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Daftar akun yang dicetak ke terminal setelah seeding.
     *
     * @var array<int, array<int, string>>
     */
    private const AKUN_PEMILIK = [
        ['superadmin@pos-umkm.test', 'Super Admin', 'lintas merchant'],
        ['benjamin@warteg.test', 'Owner', 'Warung Makan Benjamin'],
        ['rina@warteg.test', 'Manager Outlet', 'Benjamin Pusat saja'],
        ['sari@kelontong.test', 'Owner', 'Toko Sembako Sari'],
        ['hendra@depot.test', 'Owner', 'Depot Air & Laundry (trial)'],
    ];

    /**
     * @var array<int, array<int, string>>
     */
    private const AKUN_KASIR = [
        ['ani.pusat', 'Kasir', 'Benjamin Pusat', 'TAB-BJM-0001 (wajib)'],
        ['dapur.pusat', 'Dapur', 'Benjamin Pusat', 'boleh dikosongkan'],
        ['sri.seturan', 'Kasir', 'Benjamin Cabang Seturan', 'boleh dikosongkan'],
        ['dewi.sari', 'Kasir', 'Toko Sari', 'BYOD-SARI-0001 (wajib)'],
        ['yuli.depot', 'Kasir', 'Depot Bersih', 'BYOD-BRSH-0001 (wajib)'],
    ];

    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            SuperAdminSeeder::class,
            WartegSeeder::class,
            KelontongSeeder::class,
            DepotLaundrySeeder::class,
            OfflineQueueSeeder::class,
        ]);

        $this->cetakKredensial();
    }

    /**
     * Mencetak daftar akun ke terminal begitu seeding selesai, supaya tidak perlu
     * dicari-cari di berkas seeder.
     *
     * Dilewati di production: kredensial tidak boleh sampai ke log server.
     */
    private function cetakKredensial(): void
    {
        if ($this->command === null || app()->environment('production')) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Akun pemilik — buka /masuk, password semuanya "password"');
        $this->command->table(['Email', 'Peran', 'Cakupan data'], self::AKUN_PEMILIK);

        $this->command->info('Akun kasir — buka /masuk/kasir, PIN semuanya "123456"');
        $this->command->table(['Username', 'Peran', 'Outlet', 'Nomor seri perangkat'], self::AKUN_KASIR);

        $this->command->line('  Akun bertanda (wajib) terikat ke satu perangkat; nomor seri kosong akan ditolak.');
        $this->command->newLine();
    }
}
