{{--
    Catat nota belanja: barang yang baru datang dari grosir/pasar.

    Data dari komponen: $daftar (paginator baris barang), $ringkasan, $jumlahTerisi,
    $saranPemasok, $namaPerKunci, $outletTersedia, $outletDipakai, $namaOutletDiminta.
    Ikatan per baris di-key ID BARANG: jumlah.<kunci> / harga.<kunci> — dan galatnya di
    nama yang sama.

    EMPAT hal yang menentukan layar ini benar atau tidak, dan semuanya sudah pernah
    menjadi cacat nyata di layar sebelah:

    1. **Kunci id barang, bukan indeks baris.** Nota belanja bulanan kelontong bisa 40
       barang; dengan 10 baris per halaman itu 4 halaman, dan angka yang hilang saat
       berpindah halaman berarti notanya diketik ulang dari awal.

    2. **Angka uang di bar bawah datang dari $ringkasan (server).** Menghitungnya lagi di
       Alpine berarti dua rumus untuk satu angka — dan angka di layar yang berbeda dari
       angka yang tersimpan adalah cara tercepat membuat orang berhenti memercayai
       keduanya. Ikatan barisnya `.live.debounce` supaya bar itu ikut bergerak saat
       diketik; dengan `.blur` bar berkata "0 barang" sementara kotaknya sudah berisi angka.

    3. **Blok peringatan pergantian cabang MENETAP di halaman**, bukan toast: ia membawa
       tombol keputusannya. Toast hilang sendiri, dan bersamanya hilang satu-satunya jalan
       pemilik untuk benar-benar pindah cabang.

    4. **Ringkasan galat menyebut nama barangnya.** Validasi berjalan atas semua baris
       terisi termasuk yang ada di halaman lain, jadi tanpa ringkasan ini satu baris di
       halaman 3 bisa menahan simpan tanpa penjelasan apa pun di layar yang sedang dilihat.
--}}
@php
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');
    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');

    /*
     * Nama outlet per id — dipakai blok peringatan DAN bar simpan.
     *
     * Sumbernya $outletTersedia, daftar yang SAMA dengan isi dropdown: hanya outlet yang
     * boleh dilihat pengguna ini yang punya nama di sini. Mencarinya lewat model akan
     * mencetak nama usaha merchant lain di layar pemilik ini, karena $outletDiminta lahir
     * dari nilai yang dikirim klien (lihat PembelianBaru::namaOutletDiminta()).
     */
    $namaOutlet = collect($outletTersedia)->pluck('nama', 'id');
    $namaTerkunci = $namaOutlet[$outletTerkunci] ?? null;
    $namaDipakai = $namaOutlet[$outletDipakai] ?? null;

    // Label galat di luar baris barang. Nama propertinya (ongkosKirim) tidak pernah
    // dicetak apa adanya — pemilik warung tidak pernah menamai apa pun seperti itu.
    $labelGalat = [
        'diskon' => 'Potongan',
        'ongkosKirim' => 'Ongkos kirim',
        'tanggal' => 'Tanggal nota',
        'beliDari' => 'Beli dari',
        'catatan' => 'Catatan',
    ];
@endphp

