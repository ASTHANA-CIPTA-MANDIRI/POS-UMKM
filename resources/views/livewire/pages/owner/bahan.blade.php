{{--
    Kelola bahan baku (bahan mentah yang dipotong resep saat menu terjual).

    Data dari komponen: $daftar (paginator RawMaterial ber-eager-load `products`),
    $satuanTersedia, $jumlahBahan, $jumlahMenuBerresep, plus properti formulir.

    Bentuknya menyalin layar Stok & hitung stok — patokan responsif di CLAUDE.md — bukan
    mengarang sendiri: kartu di bawah lg, tabel `table-fixed` berlebar PERSEN di atasnya,
    sel kosong selalu bertanda "—", dan 10 baris per halaman dengan ->links() yang selalu
    dirender.
--}}
@php
    /*
     * Kalimat dialog hapus diketik SEKALI di sini, bukan dua kali.
     *
     * Barisnya digambar dua bentuk (kartu di ponsel, tabel di layar lebar), jadi teks yang
     * ditulis di masing-masing tempat pasti bercabang begitu salah satunya diperbaiki — dan
     * yang bercabang adalah kalimat tentang apa yang hilang.
     *
     * Isinya menyebut apa yang TIDAK hilang, dan itu bagian terpentingnya: bahan memakai
     * soft delete, jadi nota belanja lama dan hitungan lama tetap terbaca apa adanya.
     * Peringatan yang lebih menakutkan daripada kenyataannya membuat orang berhenti
     * memercayai peringatan berikutnya.
     */
    $pesanHapusBahan = 'Bahannya hilang dari daftar ini dan dari layar Stok. Riwayat belanja '
        .'dan hitungan lama tetap tersimpan: nota bulan lalu tidak berubah.';

    // Satuan yang sedang dipilih di formulir, untuk label "…per kg". tryFrom, bukan from:
    // nilai dari klien tidak dipercaya, dan `from` melempar sebelum validasi berjalan.
    $satuanForm = \App\Enums\Satuan::tryFrom($satuan);
    $satuanLabel = $satuanForm !== null ? mb_strtolower($satuanForm->label()) : 'satuan';

    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');
@endphp

{{-- x-data di akar: pemicu hapus memakai x-on:click, dan Alpine hanya menghidupkan
     elemen yang berada di dalam sebuah lingkup x-data. Tanpa ini tombol hapusnya diam
     saja — tanpa galat yang terlihat di layar. --}}
