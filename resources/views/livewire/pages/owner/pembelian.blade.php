{{--
    Daftar nota belanja satu tenant (bisa disaring per outlet).

    Data dari komponen: $daftar (paginator PurchaseOrder), $notaRincian, $barisRincian
    (paginator PurchaseOrderItem, pageName 'baris'), $ringkasan, $outletTersedia,
    $outletDipakai.

    Bentuknya MENYALIN layar Stok, bukan merancang ulang: kartu angka setinggi 90px,
    x-kartu-alat untuk kepala + saringan, panel rincian dengan pola panel "Riwayat barang",
    kartu di <lg dan tabel table-fixed di ≥lg. Dua layar owner yang memakai dua bentuk
    kartu angka terbaca seperti dua aplikasi.

    Bahasa layarnya kata warung, bukan kata gudang: "nota belanja" bukan "purchase order",
    "beli dari" bukan "supplier", "barang sudah datang" bukan "diterima/draft".
--}}
@php
    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');

    /*
     * Status dokumen diterjemahkan ke kalimat yang dipakai orang warung.
     *
     * Setiap status disebut SATU-SATU dan `default` disisakan untuk nilai yang benar-benar
     * tidak dikenal — pola yang sama dengan layar Stok. Nota lama dari data demo bisa
     * berstatus 'draft'/'dikirim'; menyembunyikannya berarti menghilangkan dokumen yang
     * ditunjuk oleh mutasi stok yang sudah terjadi.
     */
    $labelStatus = fn (?\App\Enums\DocumentStatus $status) => match ($status) {
        \App\Enums\DocumentStatus::Diterima => 'Barang sudah datang',
        \App\Enums\DocumentStatus::Dibatalkan => 'Dibatalkan',
        \App\Enums\DocumentStatus::Dikirim => 'Masih di jalan',
        \App\Enums\DocumentStatus::Draft => 'Belum masuk stok',
        default => 'Belum diketahui',
    };

    $warnaStatus = fn (?\App\Enums\DocumentStatus $status) => match ($status) {
        \App\Enums\DocumentStatus::Diterima => 'hijau',
        \App\Enums\DocumentStatus::Dibatalkan => 'merah',
        \App\Enums\DocumentStatus::Dikirim => 'jingga',
        default => 'netral',
    };
@endphp

