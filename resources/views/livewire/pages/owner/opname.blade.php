{{--
    Lembar hitung fisik (stok opname).

    Data dari komponen: $daftar (paginator), $alasanTersedia, $jumlahTerisi,
    $outletTersedia, $outletDipakai. Ikatan per baris di-key id barang:
    fisik.<kunci> / alasan.<kunci> / catatan.<kunci> — dan galatnya di nama yang sama.

    DUA keputusan tampilan yang menentukan halaman ini terpakai atau tidak:

    1. **Selisih dihitung di Alpine, bukan di server.** Pemilik mengetik 80 baris; satu
       perjalanan Livewire per ketikan berarti menunggu jaringan di setiap angka, dan
       sesudah sepuluh baris ia berhenti memakai fitur ini. Ikatan ke server memakai
       `.blur` — satu permintaan per baris SELESAI diketik, bukan per huruf. Itu juga yang
       membuat angka "N baris terisi" di bar bawah ikut bergerak, dan yang mengisi
       $sistemSaatDibuka (lihat Opname::updatedFisik) sehingga kartu stok bisa menjelaskan
       selisih yang terjadi karena kasir masih berjualan saat penghitungan berlangsung.

    2. **Angka "baris terisi" adalah SELURUH lembar, bukan halaman ini.** Itulah satu
       satunya hal yang meyakinkan orang bahwa 40 baris yang diketik di halaman 1 tidak
       hilang saat ia pindah ke halaman 2 — dan tanpa keyakinan itu ia akan mengulang
       penghitungannya dari awal.
--}}
@php
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');

    /*
     * Selisih versi server, dipakai sebagai isi awal sel selisih.
     *
     * Gunanya bukan menggantikan Alpine: kalau baris sudah pernah diketik (mis. lembarnya
     * dibuka lagi, atau simpan gagal dan barisnya dibiarkan terisi), selnya harus sudah
     * menunjukkan angka yang benar sebelum satu tombol pun ditekan.
     */
    $bedaServer = function (array $b) use ($fisik): ?float {
        $nilai = $fisik[$b['kunci']] ?? null;

        if ($nilai === null || $nilai === '' || ! is_numeric($nilai)) {
            return null;
        }

        return round((float) $nilai - (float) $b['sistem'], 3);
    };

    // Nama barang per kunci untuk ringkasan galat: pesan validasi baris fisik menyebut
    // "jumlah fisik" tanpa nama barangnya, dan galat tanpa nama tidak bisa ditindaklanjuti.
    // $namaPerKunci datang dari komponen, BUKAN dari $daftar->items(): baris yang gagal
    // bisa berada di halaman lain, dan nama yang diambil dari halaman ini saja akan
    // menampilkannya sebagai "Baris lain". Lihat Opname::namaPerKunci().

    /*
     * Nama outlet per id — dipakai blok peringatan kunci outlet DAN bar simpan.
     *
     * Sumbernya $outletTersedia, yaitu daftar yang SAMA dengan isi dropdown: hanya outlet
     * yang memang boleh dilihat pengguna ini yang punya nama di sini. Sengaja tidak mencari
     * nama lewat model — $outletDiminta lahir dari nilai yang dikirim klien dan bisa berisi
     * id apa pun, jadi pencarian langsung akan mencetak nama usaha tenant lain di layar
     * pemilik ini (lihat Opname::namaOutletDiminta(), yang menjaga hal yang sama).
     *
     * Untuk peran yang terkunci ke satu outlet, $outletTersedia memang kosong: dropdownnya
     * tidak dirender, tidak ada cabang yang bisa tertukar, dan kalimatnya jatuh ke bentuk
     * tanpa nama.
     */
    $namaOutlet = collect($outletTersedia)->pluck('nama', 'id');
    $namaTerkunci = $namaOutlet[$outletTerkunci] ?? null;
    $namaDipakai = $namaOutlet[$outletDipakai] ?? null;
@endphp