<div x-data>
    <x-kartu-alat
        judul="Bahan baku"
        jumlah="{{ $jumlahBahan }}"
        keterangan="Bahan mentah yang dibeli dan dihitung per kilo, liter, atau ikat — bukan per porsi. Sisa bahan berkurang sendiri tiap menu terjual, setelah resepnya diisi."
    >
        <x-slot:aksi>
            <button type="button" wire:click="tambah"
                    class="flex h-11 cursor-pointer items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                {{-- Bentuk pendek di ponsel: "Bahan baru" pada tombol 211px di kartu 318px
                     memecah judulnya jadi tiga baris (terukur di layar Stok). --}}
                <span class="sm:hidden">Baru</span>
                <span class="hidden sm:inline">Bahan baru</span>
            </button>
        </x-slot:aksi>

        <x-slot:saringan>
            {{-- Satu kontrol saja, jadi ia mendapat lebar penuh. Saringan status TIDAK ada
                 di layar ini karena bahan tidak punya saklar aktif/nonaktif — lihat
                 alasannya di App\Livewire\Pages\Owner\Bahan. --}}
            <div class="relative min-w-0">
                <label for="cari-bahan" class="sr-only">Cari bahan</label>
                <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                     fill="none" aria-hidden="true">
                    <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                    <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <input id="cari-bahan" type="search" wire:model.live.debounce.300ms="cari"
                       placeholder="Cari nama bahan…"
                       class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Daftar ────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong
            :ikon="$cari !== '' ? 'cari' : 'kotak'"
            judul="{{ $cari !== '' ? 'Tidak ada bahan yang cocok' : 'Belum ada bahan baku' }}"
            keterangan="{{ $cari !== ''
                ? 'Coba ubah kata pencariannya, atau tambahkan bahan baru.'
                : 'Masukkan bahan mentahnya dulu — mis. Lele Segar per kg — lalu satu porsi menu bisa dihubungkan ke bahannya.' }}"
        >
            <x-slot:aksi>
                <button type="button" wire:click="tambah"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Bahan baru
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Dua bentuk untuk data yang sama: kartu di layar sempit, tabel di ≥lg. Tabel
             yang dipaksa masuk ke ponsel menuntut gulir mendatar, dan kolom harga justru
             yang pertama hilang dari pandangan. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $bahan)
                <div class="kartu p-4" wire:key="kartu-{{ $bahan->id }}">
                    {{-- Satuannya ditulis sebagai baris kedua nama, BUKAN sebagai lencana di
                         sisi kanan. Lencana di aplikasi ini berarti STATUS (dan titiknya
                         berdenyut untuk menarik mata); memakainya untuk "kg" mengajarkan
                         orang bahwa lencana tidak berarti apa-apa. --}}
                    <div class="min-w-0">
                        <p class="truncate text-[0.9375rem] font-bold text-ink">{{ $bahan->nama }}</p>
                        <p class="mt-0.5 text-[0.75rem] text-umber">
                            dihitung per {{ mb_strtolower($bahan->satuan?->label() ?? '—') }}
                        </p>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Harga beli</p>
                            {{-- "—" WAJIB, bukan kosong: kosong membuat pembacanya menebak
                                 "nol, atau belum diisi?" — dan nol berarti bahannya gratis. --}}
                            <p class="tabular text-[0.9375rem] font-bold text-ink">
                                @if ($bahan->harga_beli_terakhir !== null)
                                    {{ $rupiah($bahan->harga_beli_terakhir) }}
                                    <span class="block text-[0.75rem] font-medium text-umber">
                                        per {{ mb_strtolower($bahan->satuan?->label() ?? 'satuan') }}
                                    </span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.75rem] font-medium text-umber-soft">belum diisi</span>
                                @endif
                            </p>
                        </div>

                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Dipakai di</p>
                            @if ($bahan->products->isEmpty())
                                <p class="text-[0.9375rem] font-bold text-umber-soft">—</p>
                                <p class="text-[0.75rem] font-medium text-umber-soft">belum ada resep</p>
                            @else
                                <p class="tabular text-[0.9375rem] font-bold text-ink">
                                    {{ $bahan->products->count() }}
                                    <span class="text-[0.75rem] font-medium text-umber">menu</span>
                                </p>
                                {{-- Nama menunya, bukan cuma angkanya: yang ditanyakan orang
                                     di kolom ini adalah "kalau saya hapus ini, apa yang
                                     rusak". Keterangan panjang jadi baris KEDUA di kolom yang
                                     menjelaskannya, bukan berkolom sendiri di ujung. --}}
                                <p class="text-[0.75rem] text-umber">{{ $bahan->products->pluck('nama_produk')->join(', ') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-end gap-1.5">
                        <x-aksi warna="utama" label="Ubah {{ $bahan->nama }}"
                                class="size-10" wire:click="ubah('{{ $bahan->id }}')">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </x-aksi>

                        {{-- Konfirmasinya lewat pembungkus SweetAlert bersama
                             (window.konfirmasiNampan, resources/js/toast.js) — judulnya
                             menyebut NAMA bahannya, tombol pembenarnya berwarna bahaya, dan
                             fokus awal jatuh di tombol batal.

                             Dialog ini BUKAN pengamannya. Gerbang sungguhannya di
                             Bahan::hapus(): bahan yang masih dipakai resep DITOLAK di server,
                             karena `restrictOnDelete` tidak pernah menyala untuk soft delete. --}}
                        <x-aksi warna="bahaya" label="Hapus {{ $bahan->nama }}" class="size-10"
                                x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$bahan->nama.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusBahan) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($bahan->id) }}))">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M4 6h12M8 6V4.5h4V6m-6 0v9.5A1.5 1.5 0 0 0 7.5 17h5a1.5 1.5 0 0 0 1.5-1.5V6M8.5 9v5m3-5v5"
                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </x-aksi>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full table-fixed text-center">
                <thead>
                    {{-- Lebar kolom PERSEN + table-fixed, jumlahnya 100%. Kolom tanpa lebar
                         menyerap SELURUH sisa lebar kartu, dan lubangnya cuma berpindah
                         tempat — sudah terjadi dua kali di layar lain.

                         Judul rata TENGAH dan isinya juga (keputusan pemilik proyek): judul
                         yang tidak berdiri di atas datanya membuat mata menghitung ulang
                         kolomnya setiap kali turun satu baris. --}}
                    <tr class="border-b border-line">
                        <th class="w-[26%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nama bahan</th>
                        <th class="w-[12%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Satuan</th>
                        <th class="w-[22%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Harga beli terakhir</th>
                        <th class="w-[28%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Dipakai di</th>
                        {{-- Kolom aksi DIBERI NAMA: dua ikon tanpa keterangan di ujung baris
                             membuat orang harus menekannya untuk tahu apa yang akan terjadi. --}}
                        <th class="w-[12%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $bahan)
                        <tr class="transition-colors hover:bg-cream/60" wire:key="baris-{{ $bahan->id }}">
                            <td class="px-5 py-3.5">
                                <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $bahan->nama }}</p>
                            </td>

                            <td class="px-5 py-3.5 text-[0.875rem] text-umber">
                                {{ $bahan->satuan?->label() ?? '—' }}
                            </td>

                            <td class="tabular px-5 py-3.5 text-[0.9375rem]">
                                @if ($bahan->harga_beli_terakhir !== null)
                                    <span class="font-bold text-ink">{{ $rupiah($bahan->harga_beli_terakhir) }}</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">
                                        per {{ mb_strtolower($bahan->satuan?->label() ?? 'satuan') }}
                                    </span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">belum diisi</span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5 text-[0.875rem]">
                                @if ($bahan->products->isEmpty())
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">belum ada resep</span>
                                @else
                                    <span class="tabular font-semibold text-ink">{{ $bahan->products->count() }} menu</span>
                                    <span class="block text-[0.6875rem] text-umber">{{ $bahan->products->pluck('nama_produk')->join(', ') }}</span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5">
                                {{-- Dua tombol seukuran (36×36) dan sejajar. Bobotnya
                                     dibedakan lewat garis dan warna, bukan ukuran — ukuran
                                     yang berbeda-beda membuat kolomnya bergerigi. --}}
                                <div class="flex items-center justify-center gap-1.5">
                                    <x-aksi warna="utama" label="Ubah {{ $bahan->nama }}"
                                            wire:click="ubah('{{ $bahan->id }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>

                                    {{-- Sama seperti bentuk kartu: konfirmasinya lewat
                                         window.konfirmasiNampan (resources/js/toast.js). --}}
                                    <x-aksi warna="bahaya" label="Hapus {{ $bahan->nama }}"
                                            x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$bahan->nama.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusBahan) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($bahan->id) }}))">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 6h12M8 6V4.5h4V6m-6 0v9.5A1.5 1.5 0 0 0 7.5 17h5a1.5 1.5 0 0 0 1.5-1.5V6M8.5 9v5m3-5v5"
                                                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ->links() SELALU dirender: daftar tanpa navigasi halaman menyembunyikan
             barisnya tanpa memberi tahu siapa pun. --}}
        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif

    {{-- ── Panel formulir ────────────────────────────────────────────────── --}}
    @if ($panel)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-navy-900/45 p-0 sm:items-center sm:p-4"
             wire:key="panel-bahan">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px] md:max-w-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-bold text-ink">
                            {{ $bahanId ? 'Ubah bahan' : 'Bahan baru' }}
                        </h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">
                            Satuannya satuan BELANJA, bukan satuan jual. Lele dibeli per kg walau dijual per porsi.
                        </p>
                    </div>
                    <button type="button" wire:click="tutupPanel" aria-label="Tutup"
                            class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition hover:bg-cream">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="simpan" class="px-5 py-4">
                    <div class="space-y-4">
                        <div>
                            <label for="nama-bahan" class="block text-[0.8125rem] font-semibold text-ink">
                                Nama bahan <x-wajib />
                            </label>
                            {{-- value= DITULIS SENDIRI, dan itu bukan berlebihan: Livewire tidak
                                 mencetak nilai awal untuk kolom ber-wire:model, jadi menekan
                                 "Ubah" membuka formulir dengan kolom KOSONG walau bahannya
                                 bernama Lele Segar — lalu menyimpannya ditolak "nama wajib"
                                 untuk bahan yang jelas punya nama. Cacat yang sama sudah
                                 pernah terjadi di kolom batas minimal layar Stok. --}}
                            <input id="nama-bahan" type="text" wire:model="nama" value="{{ $nama }}"
                                   autofocus autocomplete="off"
                                   placeholder="mis. Lele Segar"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('nama')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="satuan-bahan" class="block text-[0.8125rem] font-semibold text-ink">
                                    Satuan <x-wajib />
                                </label>
                                {{-- .live supaya label harga di sebelahnya ikut menyebut
                                     satuannya. Tanpa itu, "Harga beli terakhir per kg"
                                     tetap berbunyi "kg" sesudah satuannya diganti jadi
                                     gram — dan angka yang salah satuannya adalah angka
                                     yang salah seribu kali. --}}
                                <select id="satuan-bahan" wire:model.live="satuan"
                                        class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                    {{-- @selected WAJIB, alasan yang sama dengan value= di kolom
                                         nama: tanpa itu <select> menampilkan pilihan PERTAMA
                                         (Pcs) sementara server memegang "kg". Di kolom satuan
                                         akibatnya lebih buruk daripada kolom kosong — orangnya
                                         membaca "Pcs", menyimpan tanpa menyentuh apa pun, dan
                                         mendapat bahan bersatuan kg. Tidak ada galat, dan satuan
                                         yang salah adalah angka yang salah di seluruh rantai. --}}
                                    @foreach ($satuanTersedia as $s)
                                        <option value="{{ $s->value }}" @selected($satuan === $s->value)>{{ $s->label() }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[0.75rem] text-umber-soft">
                                    Lebih suka mengetik 250 daripada 0,25? Pilih Gram atau ml — seluruh hitungan ikut memakai satuan itu.
                                </p>
                                @error('satuan')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                {{-- TIDAK berbintang: validatornya `nullable`. Bintang pada
                                     medan yang sebenarnya opsional membuat orang mengisi hal
                                     yang tidak perlu, lalu berhenti memercayai bintangnya —
                                     dan melewatkan yang sungguh wajib. --}}
                                <label for="harga-bahan" class="block text-[0.8125rem] font-semibold text-ink">
                                    Harga beli terakhir per {{ $satuanLabel }}
                                </label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                                    {{-- value= sekali lagi: harga yang sudah tersimpan harus
                                         terlihat saat formulirnya dibuka. Kolom yang terbuka
                                         kosong terbaca sebagai "belum diisi", dan menyimpan dari
                                         keadaan itu MENGHAPUS harga yang sudah ada. --}}
                                    <input id="harga-bahan" type="text" inputmode="numeric" wire:model="hargaBeli"
                                           value="{{ $hargaBeli }}" autocomplete="off" placeholder="0"
                                           class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-[1rem] font-bold text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                </div>
                                {{-- WAJIB ada. Tanpa kalimat ini pemilik menyimpulkan
                                     aplikasi melupakan angka yang ia ketik, karena angkanya
                                     memang berubah sendiri — TerimaPembelianAction menimpanya
                                     dengan harga per satuan dasar dari nota terakhir. --}}
                                <p class="mt-1 text-[0.75rem] text-umber">
                                    Angka ini ikut berubah sendiri setiap nota belanja ditandai barangnya datang.
                                </p>
                                @error('hargaBeli')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{--
                            Tiga hal yang SENGAJA tidak ada di formulir ini, dan alasannya
                            ditulis di LAYAR — bukan cuma di komentar kode.

                            Medan yang hilang tanpa penjelasan terbaca sebagai fitur yang
                            belum jadi, dan orang lalu mencarinya di tempat yang salah atau
                            menyimpulkan aplikasinya tidak menghitung. Yang menghilangkan
                            kesimpulan itu bukan komentar di berkas PHP; kalimat inilah yang
                            dibaca pemiliknya.
                        --}}
                        <div class="rounded-xl border border-line p-3.5">
                            <p class="eyebrow text-umber-soft">Diatur di tempat lain</p>
                            <ul class="mt-2 space-y-1.5 text-[0.8125rem] text-umber">
                                <li class="flex gap-2">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                                    <span><span class="font-semibold text-ink">Batas minimal</span> disetel di layar Stok, per cabang — cabang yang ramai butuh cadangan lebih banyak.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                                    <span><span class="font-semibold text-ink">Sisa bahan</span> diisi lewat Hitung stok atau nota belanja, supaya tiap perubahan punya catatan pergerakannya.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                                    <span>Sisa bahan <span class="font-semibold text-ink">berkurang sendiri tiap menu terjual</span> — setelah resepnya diisi.</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="tutupPanel"
                                class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:order-1">
                            Batal
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                                class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60 sm:order-2">
                            <span wire:loading.remove wire:target="simpan">Simpan</span>
                            <span wire:loading wire:target="simpan">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