<div>
    {{-- ── Angka ringkasan ─────────────────────────────────────────────────
         Dua pertanyaan yang dibawa pemilik ke layar ini: "berapa uang saya yang keluar
         bulan ini" dan "dari berapa nota". Keduanya tidak bisa dikumpulkan dari tabel yang
         berhalaman 10 baris.

         Bentuknya SAMA PERSIS dengan kartu angka layar Stok: baris mendatar min 90px,
         lencana bundar di kiri, label kecil di atas angka. Di bawah sm ikonnya 36px dan
         angkanya 0,9375rem supaya nominal rupiah UTUH — angka uang yang terpotong lebih
         buruk daripada tidak ditampilkan, karena pembacanya menduga digit yang hilang.

         Kartu ketiga selebar penuh di ponsel: petak dua kolom dengan tiga kartu
         meninggalkan satu petak menganga di kanan bawah. --}}
    <div class="mt-2 mb-4 grid grid-cols-2 gap-3 sm:mt-3 sm:mb-5 lg:grid-cols-[1.4fr_minmax(0,1fr)_minmax(0,1fr)]">
        <div class="kartu flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem]">
            <span class="lencana-ikon size-9 bg-cream-deep text-terracotta sm:size-[3.25rem]">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <path d="M4 6.5h3l2 9h9l2-6.5H8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="10" cy="19" r="1.3" stroke="currentColor" stroke-width="1.4" />
                    <circle cx="17" cy="19" r="1.3" stroke="currentColor" stroke-width="1.4" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Belanja bulan ini</p>
                <p class="tabular text-[0.9375rem] leading-tight font-bold break-words text-ink sm:text-[1.25rem]">
                    {{ $rupiah($ringkasan['belanja']) }}
                </p>
                {{-- Dipendekkan sampai MUAT DUA BARIS di kolom ±135px pada 390px. Bentuk
                     panjangnya ("nota yang dibatalkan tidak ikut dihitung") terpotong di
                     tengah kata, dan keterangan yang terpotong di tengah kata lebih buruk
                     daripada keterangan yang lebih ringkas: pembacanya berhenti membaca. --}}
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug text-umber-soft">
                    tanpa nota yang dibatalkan
                </p>
            </div>
        </div>

        <div class="kartu flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem]">
            <span class="lencana-ikon size-9 bg-cream-deep text-umber sm:size-[3.25rem]">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <path d="M6 3.5h9l3 3v14H6v-17Zm3 6h6M9 13h6M9 16.5h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Jumlah nota</p>
                <p class="tabular text-[0.9375rem] leading-tight font-bold text-ink sm:text-[1.25rem]">
                    {{ $ringkasan['nota'] }} nota
                </p>
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug text-umber-soft">
                    tercatat bulan ini
                </p>
            </div>
        </div>

        <div class="kartu col-span-2 flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem] lg:col-span-1">
            <span class="lencana-ikon size-9 bg-cream-deep sm:size-[3.25rem] {{ $ringkasan['dibatalkan'] > 0 ? 'text-merah-deep' : 'text-umber-soft' }}">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.5" />
                    <path d="m9 9 6 6m0-6-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Nota dibatalkan</p>
                <p class="tabular text-[0.9375rem] leading-tight font-bold text-ink sm:text-[1.25rem]">
                    {{ $ringkasan['dibatalkan'] }} nota
                </p>
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug {{ $ringkasan['dibatalkan'] > 0 ? 'text-merah-deep' : 'text-umber-soft' }}">
                    stoknya sudah dikembalikan
                </p>
            </div>
        </div>
    </div>

    <x-kartu-alat
        judul="Nota belanja"
        jumlah="{{ $daftar->total() }}"
        keterangan="Nota yang tersimpan berarti barangnya sudah datang — stok outletnya langsung bertambah."
    >
        <x-slot:aksi>
            {{-- Tautan, bukan tombol: mencatat nota adalah halaman tersendiri dan bisa
                 dibuka di tab lain sambil daftar ini tetap terbuka. Tingginya 44px dan
                 seukuran isinya, SEJAJAR dengan judul seksinya sejak ponsel — bentuk yang
                 sama dengan "Hitung stok sekarang" di layar Stok, supaya pasangan
                 pergi-pulang antar layar owner terbaca sebagai satu bahasa.

                 Dua bentuk teks: "Catat nota belanja" (127px) memepet judul di 390px,
                 jadi di bawah 640px dipendekkan. Maknanya tidak berubah — kata "belanja"
                 sudah ada di judul seksinya. --}}
            <a href="{{ route('owner.pembelian.baru', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="tombol-utama h-11 w-auto shrink-0 px-4 text-[0.875rem]">
                <span class="tombol-ikon">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                </span>
                <span class="sm:hidden">Catat nota</span>
                <span class="hidden sm:inline">Catat nota belanja</span>
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            {{-- Pencarian selebar penuh, lalu kedua dropdown berbagi satu baris di ponsel —
                 pola layar Stok. Dropdown outlet TIDAK dibuat selebar kartu di sini karena
                 di layar ini ia hanya menyaring tampilan: salah membacanya tidak mengubah
                 satu data pun. (Di layar catat nota ia selebar penuh — di sana ia menentukan
                 ke cabang mana barangnya masuk.) --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-[1fr_13rem_13rem]">
                <div class="col-span-2 min-w-0 lg:col-span-1">
                    <label for="cari" class="sr-only">Cari nota belanja</label>
                    <div class="relative">
                        <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                             fill="none" aria-hidden="true">
                            <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
                               placeholder="Cari nomor nota atau nama tempat belanja…"
                               class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                    </div>
                </div>

                <div class="min-w-0">
                    <label for="status" class="sr-only">Saringan status nota</label>
                    <select id="status" wire:model.live="status"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Semua nota</option>
                        <option value="diterima">Barang sudah datang</option>
                        <option value="dibatalkan">Dibatalkan</option>
                    </select>
                </div>

                @if ($outletTersedia !== [])
                    <div class="min-w-0">
                        <label for="outlet" class="sr-only">Outlet</label>
                        <select id="outlet" wire:model.live="outletId"
                                class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                            <option value="">Semua outlet</option>
                            @foreach ($outletTersedia as $o)
                                <option value="{{ $o['id'] }}">{{ $o['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Rincian nota ────────────────────────────────────────────────────
         Panel DI DALAM alur halaman, bukan dialog mengapung — pola panel "Riwayat barang"
         di layar Stok. Nota belanja bulanan kelontong bisa 40 baris; dialog yang harus
         digulir menyembunyikan baris terakhirnya, padahal justru di situ orang mencari
         barang yang dicarinya.

         Halamannya punya penunjuk SENDIRI ('baris', diatur komponen) dan reset ke halaman 1
         tiap panel dibuka: kalau ikut 'page', membuka rincian dari halaman 3 daftar akan
         melompatkan daftarnya. --}}
    @if ($notaRincian !== null)
        @php
            /*
             * Nilai barangnya diturunkan dari angka yang TERSIMPAN (total + diskon − ongkir),
             * bukan dijumlah ulang dari baris yang sedang tampak.
             *
             * Barisnya berhalaman 10, jadi menjumlah $barisRincian hanya akan menghitung
             * halaman ini — dan angka "belanja" yang lebih kecil daripada totalnya membuat
             * pemilik menyimpulkan totalnya salah. Rumusnya kebalikan persis dari rumus di
             * CatatPembelianAction (total = subtotal − diskon + ongkir), jadi tidak ada
             * definisi kedua yang bisa bergeser sendiri.
             */
            $diskonNota = (float) $notaRincian->diskon;
            $ongkirNota = (float) $notaRincian->ongkos_kirim;
            $belanjaNota = (float) $notaRincian->total + $diskonNota - $ongkirNota;
        @endphp

        <div class="kartu mb-4 overflow-hidden sm:mb-5" wire:key="rincian-{{ $notaRincian->getKey() }}">
            {{-- Kepala panel: identitas nota di kiri, tiga keterangan di kanan, tombol tutup
                 di ujung. Di bawah 1024px ketiga kotak pindah ke barisnya sendiri
                 (`w-full` + order): berbagi baris dengan judul di 390px menyisakan ±40px per
                 kotak dan seluruh isinya luber. --}}
            <div class="flex flex-wrap items-start gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div class="order-1 min-w-0 flex-1">
                    <p class="eyebrow text-umber-soft">Isi nota</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="tabular text-[1.0625rem] font-bold text-ink">{{ $notaRincian->nomor_po }}</h2>
                        <x-lencana :warna="$warnaStatus($notaRincian->status)" :denyut="$notaRincian->status !== \App\Enums\DocumentStatus::Dibatalkan">
                            {{ $labelStatus($notaRincian->status) }}
                        </x-lencana>
                    </div>
                    <p class="mt-0.5 text-[0.75rem] text-umber-soft">
                        {{ $notaRincian->outlet?->outlet_name ?? 'outlet tidak diketahui' }}
                        · {{ $barisRincian instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $barisRincian->total() : $notaRincian->items_count ?? 0 }} baris barang
                        @if (filled($notaRincian->catatan))
                            · {{ $notaRincian->catatan }}
                        @endif
                    </p>
                </div>

                <div class="order-3 grid w-full min-w-0 grid-cols-3 gap-2 lg:order-2 lg:w-auto lg:max-w-lg lg:flex-1">
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Beli dari</p>
                        <p class="text-[0.8125rem] font-bold break-words text-ink">
                            {{ $notaRincian->supplier?->nama ?: '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Tanggal</p>
                        <p class="tabular text-[0.8125rem] font-bold text-ink">
                            {{ $notaRincian->tanggal?->locale('id')->translatedFormat('j M Y') ?? '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Total belanja</p>
                        <p class="tabular text-[0.8125rem] font-bold break-words text-ink">
                            {{ $rupiah($notaRincian->total) }}
                        </p>
                    </div>
                </div>

                <button type="button" wire:click="tutupRincian" aria-label="Tutup rincian nota {{ $notaRincian->nomor_po }}"
                        class="order-2 grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg border border-line text-umber transition-colors hover:bg-cream hover:text-ink lg:order-3">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            @if ($barisRincian->isEmpty())
                <div class="px-5 py-5 sm:px-6">
                    <x-kosong judul="Nota ini tidak berisi barang"
                              keterangan="Barisnya mungkin ikut terhapus. Nota tetap ditampilkan supaya mutasi stok yang menunjuknya masih bisa dibuka."
                              ikon="lembar" />
                </div>
            @else
                {{-- Kartu di <lg, tabel di ≥lg — pola yang sama dengan seluruh daftar owner. --}}
                <ul class="divide-y divide-line-soft lg:hidden">
                    @foreach ($barisRincian as $baris)
                        <li class="px-5 py-3.5" wire:key="baris-{{ $baris->getKey() }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[0.875rem] font-semibold text-ink">{{ $baris->namaItem() }}</p>
                                    <p class="tabular text-[0.75rem] text-umber-soft">
                                        {{ $angka($baris->qty_beli ?? $baris->qty) }}
                                        {{ $baris->satuan_beli ?: 'satuan' }}
                                        × {{ $rupiah($baris->harga_satuan) }}
                                    </p>
                                </div>
                                <p class="tabular shrink-0 text-[0.9375rem] font-bold text-ink">{{ $rupiah($baris->subtotal) }}</p>
                            </div>
                            {{-- Jumlah yang MASUK STOK ditulis terpisah dari jumlah yang dibeli:
                                 2 dus yang menjadi 24 pcs di kartu stok adalah keterangan yang
                                 paling sering ditanyakan saat angka stok terasa aneh. --}}
                            <p class="tabular mt-1 text-[0.75rem] text-umber">
                                masuk stok {{ $angka($baris->qty) }}
                                @if ($baris->isi_per_satuan_beli !== null)
                                    · 1 {{ $baris->satuan_beli ?: 'satuan' }} = {{ $angka($baris->isi_per_satuan_beli) }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden lg:block">
                    {{-- Lebar PERSEN + table-fixed, jumlahnya 100%: kolom tanpa lebar akan
                         menyerap seluruh sisa lebar panel dan lubangnya cuma berpindah tempat.
                         Judul DAN isi rata tengah (keputusan pemilik proyek). --}}
                    <table class="w-full table-fixed text-center">
                        <thead>
                            <tr class="border-b border-line">
                                <th class="w-[34%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                                <th class="w-[18%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah</th>
                                <th class="w-[16%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Masuk stok</th>
                                <th class="w-[16%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Harga</th>
                                <th class="w-[16%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah uang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            @foreach ($barisRincian as $baris)
                                <tr class="transition-colors hover:bg-cream/60" wire:key="baris-tabel-{{ $baris->getKey() }}">
                                    <td class="px-5 py-3 text-[0.8125rem]">
                                        <span class="block font-semibold text-ink">{{ $baris->namaItem() }}</span>
                                        {{-- Jenis barangnya, bukan satuannya: satuan sudah dicetak
                                             di bawah angka pada kolom Jumlah, dan mengulangnya di
                                             sini membuat dua kolom bersebelahan berbunyi "kg" dua
                                             kali tanpa menambah satu keterangan pun. --}}
                                        <span class="tabular block text-[0.75rem] text-umber-soft">
                                            {{ $baris->product_id !== null ? 'Produk' : 'Bahan baku' }}
                                            @if ($baris->isi_per_satuan_beli !== null)
                                                · 1 {{ $baris->satuan_beli ?: 'satuan' }} = {{ $angka($baris->isi_per_satuan_beli) }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem]">
                                        <span class="font-semibold text-ink">{{ $angka($baris->qty_beli ?? $baris->qty) }}</span>
                                        <span class="block text-[0.6875rem] text-umber-soft">{{ $baris->satuan_beli ?: '—' }}</span>
                                    </td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem] text-umber">{{ $angka($baris->qty) }}</td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem] text-umber">{{ $rupiah($baris->harga_satuan) }}</td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem] font-bold text-ink">{{ $rupiah($baris->subtotal) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Navigasi halaman rincian SELALU dirender selama ada halaman berikutnya:
                 daftar yang memotong diam-diam membuat orang menyimpulkan notanya cuma
                 berisi 10 baris, lalu menganggap totalnya salah. --}}
            @if ($barisRincian instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $barisRincian->hasPages())
                <div class="border-t border-line-soft px-5 py-3 sm:px-6">
                    {{ $barisRincian->links() }}
                </div>
            @endif

            {{-- Kaki panel: uang nota + jalan keluar kalau notanya salah.
                 Empat kotak yang sama bentuknya dengan kotak keterangan di kepala panel —
                 satu kebiasaan untuk hal yang sama, bukan dua. --}}
            <div class="border-t border-line px-5 py-4 sm:px-6">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Harga barang</p>
                        <p class="tabular text-[0.875rem] font-bold break-words text-ink">{{ $rupiah($belanjaNota) }}</p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Potongan</p>
                        <p class="tabular text-[0.875rem] font-bold break-words {{ $diskonNota > 0 ? 'text-hijau-tua' : 'text-umber-soft' }}">
                            {{ $diskonNota > 0 ? '− '.$rupiah($diskonNota) : '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Ongkos kirim</p>
                        <p class="tabular text-[0.875rem] font-bold break-words {{ $ongkirNota > 0 ? 'text-ink' : 'text-umber-soft' }}">
                            {{ $ongkirNota > 0 ? '+ '.$rupiah($ongkirNota) : '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line bg-cream/60 px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Total belanja</p>
                        <p class="tabular text-[0.875rem] font-bold break-words text-terracotta">{{ $rupiah($notaRincian->total) }}</p>
                    </div>
                </div>

                @if ($notaRincian->status !== \App\Enums\DocumentStatus::Dibatalkan)
                    {{-- Pembatalan diletakkan DI SINI, bukan sebagai ikon di setiap baris
                         daftar: yang dibatalkan adalah seluruh nota beserta mutasi stoknya,
                         dan tindakan sebesar itu tidak boleh berjarak satu ketukan jempol
                         dari tombol "lihat".

                         Dua langkah, pola yang sama dengan hapus produk — bedanya keadaannya
                         disimpan Alpine, bukan komponen: pertanyaannya hanya hidup selama
                         panelnya terbuka dan tidak perlu diketahui server. `x-cloak` menutup
                         kedipan sebelum Alpine hidup, dan tombol "Ya" ikut menutup
                         pertanyaannya supaya panel yang dirender ulang tidak tertinggal dalam
                         keadaan bertanya. --}}
                    <div class="mt-3" x-data="{ tanya: false }">
                        <div x-show="! tanya" class="flex">
                            <button type="button" x-on:click="tanya = true"
                                    class="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-line px-4 text-[0.8125rem] font-semibold text-umber transition-colors hover:border-merah/40 hover:bg-merah/5 hover:text-merah-tua sm:w-auto">
                                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                    <path d="M4 6h12M8 6V4.5h4V6m-6 0 .8 10h6.4L14 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Batalkan nota
                            </button>
                        </div>

                        <div x-cloak x-show="tanya">
                            <p class="text-[0.8125rem] text-ink">
                                <span class="font-bold">Batalkan nota {{ $notaRincian->nomor_po }}?</span>
                                Stok yang masuk dari nota ini dikembalikan seperti sebelum dicatat. Notanya tetap
                                tersimpan supaya riwayat barangnya masih bisa dibuka.
                            </p>
                            <div class="mt-2.5 flex gap-2">
                                <button type="button" wire:click="batalkan('{{ $notaRincian->getKey() }}')"
                                        x-on:click="tanya = false" wire:loading.attr="disabled"
                                        class="tombol-bahaya h-11 flex-1 cursor-pointer px-4 text-[0.8125rem] sm:flex-none">
                                    Ya, batalkan nota
                                </button>
                                <button type="button" x-on:click="tanya = false"
                                        class="h-11 flex-1 cursor-pointer rounded-xl border border-line px-4 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream sm:flex-none">
                                    Tidak jadi
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Daftar nota ─────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong judul="Belum ada nota belanja"
                  keterangan="Catat belanja begitu barangnya sampai di warung — stok outletnya langsung bertambah dan harga belinya ikut diperbarui."
                  ikon="lembar">
            <x-slot:aksi>
                <a href="{{ route('owner.pembelian.baru', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
                   wire:navigate class="tombol-utama h-11 px-5 text-[0.875rem]">
                    Catat nota belanja
                </a>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Kartu di <lg, tabel di ≥lg. Tabel yang dipaksa masuk ke ponsel menuntut gulir
             mendatar, dan kolom uang justru yang pertama hilang dari pandangan. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $nota)
                <div class="kartu p-4" wire:key="kartu-nota-{{ $nota->getKey() }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="tabular text-[0.9375rem] font-bold text-ink">{{ $nota->nomor_po }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $nota->tanggal?->locale('id')->translatedFormat('j M Y') ?? 'tanpa tanggal' }}
                                · {{ $nota->outlet?->outlet_name ?? 'outlet tidak diketahui' }}
                            </p>
                        </div>
                        <x-lencana :warna="$warnaStatus($nota->status)" :denyut="$nota->status !== \App\Enums\DocumentStatus::Dibatalkan" class="shrink-0">
                            {{ $labelStatus($nota->status) }}
                        </x-lencana>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Beli dari</p>
                            <p class="text-[0.875rem] font-bold break-words text-ink">{{ $nota->supplier?->nama ?: '—' }}</p>
                        </div>
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Total belanja</p>
                            <p class="tabular text-[0.875rem] font-bold break-words text-ink">{{ $rupiah($nota->total) }}</p>
                        </div>
                    </div>

                    <p class="mt-2 text-[0.75rem] text-umber">
                        {{ $nota->items_count }} baris barang
                    </p>

                    {{-- Bergaris rambut, BUKAN isian penuh. Sepuluh kartu nota berarti sepuluh
                         batang berwarna penuh berjajar ke bawah, dan begitu semuanya berwarna
                         tidak ada lagi yang menonjol — termasuk satu-satunya tindakan utama
                         layar ini, "Catat nota belanja" di kepala halaman.

                         `.tombol-kedua` + `.tombol-ikon`, bentuk yang sama dengan tombol
                         pembuka rincian di tabel ≥lg: satu layar tidak boleh punya dua bentuk
                         untuk tindakan yang sama. aria-label MENYEBUT nomor notanya — teksnya
                         berbunyi "Lihat isi nota" sepuluh kali, dan pembaca layar yang
                         mendengar kalimat identik sepuluh kali tidak tahu ia sedang di nota
                         yang mana. --}}
                    <div class="mt-3 flex">
                        <button type="button" wire:click="bukaRincian('{{ $nota->getKey() }}')"
                                class="tombol-kedua h-11 w-full cursor-pointer px-3 text-[0.8125rem]">
                            <span class="tombol-ikon">
                                @if ($rincianId === $nota->getKey())
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                    </svg>
                                @else
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M4 5.5h9M4 10h12M4 14.5h7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                    </svg>
                                @endif
                            </span>
                            {{ $rincianId === $nota->getKey() ? 'Tutup rincian' : 'Lihat isi nota' }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full table-fixed text-center">
                <thead>
                    <tr class="border-b border-line">
                        {{-- Lebar persen berjumlah 100%. Tanggal menempel DI BAWAH nomor nota,
                             bukan berkolom sendiri: keduanya satu identitas ("PB-20260805-001,
                             5 Agt"), dan kolom terpisah membuat mata memasangkannya sendiri
                             setiap baris. --}}
                        {{-- LIMA kolom, bukan enam. "Barang: 3 baris" dulu berkolom sendiri dan
                             menyisakan 18% lebar tabel untuk dua kata — sementara kolom Status
                             hanya kebagian 128px dan lencana "Barang sudah datang" (±165px)
                             TERPOTONG oleh tepi kartunya. Terpotongnya tidak terhitung sebagai
                             gulir mendatar (kartunya overflow-hidden), jadi ia hanya terlihat
                             di potretnya. Jumlah barisnya sekarang menempel di bawah total —
                             tempatnya memang di situ: ia menjelaskan uang yang di atasnya. --}}
                        {{-- Nota 1% lebih lebar dan selnya berpadding tipis (px-2, bukan px-5):
                             nomor notanya sekarang berdiri di dalam tombol berikon, dan tombol
                             itu butuh ±180px. Yang ditambah bukan hanya persennya — 24px
                             padding sel yang dibebaskan lebih besar daripada 1% lebar tabel.
                             Sisa 1% diambil dari Outlet dan diberikan ke Total belanja, karena
                             nominal yang pecah dua baris adalah cacat yang sudah diatur
                             tersendiri di CLAUDE.md. --}}
                        <th class="w-[23%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nota</th>
                        <th class="w-[21%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Beli dari</th>
                        <th class="w-[17%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Outlet</th>
                        <th class="w-[17%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Total belanja</th>
                        <th class="w-[22%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $nota)
                        <tr @class([
                                'transition-colors hover:bg-cream/60',
                                'bg-cream/60' => $rincianId === $nota->getKey(),
                            ])
                            wire:key="baris-nota-{{ $nota->getKey() }}">
                            <td class="px-3 py-3.5">
                                {{-- Seluruh identitas nota jadi satu tombol: itu satu-satunya
                                     tindakan baris ini, dan tombol ikon kecil di ujung kanan
                                     membuat orang harus membidik sesudah membaca kiri.

                                     Dan tombolnya BERBENTUK tombol — `.tombol-kedua` bergaris
                                     rambut + `.tombol-ikon`, bentuk yang sama dengan kartu di
                                     <lg. Bentuk lama hanya teks berwarna terracotta: satu-satunya
                                     petunjuk bahwa ia bisa ditekan adalah warnanya, dan pemilik
                                     proyek bertanya persis itu ("bagaimana saya tahu
                                     PB-20260801-002 bisa diklik kalau tidak ada ikonnya?").
                                     Penandanya tidak boleh menunggu hover: layar ini dipakai di
                                     tablet dan HP, dan di sana hover TIDAK ADA — cacat yang sama
                                     sudah pernah terjadi pada tombol hapus yang kelabu sampai
                                     disorot. Tingginya min 44px, jadi sasaran sentuhnya sah juga
                                     di tablet lanskap yang memakai tata letak tabel ini.

                                     Selebar selnya (`w-full` + `justify-between`), bukan
                                     seukuran isinya: tanggalnya panjangnya berbeda-beda
                                     ("6 Agt 2026" vs "31 Jul 2026"), jadi tombol yang menyusut
                                     ke isinya membuat ikonnya berpindah tempat sampai 7px dari
                                     baris ke baris (terukur) — dan kolom yang tepinya bergerigi
                                     membuat mata mencari ikonnya lagi setiap turun satu baris.
                                     Nomor notanya rata kiri di posisi yang sama tiap baris. --}}
                                <button type="button" wire:click="bukaRincian('{{ $nota->getKey() }}')"
                                        class="tombol-kedua min-h-11 w-full cursor-pointer justify-between py-1.5 pr-1.5 pl-2.5 text-left"
                                    <span class="min-w-0">
                                        <span class="tabular block truncate text-[0.875rem] font-bold text-terracotta">{{ $nota->nomor_po }}</span>
                                        <span class="tabular block truncate text-[0.75rem] font-normal text-umber-soft">
                                            {{ $nota->tanggal?->locale('id')->translatedFormat('j M Y') ?? '—' }}
                                        </span>
                                    </span>
                                    <span class="tombol-ikon">
                                        @if ($rincianId === $nota->getKey())
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                            </svg>
                                        @else
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M4 5.5h9M4 10h12M4 14.5h7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            </td>
                            <td class="px-5 py-3.5 text-[0.875rem] text-ink">{{ $nota->supplier?->nama ?: '—' }}</td>
                            <td class="px-3 py-3.5 text-[0.8125rem] text-umber">{{ $nota->outlet?->outlet_name ?: '—' }}</td>
                            <td class="tabular px-3 py-3.5">
                                <span class="block text-[0.9375rem] font-bold text-ink">{{ $rupiah($nota->total) }}</span>
                                <span class="block text-[0.6875rem] text-umber-soft">
                                    {{ $nota->items_count > 0 ? $nota->items_count.' baris barang' : '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-3.5">
                                <x-lencana :warna="$warnaStatus($nota->status)" :denyut="$nota->status !== \App\Enums\DocumentStatus::Dibatalkan">
                                    {{ $labelStatus($nota->status) }}
                                </x-lencana>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif
</div>