<div>
    {{-- mt-2/sm:mt-3: navbar mengapung (`sticky top-4 mb-2`), jadi tanpa ini kartu pertama
         halaman menempel ke kartu judul sementara jarak antar-bagian di bawahnya 16/20px. --}}
    <x-kartu-alat
        class="mt-2 sm:mt-3"
        judul="Nota belanja baru"
        jumlah="{{ $daftar->total() }}"
        keterangan="Isi jumlah dalam satuan yang dibeli (dus, karung, kg) — stok bertambah sendiri dalam satuan kecilnya. Baris yang dikosongkan berarti tidak dibeli."
    >
        <x-slot:aksi>
            {{-- Bergaris rambut, bukan isian penuh: tombol utama layar ini adalah SIMPAN di
                 bar bawah, dan dua tombol berwarna penuh membuat keduanya berhenti berarti.
                 Tingginya 44px, seukuran isinya, sejajar judul seksinya sejak ponsel —
                 bentuk yang sama dengan "Daftar stok" di lembar hitung stok. --}}
            <a href="{{ route('owner.pembelian', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="tombol-kedua h-11 w-auto shrink-0 px-4 text-[0.875rem]">
                <span class="tombol-ikon">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                Daftar nota
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            {{-- Pencarian selebar penuh, dan dropdown OUTLET juga selebar penuh sampai ≥lg.
                 Kedua dropdown tidak dipasangkan dua-dua di sini justru karena hanya ada
                 dua: yang satu menentukan ke cabang mana seluruh belanjaan ini masuk, dan
                 nama cabang ("Benjamin Cabang Seturan") terpotong jadi "Benjamin Caban…" di
                 kotak separuh lebar. Di layar Daftar nota ia boleh berbagi baris — di sana ia
                 cuma menyaring tampilan, dan salah membacanya tidak mengubah data apa pun. --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-[minmax(0,1fr)_11rem_16rem]">
                <div class="col-span-2 min-w-0 lg:col-span-1">
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

                <div class="col-span-2 min-w-0 lg:col-span-1">
                    <label for="jenis" class="sr-only">Jenis barang</label>
                    <select id="jenis" wire:model.live="jenis"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Produk &amp; bahan</option>
                        <option value="produk">Produk saja</option>
                        <option value="bahan">Bahan baku saja</option>
                    </select>
                </div>

                @if ($outletTersedia !== [])
                    <div class="col-span-2 min-w-0 lg:col-span-1">
                        <label for="outlet" class="sr-only">Outlet tujuan barang</label>
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

    {{-- ── Pergantian cabang yang DITOLAK ──────────────────────────────────
         Sama persis dengan lembar hitung stok, dan bukan karena seragam saja: nota yang
         sudah setengah diketik tidak boleh terbuang oleh satu sentuhan dropdown, dan
         sebaliknya satu angka nyasar tidak boleh membekukan dropdown selamanya. Jumlah
         barisnya disebut DI TOMBOLNYA — itu langkah konfirmasinya, jadi tidak perlu dialog
         tambahan di atasnya. --}}
    @if ($outletDiminta !== null)
        @php
            // Dua bentuk kalimat, dan yang tanpa nama bukan kelalaian: id di luar
            // outletTersedia() sengaja tidak diberi nama supaya nama outlet merchant lain
            // tidak bocor. Yang dicetak dalam keadaan itu kalimat tanpa nama — BUKAN id
            // mentahnya, yang berupa UUID dan tidak berarti apa pun.
            $sebutTerkunci = $namaTerkunci ?? 'cabang tempat notanya diketik';
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
                        Nota ini sedang diketik untuk {{ $namaTerkunci ?? 'cabang lain' }}
                    </p>
                    <p class="mt-0.5 text-[0.8125rem] text-umber">
                        <span class="tabular font-semibold text-ink">{{ $jumlahTerisi }}</span>
                        barang sudah diisi untuk {{ $sebutTerkunci }}, sedangkan yang tadi dipilih {{ $sebutDiminta }}.
                        Barang yang dibawa pulang ke {{ $sebutTerkunci }} tidak pernah sampai ke {{ $sebutDiminta }}:
                        kalau tersimpan ke cabang yang salah, stok kedua cabang jadi salah dan tidak ada catatan yang
                        menunjukkan kenapa.
                    </p>
                </div>
            </div>

            {{-- Satu kolom di ponsel, sebaris di ≥sm: teksnya panjang karena memuat nama
                 cabang, dan tombol lebar-tetap di 390px melebarkan halaman. --}}
            <div class="mt-3 grid gap-2 sm:flex sm:flex-wrap">
                <button type="button" wire:click="simpan" wire:loading.attr="disabled"
                        class="min-h-11 cursor-pointer rounded-xl bg-terracotta px-4 py-2.5 text-[0.8125rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60">
                    Simpan nota {{ $sebutTerkunci }} dulu
                </button>

                <button type="button" wire:click="pindahOutlet('{{ $outletDiminta }}')" wire:loading.attr="disabled"
                        class="tombol-bahaya min-h-11 cursor-pointer px-4 py-2.5 text-[0.8125rem]">
                    Buang {{ $jumlahTerisi }} barang, pindah ke {{ $sebutDiminta }}
                </button>

                <button type="button" wire:click="tetapDiOutlet"
                        class="min-h-11 cursor-pointer rounded-xl border border-line px-4 py-2.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                    Tetap di {{ $sebutTerkunci }}
                </button>
            </div>
        </div>
    @endif

    {{-- Ringkasan galat SELURUH nota — termasuk baris yang sedang tidak tampak. --}}
    @if ($errors->any())
        <div role="alert" class="kartu mb-4 border border-merah/30 px-5 py-4 sm:mb-5 sm:px-6">
            <p class="text-[0.875rem] font-bold text-merah-deep">
                {{ $errors->count() }} hal harus dibetulkan dulu
            </p>
            <p class="mt-0.5 text-[0.8125rem] text-umber">
                Nota disimpan sekaligus, jadi belum ada satu barang pun yang masuk stok —
                angka yang sudah diketik tetap ada.
            </p>
            <ul class="mt-2 space-y-1">
                @foreach ($errors->keys() as $kunciGalat)
                    @php
                        $kunciBaris = str($kunciGalat)->after('.')->toString();
                        $sebutan = str_contains($kunciGalat, '.')
                            ? ($namaPerKunci[$kunciBaris] ?? 'Barang lain')
                            : ($labelGalat[$kunciGalat] ?? $kunciGalat);
                    @endphp
                    <li class="text-[0.8125rem] text-ink">
                        <span class="font-semibold">{{ $sebutan }}:</span>
                        {{ $errors->first($kunciGalat) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Keterangan nota ─────────────────────────────────────────────────
         Satu kartu berisi hal-hal yang berlaku untuk SELURUH nota. Ditaruh di atas daftar
         barang karena inilah yang diisi lebih dulu saat pulang dari grosir: dari mana, dan
         tanggal berapa. Potongan & ongkos kirim menyusul di baris kedua — keduanya menggeser
         uang yang keluar, bukan harga barangnya. --}}
    <div class="kartu mb-4 px-5 py-4 sm:mb-5 sm:px-6 sm:py-5">
        <h2 class="text-[1.0625rem] font-bold text-ink">Keterangan nota</h2>
        <p class="mt-1 text-[0.875rem] text-umber">
            Kosongkan yang memang tidak ada — belanja di pasar tidak selalu punya nama toko.
        </p>

        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="col-span-2 min-w-0">
                <label for="beli-dari" class="block text-[0.8125rem] font-semibold text-ink">Beli dari</label>
                {{-- Teks bebas + <datalist>: nama yang sudah pernah dipakai ditawarkan, nama
                     baru cukup diketik dan barisnya lahir sendiri saat nota disimpan. Tidak
                     ada layar master pemasok, dan itu disengaja — pemilik warung tidak akan
                     mengisi formulir master sebelum mencatat belanja. --}}
                {{-- value= ditulis SENDIRI, dan itu bukan kelebihan hati-hati: Livewire tidak
                     mencetak nilai awal untuk kolom ber-wire:model, jadi begitu halaman
                     dirender ulang (pindah halaman daftar barang, ganti saringan) kolom ini
                     terbuka KOSONG walau isinya masih tersimpan di server. Pemiliknya akan
                     mengetik ulang — dan pada kolom jumlah & harga di bawah, mengetik ulang
                     berarti nota yang salah. Pola yang sama sudah dipakai kolom batas minimal
                     di layar Stok. --}}
                <input id="beli-dari" type="text" list="saran-beli-dari" autocomplete="off"
                       wire:model.blur="beliDari" placeholder="mis. Grosir Amanah"
                       value="{{ $beliDari }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                <datalist id="saran-beli-dari">
                    @foreach ($saranPemasok as $nama)
                        <option value="{{ $nama }}"></option>
                    @endforeach
                </datalist>
                @error('beliDari')
                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                @enderror
            </div>

            <div class="col-span-2 min-w-0 lg:col-span-1">
                <label for="tanggal" class="block text-[0.8125rem] font-semibold text-ink">Tanggal nota<x-wajib /></label>
                <input id="tanggal" type="date" wire:model.blur="tanggal" value="{{ $tanggal }}"
                       class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                @error('tanggal')
                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                @enderror
            </div>

            <div class="min-w-0">
                <label for="diskon" class="block text-[0.8125rem] font-semibold text-ink">Potongan</label>
                <input id="diskon" type="text" inputmode="decimal" autocomplete="off"
                       wire:model.live.debounce.600ms="diskon" placeholder="0" value="{{ $diskon }}"
                       class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                @error('diskon')
                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                @enderror
            </div>

            <div class="min-w-0">
                <label for="ongkos-kirim" class="block text-[0.8125rem] font-semibold text-ink">Ongkos kirim</label>
                <input id="ongkos-kirim" type="text" inputmode="decimal" autocomplete="off"
                       wire:model.live.debounce.600ms="ongkosKirim" placeholder="0" value="{{ $ongkosKirim }}"
                       class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                @error('ongkosKirim')
                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                @enderror
            </div>

            {{-- lg:col-span-3, bukan 4: dengan lebar penuh ia turun ke barisnya sendiri dan
                 meninggalkan bidang kosong selebar tiga kolom di sebelah "Ongkos kirim" —
                 lubang yang tidak terhitung oleh alat ukur mana pun, tapi itulah yang
                 membuat kartu ini terbaca setengah jadi. Sekarang barisnya penuh: ongkir (1)
                 + catatan (3). --}}
            <div class="col-span-2 min-w-0 lg:col-span-3">
                <label for="catatan" class="block text-[0.8125rem] font-semibold text-ink">Catatan</label>
                <input id="catatan" type="text" wire:model.blur="catatan" value="{{ $catatan }}"
                       placeholder="mis. bayar tunai, nota kertas disimpan di laci"
                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                @error('catatan')
                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                @enderror
            </div>

            {{-- ── Barangnya sudah datang? ──────────────────────────────────
                 Pertanyaan ini yang menentukan apakah stok bertambah SEKARANG. Bawaannya
                 "sudah saya terima", karena belanja warung biasanya dicatat sesudah barangnya
                 diturunkan dari motor — bawaan sebaliknya akan mengubah setiap nota biasa
                 menjadi nota menggantung, dan pemiliknya baru sadar saat kasir mengabari
                 "Habis" untuk rak yang penuh.

                 TANPA <x-wajib />: validatornya `boolean`, bukan `required`, dan pilihan yang
                 sudah punya bawaan tidak pernah bisa kosong. Bintang di sini akan menjadi
                 bintang yang tidak pernah bisa dilanggar — dan sesudah dua bintang seperti itu
                 orang berhenti memercayai bintangnya lalu melewatkan yang sungguh wajib
                 (aturan medan wajib di CLAUDE.md).

                 Dua pilihan berdampingan, bukan satu sakelar: sakelar hanya menyebut SATU
                 keadaan dan pembacanya harus menyimpulkan sendiri arti keadaan sebaliknya.
                 Di sini keduanya tertulis, beserta akibatnya masing-masing pada stok.

                 `wire:model.live`, bukan `.blur`: sorotan pilihan yang sedang aktif digambar
                 dari keadaan di server, jadi tanpa `.live` kotak yang tersorot akan tertinggal
                 satu langkah di belakang titik radionya — dan pemilik yang melihat dua penanda
                 tidak sepakat akan berhenti memercayai keduanya. --}}
            <fieldset class="col-span-2 min-w-0 lg:col-span-4">
                <legend class="text-[0.8125rem] font-semibold text-ink">Barangnya sudah datang?</legend>
                <div class="mt-1.5 grid gap-2 sm:grid-cols-2">
                    @foreach ([
                        ['1', true, 'Barangnya sudah saya terima', 'Stok outletnya langsung bertambah begitu nota ini tersimpan.'],
                        ['0', false, 'Barangnya belum datang', 'Stok belum bertambah. Tandai datang nanti dari daftar nota.'],
                    ] as [$nilai, $aktif, $judulPilihan, $akibat])
                        <label @class([
                                   'flex min-h-12 min-w-0 cursor-pointer items-start gap-2.5 rounded-xl border px-3.5 py-2.5 transition-colors',
                                   'border-terracotta bg-cream' => $sudahDatang === $aktif,
                                   'border-line bg-white hover:bg-cream/60' => $sudahDatang !== $aktif,
                               ])>
                            <input type="radio" wire:model.live="sudahDatang" value="{{ $nilai }}"
                                   class="mt-0.5 size-4 shrink-0 cursor-pointer accent-terracotta">
                            <span class="min-w-0">
                                <span class="block text-[0.875rem] font-semibold text-ink">{{ $judulPilihan }}</span>
                                <span class="mt-0.5 block text-[0.75rem] leading-snug text-umber">{{ $akibat }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('sudahDatang')
                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                @enderror
            </fieldset>
        </div>
    </div>

    {{-- RUANG BAWAH sebesar bar simpan: barnya menempel (sticky), jadi tanpa ruang ini ia
         berdiri tepat di atas dua baris terakhir dan kolom isiannya tidak bisa diketik.
         Angkanya DIUKUR di peramban, bukan dikira — tinggi bar berubah menurut lebar layar
         karena kalimat & tombolnya berpindah baris. Kalau isi barnya diubah, ukur ulang di
         390/768/1280. Margin negatif bar menariknya kembali ke dalam ruang ini, jadi halaman
         tidak menjadi lebih panjang dan tidak ada lubang kosong di kakinya. --}}
    <div class="pb-44 sm:pb-36 xl:pb-28">
    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada barang yang cocok"
                  keterangan="Coba ubah kata pencarian atau saringannya. Menu berbasis resep tidak muncul di sini — yang dibeli untuk menu adalah bahan bakunya."
                  ikon="cari">
            <x-slot:aksi>
                <button type="button" wire:click="$set('jenis', 'semua')"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Tampilkan semua barang
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Kartu di <lg, tabel di ≥lg — pola yang sama dengan lembar hitung stok. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $b)
                @php
                    $kunci = $b['kunci'];
                    $satuanBeli = $b['satuan'] ?: 'satuan';
                    $satuanDasar = $b['satuan_dasar'] ?? $b['satuan'] ?? '';
                    $terisi = ($jumlah[$kunci] ?? '') !== '' && ($jumlah[$kunci] ?? null) !== null;
                @endphp

                {{-- wire:key MEMUAT OUTLET: dengan kunci yang sama di dua cabang, morph
                     Livewire memakai ulang elemen yang sama saat outlet berganti dan kotak
                     isian bisa membawa nilai cabang sebelumnya. Nama ikatannya
                     (jumlah.<kunci>) tidak ikut berubah — ia memang milik barangnya. --}}
                <div wire:key="kartu-{{ $outletDipakai }}-{{ $kunci }}"
                     @class(['kartu border p-4', 'border-terracotta/40' => $terisi, 'border-transparent' => ! $terisi])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[0.9375rem] font-bold text-ink">{{ $b['nama'] }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                · {{ $b['kode'] ?: 'tanpa kode' }}
                                · sisa
                                <span class="tabular font-semibold text-ink">
                                    {{ $b['punya_baris'] ? trim($angka($b['sistem']).' '.$satuanDasar) : 'belum dihitung' }}
                                </span>
                            </p>
                        </div>
                    </div>

                    @if ($b['isi_per_satuan'] !== null && $b['satuan'] !== null)
                        {{-- Konversi ditulis apa adanya: yang diketik di bawah adalah DUS,
                             yang bertambah di kartu stok adalah pcs. Tanpa kalimat ini, "2"
                             yang menjadi "24" di layar stok terbaca sebagai salah hitung. --}}
                        <p class="tabular mt-1.5 text-[0.75rem] text-umber-soft">
                            1 {{ mb_strtolower($satuanBeli) }} = {{ $angka($b['isi_per_satuan']) }} {{ $satuanDasar }}
                        </p>
                    @endif

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0">
                            <label for="jumlah-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                                Jumlah ({{ mb_strtolower($satuanBeli) }})
                            </label>
                            <input id="jumlah-hp-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                   wire:model.live.debounce.600ms="jumlah.{{ $kunci }}" placeholder="0" value="{{ $jumlah[$kunci] ?? '' }}"
                                   class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                        </div>

                        <div class="min-w-0">
                            {{-- Berbintang: `harga.<kunci>` ber-`required` begitu barisnya
                                 terisi. Nol SAH (bonus grosir) — yang ditolak adalah kosong,
                                 karena kosong yang lolos sebagai nol menghapus harga beli
                                 barangnya di master. --}}
                            <label for="harga-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                                Harga per {{ mb_strtolower($satuanBeli) }}<x-wajib />
                            </label>
                            <input id="harga-hp-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                   wire:model.live.debounce.600ms="harga.{{ $kunci }}" placeholder="0" value="{{ $harga[$kunci] ?? '' }}"
                                   class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                        </div>
                    </div>

                    @error('jumlah.'.$kunci)
                        <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                    @enderror
                    @error('harga.'.$kunci)
                        <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full table-fixed text-center">
                <thead>
                    <tr class="border-b border-line">
                        {{-- Lebar persen + table-fixed, jumlahnya 100%: kolom tanpa lebar
                             menyerap seluruh sisa lebar panel dan lubangnya cuma berpindah
                             tempat. Judul rata TENGAH sama dengan isinya (keputusan pemilik
                             proyek, ditandai juga di layar Stok & hitung stok). --}}
                        <th class="w-[34%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                        <th class="w-[14%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Sisa sekarang</th>
                        <th class="w-[24%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah</th>
                        <th class="w-[28%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Harga beli<x-wajib /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $b)
                        @php
                            $kunci = $b['kunci'];
                            $satuanBeli = $b['satuan'] ?: 'satuan';
                            $satuanDasar = $b['satuan_dasar'] ?? $b['satuan'] ?? '';
                            $terisi = ($jumlah[$kunci] ?? '') !== '' && ($jumlah[$kunci] ?? null) !== null;
                        @endphp

                        <tr wire:key="baris-{{ $outletDipakai }}-{{ $kunci }}"
                            @class(['transition-colors hover:bg-cream/60', 'bg-terracotta/5' => $terisi])>
                            <td class="px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $b['nama'] }}</p>
                                    <p class="tabular truncate text-[0.75rem] text-umber-soft">
                                        {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                        · {{ $b['kode'] ?: 'tanpa kode' }}
                                        @if ($b['isi_per_satuan'] !== null && $b['satuan'] !== null)
                                            · 1 {{ mb_strtolower($satuanBeli) }} = {{ $angka($b['isi_per_satuan']) }} {{ $satuanDasar }}
                                        @endif
                                    </p>
                                </div>
                            </td>

                            {{-- "belum dihitung", BUKAN "0": nol adalah pernyataan bahwa raknya
                                 kosong, dan barang yang belum pernah dicatat tidak pernah
                                 menyatakan itu. --}}
                            <td class="tabular px-5 py-3 text-center text-[0.875rem]">
                                @if ($b['punya_baris'])
                                    <span class="font-semibold text-ink">{{ $angka($b['sistem']) }}</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">{{ $satuanDasar !== '' ? $satuanDasar : '—' }}</span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">belum dihitung</span>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                <label for="jumlah-{{ $kunci }}" class="sr-only">Jumlah beli {{ $b['nama'] }}</label>
                                <input id="jumlah-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                       wire:model.live.debounce.600ms="jumlah.{{ $kunci }}" placeholder="0" value="{{ $jumlah[$kunci] ?? '' }}"
                                       class="tabular h-12 w-full rounded-xl border border-line bg-white px-3 text-right text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                {{-- Satuannya ditulis DI BAWAH kotaknya, bukan di judul kolom:
                                     tiap baris punya satuan belinya sendiri (dus, karung, kg),
                                     dan satu judul kolom tidak bisa jujur untuk semuanya. --}}
                                <p class="mt-1 text-[0.6875rem] text-umber-soft">{{ mb_strtolower($satuanBeli) }}</p>
                                @error('jumlah.'.$kunci)
                                    <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </td>

                            <td class="px-5 py-3">
                                <label for="harga-{{ $kunci }}" class="sr-only">Harga beli {{ $b['nama'] }}</label>
                                <input id="harga-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                       wire:model.live.debounce.600ms="harga.{{ $kunci }}" placeholder="0" value="{{ $harga[$kunci] ?? '' }}"
                                       class="tabular h-12 w-full rounded-xl border border-line bg-white px-3 text-right text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                <p class="mt-1 text-[0.6875rem] text-umber-soft">per {{ mb_strtolower($satuanBeli) }}</p>
                                @error('harga.'.$kunci)
                                    <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                @enderror
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
         sticky, bukan fixed: ia tetap terlihat selama menggulir, tapi punya tempat sendiri
         di alur halaman sehingga tidak menutupi baris terakhir saat lembarnya digulir
         sampai bawah.

         Seluruh angkanya dari $ringkasan — dihitung server dengan rumus yang SAMA dengan
         CatatPembelianAction (total = harga barang − potongan + ongkir). Tidak ada
         penghitung kedua di Alpine: nota ini berhalaman, baris di halaman lain hanya
         diketahui server, dan dua angka yang bisa berbeda untuk satu nominal adalah cacat
         yang sudah diperbaiki dua kali di repo ini.

         Nama cabangnya disebut DI SINI karena bar ini satu-satunya elemen yang selalu
         terlihat — dropdown outlet sudah di luar layar begitu daftarnya digulir. --}}
    <div class="sticky bottom-0 z-10 -mt-40 flex flex-col items-center gap-2.5 rounded-[20px] border border-line bg-white/95 px-4 py-3 text-center backdrop-blur sm:-mt-32 sm:flex-row sm:justify-between sm:gap-3 sm:px-5 sm:py-2.5 sm:text-left xl:-mt-24">
        <div class="min-w-0">
            {{-- Dua bentuk kalimat UTUH, bukan satu kalimat dengan @if di tengahnya: Blade
                 tidak mengenali direktif yang menempel di belakang huruf. --}}
            @if ($namaDipakai !== null)
                <p class="text-[0.9375rem] font-bold text-ink">
                    <span class="tabular">{{ $ringkasan['baris'] }}</span> barang ·
                    <span class="tabular text-terracotta">{{ $rupiah($ringkasan['total']) }}</span>
                    {{-- inline-block + max-w-full: nama cabang yang terbelah dua baris membuat
                         kotak span-nya menjadi gabungan kedua barisnya, dan kotak itu MENIMPA
                         teks di baris pertama (pernah terukur tumpangTindih=1 di 390px pada
                         lembar hitung stok). --}}
                    untuk <span class="inline-block max-w-full">{{ $namaDipakai }}</span>
                </p>
            @else
                <p class="text-[0.9375rem] font-bold text-ink">
                    <span class="tabular">{{ $ringkasan['baris'] }}</span> barang ·
                    <span class="tabular text-terracotta">{{ $rupiah($ringkasan['total']) }}</span> total belanja
                </p>
            @endif
            {{-- SATU baris di semua lebar. Rinciannya tetap disebut karena potongan & ongkir
                 tidak terlihat di mana pun lagi setelah kartu keterangan tergulir ke atas. --}}
            <p class="tabular mt-0.5 text-[0.75rem] text-umber">
                Barang {{ $rupiah($ringkasan['subtotal']) }} · potongan {{ $rupiah($ringkasan['diskon']) }} · ongkir {{ $rupiah($ringkasan['ongkir']) }}
            </p>
        </div>

        {{-- Lebar penuh di ponsel, tinggi minimum bukan tetap: teks tombol bisa jatuh ke dua
             baris, dan tombol lebar-tetap di 390px melebarkan halaman. --}}
        <button type="button" wire:click="simpan" wire:loading.attr="disabled"
                class="tombol-utama min-h-12 w-full cursor-pointer px-8 py-3 text-[0.9375rem] sm:w-auto sm:min-w-[16rem]">
            <span class="tombol-ikon" wire:loading.remove wire:target="simpan">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M4.5 4.5h8L15.5 7.5v8h-11v-11Zm2.5 0h5v3.5h-5V4.5Zm0 6.5h6v4.5h-6V11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            {{-- Dua bentuk teks: "Simpan nota — barang masuk stok" 31 karakter, di 390px ia
                 terbelah dua baris dan tombol utama layar ini menjadi kotak tinggi
                 bertumpuk. Yang dijanjikan tombolnya (stok bertambah) tetap tertulis di
                 kalimat pembuka kartu alat di atas.

                 Janjinya MENGIKUTI pilihan "barangnya sudah datang". Bunyi tetap "barang masuk
                 stok" adalah kebohongan pada nota yang barangnya belum sampai — dan tombol
                 simpan adalah tempat terakhir orang membaca sebelum menekan, jadi kalimat yang
                 salah di situ tidak pernah terbantah oleh apa pun sesudahnya. --}}
            <span wire:loading.remove wire:target="simpan">
                <span class="sm:hidden">Simpan nota</span>
                @if ($sudahDatang)
                    <span class="hidden sm:inline">Simpan nota — barang masuk stok</span>
                @else
                    <span class="hidden sm:inline">Simpan nota — belum masuk stok</span>
                @endif
            </span>
            <span wire:loading wire:target="simpan">Menyimpan…</span>
        </button>
    </div>
</div>