<div>
    {{-- mt-2/sm:mt-3: navbar mengapung (`sticky top-4 mb-2`), jadi tanpa ini kartu pertama
         halaman menempel ke kartu judul sementara jarak antar-bagian di bawahnya 16/20px. --}}
    <x-kartu-alat
        class="mt-2 sm:mt-3"
        judul="Lembar hitung fisik"
        jumlah="{{ $daftar->total() }}"
        keterangan="Isi jumlah yang benar-benar ada di rak. Kolom kosong berarti belum dihitung, bukan nol · desimal pakai titik (1.5)."
    >
        <x-slot:aksi>
            {{-- Selebar kartu di ponsel, 48px: ini satu-satunya jalan keluar dari lembar
                 hitung, dan sebagai kotak kecil di pojok kanan judul ia hampir tidak
                 terlihat di layar 390px. Tetap bergaris rambut, bukan isian penuh — tombol
                 utama layar ini adalah SIMPAN di bar bawah, dan dua tombol berwarna penuh di
                 satu layar membuat keduanya berhenti berarti. --}}
            <a href="{{ route('owner.stok', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-line bg-white px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:h-11 sm:w-auto sm:text-[0.875rem]">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Daftar stok
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            {{-- Empat kolom di layar lebar, bukan tiga + satu yang menggantung.
                 Dengan dropdown outlet dipaksa ke barisnya sendiri (`col-span-full w-64`),
                 baris kedua kartu ini berisi satu kontrol dan bidang kosong seluas tiga
                 perempat lebarnya. --}}
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_11rem_12rem] xl:grid-cols-[minmax(0,1fr)_11rem_12rem_14rem]">
                <div class="min-w-0">
                    <label for="cari" class="sr-only">Cari barang</label>
                    <div class="relative">
                        <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                             fill="none" aria-hidden="true">
                            <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
                               placeholder="Cari nama barang, SKU, atau barcode…"
                               class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                    </div>
                </div>

                <div class="min-w-0">
                    <label for="jenis" class="sr-only">Jenis barang</label>
                    <select id="jenis" wire:model.live="jenis"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Produk &amp; bahan</option>
                        <option value="produk">Produk saja</option>
                        <option value="bahan">Bahan baku saja</option>
                    </select>
                </div>

                <div class="min-w-0">
                    <label for="status" class="sr-only">Saringan status</label>
                    <select id="status" wire:model.live="status"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Semua status</option>
                        <option value="minus">Minus</option>
                        <option value="habis">Habis</option>
                        <option value="menipis">Menipis</option>
                        <option value="aman">Aman</option>
                        <option value="perlu_diperiksa">Perlu dihitung ulang</option>
                        <option value="belum_pernah">Belum pernah diopname</option>
                    </select>
                </div>

                @if ($outletTersedia !== [])
                    <div class="min-w-0 lg:col-span-full xl:col-span-1">
                        <label for="outlet" class="sr-only">Outlet</label>
                        <select id="outlet" wire:model.live="outletId"
                                class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                            @foreach ($outletTersedia as $o)
                                <option value="{{ $o['id'] }}">{{ $o['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Pergantian cabang yang DITOLAK ─────────────────────────────────────
         MENETAP di halaman, bukan toast, dan itu bukan pilihan gaya: blok ini membawa
         tombol keputusannya. Toast hilang sendiri sesudah beberapa detik, dan bersama
         toast itu ikut hilang satu-satunya jalan pemilik untuk benar-benar pindah cabang —
         yang tersisa hanyalah mengosongkan 120 kolom satu per satu.

         Tombol "buang" WAJIB ada di sini. Satu angka nyasar (nol tersenggol di baris yang
         tidak sedang dilihat) tidak boleh membekukan dropdown selamanya, karena dengan 10
         baris per halaman pemiliknya harus menyisir 12 halaman untuk menemukan barisnya.

         Jumlah barisnya disebut DI TOMBOLNYA. Itu langkah konfirmasinya — pola dua langkah
         yang sama dengan hapus produk, jadi tidak ada dialog tambahan di atasnya. --}}
    @if ($outletDiminta !== null)
        @php
            // Dua bentuk kalimat, dan yang tanpa nama bukan kelalaian: id di luar
            // outletTersedia() sengaja tidak diberi nama supaya nama outlet tenant lain
            // tidak bocor ke layar. Yang dicetak dalam keadaan itu adalah kalimat tanpa
            // nama — BUKAN id mentahnya, yang berupa UUID dan tidak berarti apa pun.
            $sebutTerkunci = $namaTerkunci ?? 'cabang tempat angkanya dihitung';
            $sebutDiminta = $namaOutletDiminta ?? 'cabang yang baru dipilih';
        @endphp

        <div role="alert" class="kartu mb-4 border border-jingga/30 px-5 py-4 sm:mb-5 sm:px-6">
            <div class="flex items-start gap-3">
                <svg viewBox="0 0 20 20" class="mt-0.5 size-4 shrink-0 text-jingga-tua" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                    <path d="M10 6.5v4M10 13.4v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <div class="min-w-0">
                    <p class="text-[0.875rem] font-bold text-jingga-tua">
                        Lembar ini masih berisi hitungan {{ $namaTerkunci ?? 'cabang lain' }}
                    </p>
                    <p class="mt-0.5 text-[0.8125rem] text-umber">
                        <span class="tabular font-semibold text-ink">{{ $jumlahTerisi }}</span>
                        baris sudah dihitung untuk {{ $sebutTerkunci }}, sedangkan yang tadi dipilih {{ $sebutDiminta }}.
                        Jumlah di rak {{ $sebutTerkunci }} tidak berlaku untuk {{ $sebutDiminta }}: kalau tersimpan ke
                        cabang yang salah, stok kedua cabang jadi salah dan tidak ada catatan yang menunjukkan kenapa.
                    </p>
                </div>
            </div>

            {{-- Grid satu kolom di ponsel, sebaris di ≥sm: teksnya panjang karena memuat nama
                 cabang, dan tombol lebar-tetap di 390px melebarkan halaman. --}}
            <div class="mt-3 grid gap-2 sm:flex sm:flex-wrap">
                <button type="button" wire:click="simpan" wire:loading.attr="disabled"
                        class="min-h-11 cursor-pointer rounded-xl bg-terracotta px-4 py-2.5 text-[0.8125rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60">
                    Simpan hitungan {{ $sebutTerkunci }} dulu
                </button>

                <button type="button" wire:click="pindahOutlet('{{ $outletDiminta }}')" wire:loading.attr="disabled"
                        class="min-h-11 cursor-pointer rounded-xl bg-merah-deep px-4 py-2.5 text-[0.8125rem] font-bold text-white transition hover:brightness-110 disabled:opacity-60">
                    Buang {{ $jumlahTerisi }} baris, pindah ke {{ $sebutDiminta }}
                </button>

                <button type="button" wire:click="tetapDiOutlet"
                        class="min-h-11 cursor-pointer rounded-xl border border-line px-4 py-2.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                    Tetap di {{ $sebutTerkunci }}
                </button>
            </div>
        </div>
    @endif

    {{-- Ringkasan galat SELURUH lembar.
         Validasi berjalan atas semua baris terisi, termasuk yang sedang tidak tampak —
         jadi tanpa ringkasan ini, satu baris di halaman 3 bisa menahan simpan tanpa
         penjelasan apa pun di layar yang sedang dilihat. --}}
    @if ($errors->any())
        <div role="alert" class="kartu mb-4 border border-merah/30 px-5 py-4 sm:mb-5 sm:px-6">
            <p class="text-[0.875rem] font-bold text-merah-deep">
                {{ $errors->count() }} baris belum bisa disimpan
            </p>
            {{-- Dua kalimat berbeda karena dua keadaan yang berbeda, dan keliru memilihnya
                 berarti memberi keterangan yang salah tentang data yang sudah berubah:
                 galat validasi menahan seluruh lembar, sedangkan kegagalan sesudah simpan
                 menyisakan baris-baris yang SUDAH tercatat. Pemilik yang membaca "tidak ada
                 yang tersimpan" padahal sebagiannya sudah masuk akan menghitung ulang
                 barang yang sudah benar. --}}
            <p class="mt-0.5 text-[0.8125rem] text-umber">
                @if ($sebagianTersimpan)
                    Baris lain sudah tersimpan. Yang di bawah ini belum, dan angkanya masih terisi — perbaiki lalu simpan lagi.
                @else
                    Tidak ada satu baris pun yang tersimpan sampai semuanya benar — angka yang sudah diketik tetap ada.
                @endif
            </p>
            <ul class="mt-2 space-y-1">
                @foreach ($errors->keys() as $kunciGalat)
                    @php $kunciBaris = str($kunciGalat)->after('.')->toString(); @endphp
                    <li class="text-[0.8125rem] text-ink">
                        <span class="font-semibold">{{ $namaPerKunci[$kunciBaris] ?? 'Baris lain' }}:</span>
                        {{ $errors->first($kunciGalat) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- RUANG BAWAH sebesar bar simpan, dan itu bukan hiasan: bar di bawah menempel
         (sticky), jadi tanpa ruang ini ia berdiri tepat di atas dua baris terakhir tabel —
         terukur di 1280: baris "Minyak Goreng" dan "Kerupuk" tidak bisa dibaca maupun
         diketik. Bar-nya menarik dirinya kembali ke dalam ruang ini lewat margin negatif,
         jadi halaman tidak menjadi lebih panjang dan tidak ada lubang kosong di kakinya.

         Angkanya DIUKUR, bukan dikira — tinggi bar itu berubah menurut lebar layar karena
         tombol dan kalimatnya berpindah baris: 177px di 390, 127px di 768, 71px di 1280.
         Ruangnya dibuat sedikit lebih besar daripada masing-masing (192 / 144 / 88), dan
         margin negatifnya sedikit lebih kecil daripada ruangnya supaya barnya tetap DI DALAM
         ruang itu. Kalau nanti isi barnya bertambah, ukur ulang di tiga lebar itu. --}}
    <div class="pb-[12rem] sm:pb-36 xl:pb-[5.5rem]">
    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada barang yang cocok"
                  keterangan="Coba ubah kata pencarian atau saringannya. Menu berbasis resep tidak dihitung di sini — yang dihitung adalah bahan bakunya.">
            <x-slot:aksi>
                <button type="button" wire:click="$set('status', 'semua')"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Tampilkan semua
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Kartu di <lg, tabel di ≥lg — pola yang sama dengan daftar produk & stok. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $b)
                @php
                    $kunci = $b['kunci'];
                    $beda = $bedaServer($b);
                    $satuan = $b['satuan_dasar'] ?? $b['satuan'] ?? '';
                @endphp

                {{-- Kuncinya memuat outlet — lihat alasan lengkapnya di wire:key baris tabel. --}}
                <div wire:key="kartu-{{ $outletDipakai }}-{{ $kunci }}"
                     x-data="barisOpname(@js((string) ($fisik[$kunci] ?? '')), @js((float) $b['sistem']), @js((string) ($alasan[$kunci] ?? '')))"
                     x-bind:class="selisih ? 'border-jingga bg-jingga/5' : 'border-transparent'"
                     @class(['kartu border p-4', 'border-jingga bg-jingga/5' => $beda !== null && $beda !== 0.0, 'border-transparent' => $beda === null || $beda === 0.0])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[0.9375rem] font-bold text-ink">{{ $b['nama'] }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                · {{ $b['kode'] ?: 'tanpa kode' }}
                                · sistem
                                <span class="tabular font-semibold text-ink">
                                    {{ $b['punya_baris'] ? trim($angka($b['sistem']).' '.$satuan) : 'belum dihitung' }}
                                </span>
                            </p>
                        </div>
                        @if ($b['perlu_diperiksa'])
                            <span class="shrink-0 rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                perlu dihitung ulang
                            </span>
                        @endif
                    </div>

                    {{-- Selisih dulu berupa KOTAK setinggi 48px di samping kolom isian, dan di
                         lembar yang belum diketik seluruhnya berisi "—": delapan kotak besar
                         yang tidak mengatakan apa pun. Sekarang ia satu baris kecil di bawah
                         kolomnya — tetap ada sebelum satu tombol pun ditekan, tapi tidak lagi
                         menuntut ruang sebesar kolom yang benar-benar diisi. --}}
                    <div class="mt-3">
                        <div class="flex items-baseline justify-between gap-2">
                            <label for="fisik-hp-{{ $kunci }}" class="text-[0.8125rem] font-semibold text-ink">
                                Jumlah fisik {{ $satuan !== '' ? '('.$satuan.')' : '' }}
                            </label>
                            <span class="text-[0.75rem] text-umber-soft">
                                selisih
                                <span class="tabular font-bold"
                                      x-bind:class="selisih ? 'text-jingga-tua' : 'text-umber-soft'"
                                      x-text="teksBeda">{{ $beda === null ? '—' : ($beda > 0 ? '+' : '').$angka($beda) }}</span>
                            </span>
                        </div>
                        <input id="fisik-hp-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                               wire:model.blur="fisik.{{ $kunci }}"
                               x-on:input="ketik($event.target)"
                               placeholder="belum dihitung"
                               class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                        @error('fisik.'.$kunci)
                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Alasan hanya MUNCUL kalau ada selisih.
                         Dirender selalu, ia delapan dropdown "Belum dipilih" berjajar di lembar
                         yang belum punya satu pun selisih — pekerjaan yang tidak diminta,
                         dipajang sebagai kewajiban. Elemennya tetap ada di DOM (Alpine yang
                         menyembunyikannya), jadi begitu angka fisik diketik ia langsung
                         terlihat TANPA menunggu jaringan, dan kemampuan memilih alasan tidak
                         pernah hilang. Server tetap yang mewajibkannya saat selisih ≠ 0.

                         SENGAJA tanpa kelas `hidden` dari server: x-show hanya melepas style
                         inline-nya, jadi kelas itu akan menyembunyikan kolomnya SELAMANYA
                         walau Alpine sudah menyatakan ada selisih. x-cloak sudah menutup
                         kedipan sebelum Alpine hidup, dan galat alasan hanya bisa lahir dari
                         baris yang fisiknya terisi & berselisih — jadi keadaan itu selalu
                         membuat x-show bernilai benar. --}}
                    <div class="mt-3" x-cloak x-show="selisih || alasan !== ''">
                        <label for="alasan-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                            Alasan selisih
                            <span class="font-normal text-jingga-tua" x-cloak x-show="selisih">— wajib dipilih</span>
                        </label>
                        <select id="alasan-hp-{{ $kunci }}" wire:model.blur="alasan.{{ $kunci }}"
                                x-on:change="alasan = $event.target.value"
                                class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                            <option value="">Belum dipilih</option>
                            @foreach ($alasanTersedia as $a)
                                <option value="{{ $a['nilai'] }}">{{ $a['label'] }}</option>
                            @endforeach
                        </select>
                        @error('alasan.'.$kunci)
                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                        @enderror

                        {{-- Catatan hanya untuk "Lainnya": mewajibkannya di semua alasan
                             membuat kolomnya diisi "-" oleh orang yang sedang menghitung 200
                             barang, dan sesudah itu isinya tidak berarti apa pun. --}}
                        <div class="mt-3" x-cloak x-show="butuhCatatan">
                            <label for="catatan-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                                Catatan (wajib untuk alasan "Lainnya")
                            </label>
                            <input id="catatan-hp-{{ $kunci }}" type="text" wire:model.blur="catatan.{{ $kunci }}"
                                   placeholder="mis. dipakai sendiri untuk contoh"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('catatan.'.$kunci)
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-line">
                        <th class="px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                        <th class="w-28 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Sistem</th>
                        <th class="w-36 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Fisik</th>
                        <th class="w-28 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Selisih</th>
                        <th class="w-60 px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Alasan selisih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $b)
                        @php
                            $kunci = $b['kunci'];
                            $beda = $bedaServer($b);
                            $satuan = $b['satuan_dasar'] ?? $b['satuan'] ?? '';
                        @endphp

                        {{-- Baris yang fisiknya ≠ sistem berwarna SEBELUM disimpan: itu satu
                             satunya cara pemilik melihat berapa baris yang akan menghasilkan
                             mutasi, saat lembarnya masih bisa diperbaiki.

                             wire:key MEMUAT OUTLET, dan itu bukan kehati-hatian: x-data di
                             bawah menangkap `sistem` SEKALI saat Alpine menghidupkan
                             elemennya. Dengan kunci yang sama di kedua cabang, morph Livewire
                             memakai ulang elemen yang sama saat outlet berganti, Alpine tidak
                             pernah dihidupkan ulang, dan `sistem` tetap milik cabang lama —
                             kolom Selisih dan lencana "wajib pilih alasan" lalu berbicara
                             tentang cabang yang salah. PHPUnit tidak pernah melihat ini:
                             servernya merender angka yang benar, Alpine-lah yang menimpanya
                             di peramban. Nama ikatannya (fisik.<kunci>) tidak berubah. --}}
                        <tr wire:key="baris-{{ $outletDipakai }}-{{ $kunci }}"
                            x-data="barisOpname(@js((string) ($fisik[$kunci] ?? '')), @js((float) $b['sistem']), @js((string) ($alasan[$kunci] ?? '')))"
                            x-bind:class="selisih ? 'bg-jingga/10' : ''"
                            @class(['bg-jingga/10' => $beda !== null && $beda !== 0.0])>
                            <td class="px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $b['nama'] }}</p>
                                    <p class="truncate text-[0.75rem] text-umber-soft">
                                        {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                        · {{ $b['kode'] ?: 'tanpa kode' }}
                                        @if ($b['perlu_diperiksa'])
                                            · <span class="font-semibold text-jingga-tua">perlu dihitung ulang</span>
                                        @endif
                                    </p>
                                </div>
                            </td>

                            <td class="tabular px-5 py-3 text-right text-[0.875rem]">
                                @if ($b['punya_baris'])
                                    <span class="font-semibold text-ink">{{ $angka($b['sistem']) }}</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">{{ $satuan !== '' ? $satuan : '—' }}</span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">belum dihitung</span>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                <label for="fisik-{{ $kunci }}" class="sr-only">Jumlah fisik {{ $b['nama'] }}</label>
                                <input id="fisik-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                       wire:model.blur="fisik.{{ $kunci }}"
                                       x-on:input="ketik($event.target)"
                                       placeholder="—"
                                       class="tabular h-12 w-full rounded-xl border border-line bg-white px-3 text-right text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                @error('fisik.'.$kunci)
                                    <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </td>

                            <td class="tabular px-5 py-3 text-right text-[0.9375rem] font-bold">
                                <span x-bind:class="selisih ? 'text-jingga-tua' : 'text-umber-soft'"
                                      x-text="teksBeda">{{ $beda === null ? '—' : ($beda > 0 ? '+' : '').$angka($beda) }}</span>
                            </td>

                            {{-- Kolom ini dulu berisi DELAPAN dropdown "Belum dipilih" pada
                                 lembar yang belum punya satu pun selisih — dan itulah "banyak
                                 yang kosong" secara harfiah: pekerjaan yang belum diminta,
                                 dipajang penuh sebagai kewajiban.

                                 Yang belum relevan sekarang cuma satu tanda tenang "—".
                                 Dropdownnya TETAP ada di DOM dan hanya disembunyikan Alpine,
                                 jadi ia terbuka seketika begitu angka fisiknya diketik, tanpa
                                 menunggu jaringan — dan kemampuan memilih alasan tidak pernah
                                 hilang. Yang mewajibkannya tetap server (selisih ≠ 0). --}}
                            <td class="px-5 py-3">
                                {{-- Kata, bukan tanda hubung kedua. Kolom Selisih di sebelahnya
                                     sudah berisi "—", dan dua strip berdampingan tidak
                                     mengatakan apa-apa; "tidak perlu" menjawab pertanyaan yang
                                     sebenarnya dibawa pembacanya ke kolom ini. --}}
                                <p class="text-[0.8125rem] text-umber-soft" x-cloak x-show="! selisih && alasan === ''">tidak perlu</p>

                                <div x-cloak x-show="selisih || alasan !== ''">
                                    <label for="alasan-{{ $kunci }}" class="sr-only">Alasan selisih {{ $b['nama'] }}</label>
                                    <select id="alasan-{{ $kunci }}" wire:model.blur="alasan.{{ $kunci }}"
                                            x-on:change="alasan = $event.target.value"
                                            class="h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                                        <option value="">Belum dipilih</option>
                                        @foreach ($alasanTersedia as $a)
                                            <option value="{{ $a['nilai'] }}">{{ $a['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-[0.75rem] text-jingga-tua" x-cloak x-show="selisih && alasan === ''">
                                        Wajib dipilih karena fisiknya berbeda dari sistem.
                                    </p>
                                    @error('alasan.'.$kunci)
                                        <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="mt-2" x-cloak x-show="butuhCatatan">
                                    <label for="catatan-{{ $kunci }}" class="sr-only">Catatan {{ $b['nama'] }}</label>
                                    <input id="catatan-{{ $kunci }}" type="text" wire:model.blur="catatan.{{ $kunci }}"
                                           placeholder="Catatan wajib untuk alasan “Lainnya”"
                                           class="h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                    @error('catatan.'.$kunci)
                                        <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                    @enderror
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif
    </div>

    {{-- ── Bar simpan ──────────────────────────────────────────────────────
         sticky, bukan fixed: ia tetap terlihat selama menggulir, tapi tetap punya tempat
         sendiri di alur halaman sehingga tidak pernah menutupi baris terakhir saat lembar
         sudah digulir sampai bawah.

         Margin negatifnya memasangkan diri dengan `pb-[5.5rem]` di wadah daftar: barnya
         berdiri DI DALAM ruang bawah itu, jadi baris terakhir tetap bisa dibaca saat lembar
         digulir sampai habis, sekaligus tidak menambah ekor kosong di bawah halaman. --}}
    {{-- Nama cabangnya disebut DI SINI, dan itu bukan pengulangan yang berlebihan: bar ini
         satu-satunya elemen yang selalu terlihat. Dropdown outlet duduk di baris saringan
         paling atas dan sudah berada di luar layar begitu lembarnya digulir, jadi kalimat
         ini pertahanan terakhir sebelum tombol simpan ditekan tanpa cabangnya pernah dibaca.
         Tanpa nama hanya untuk peran yang terkunci satu outlet — di situ dropdownnya tidak
         ada, jadi tidak ada cabang yang bisa tertukar. --}}
    <div class="sticky bottom-0 z-10 -mt-[11.25rem] flex flex-wrap items-center justify-between gap-3 rounded-[20px] border border-line bg-white/95 px-4 py-2.5 backdrop-blur sm:-mt-32 sm:px-5 xl:-mt-[4.5rem]">
        <div class="min-w-0">
            {{-- Dua bentuk utuh, bukan satu kalimat dengan @if di tengahnya: Blade TIDAK
                 mengenali direktif yang menempel di belakang huruf (`terisi@if` dibaca
                 sebagai teks biasa, lalu @endif-nya menjadi galat sintaks). --}}
            @if ($namaDipakai !== null)
                <p class="text-[0.9375rem] font-bold text-ink">
                    <span class="tabular">{{ $jumlahTerisi }}</span> baris terisi untuk
                    {{-- inline-block + max-w-full, bukan span biasa: nama cabang yang panjang
                         terbelah dua baris membuat kotak span-nya menjadi gabungan kedua
                         barisnya, dan kotak itu MENIMPA angka di baris pertama (terukur:
                         tumpangTindih=1 di 390px). Sebagai kotak utuh ia pindah ke barisnya
                         sendiri, dan max-w-full menjaganya tidak melebarkan halaman. --}}
                    <span class="inline-block max-w-full text-terracotta">{{ $namaDipakai }}</span>, belum disimpan
                </p>
            @else
                <p class="text-[0.9375rem] font-bold text-ink">
                    <span class="tabular">{{ $jumlahTerisi }}</span> baris terisi, belum disimpan
                </p>
            @endif
            {{-- SATU baris di semua lebar. Dua kalimat penuh di sini membuat barnya 131px di
                 1280 dan 197px di 390 — dan bar setinggi itu menutupi dua baris tabel setiap
                 kali lembarnya digulir. Kedua keterangan yang mengubah keputusan tetap ada,
                 hanya dipendekkan: angkanya milik seluruh lembar (jadi pindah halaman aman),
                 dan desimal ditulis dengan titik. --}}
            <p class="text-[0.75rem] text-umber">
                Seluruh lembar · desimal pakai titik (1.5)
            </p>
        </div>

        {{-- Lebar penuh di ponsel, dan tingginya minimum bukan tetap: teks tombol bisa jatuh
             ke dua baris. Tombol lebar-tetap di situ melebarkan halaman.

             DUA bentuk teks, dan bukan demi kerapian saja: "Simpan hasil hitung — Benjamin
             Cabang Seturan" itu 44 karakter, di 390px ia terbelah dua baris dan menjadikan
             tombol utama layar ini kotak tinggi bertumpuk. Bentuk pendeknya hanya dipakai di
             bawah 640px, dan nama cabangnya TIDAK hilang dari bar ini — kalimat di atas
             tombol ("… baris terisi untuk <cabang>, belum disimpan") menyebutnya, dan itulah
             pertahanan terakhir sebelum hitungan tersimpan ke cabang yang salah. --}}
        <button type="button" wire:click="simpan" wire:loading.attr="disabled"
                class="min-h-12 w-full cursor-pointer rounded-xl bg-terracotta px-6 py-3 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60 sm:w-auto sm:shrink-0">
            <span wire:loading.remove wire:target="simpan">
                <span class="sm:hidden">Simpan hasil hitung</span>
                <span class="hidden sm:inline">Simpan hasil hitung{{ $namaDipakai !== null ? ' — '.$namaDipakai : '' }}</span>
            </span>
            <span wire:loading wire:target="simpan">Menyimpan…</span>
        </button>
    </div>
</div>
