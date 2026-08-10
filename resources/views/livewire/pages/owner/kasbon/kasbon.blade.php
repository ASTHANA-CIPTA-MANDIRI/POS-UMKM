{{--
    Buku kasbon: siapa berutang berapa, dan setoran apa saja yang sudah masuk.

    Data dari komponen: $daftar (paginator CreditLedger ber-eager-load customer + payments),
    $totalPiutang, $jumlahBerutang, $kasbonTerpilih, $sisaTerpilih, $pelangganTersedia.

    BENTUKNYA BUKAN TABEL, dan itu keputusan sadar — beda dengan layar Pelanggan/Bahan/Stok
    yang memang bertabel di ≥lg. Satu baris kasbon membawa riwayat setoran di dalamnya, dan
    riwayat bersarang di dalam sel tabel memaksa salah satu dari dua hal: riwayatnya
    disembunyikan (lalu buku tulis tetap menang, karena pertanyaan "kapan saya bayar yang
    seratus ribu itu" tidak terjawab), atau barisnya melar dan seluruh kolomnya bergerigi.
    Kartu memberi tiap kasbon ruangnya sendiri di semua lebar layar.
--}}
@php
    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');

    // Diketik SEKALI: dipakai tiap baris riwayat setoran, dan teks yang ditulis berulang
    // pasti bercabang begitu salah satunya diperbaiki.
    $pesanBatalSetoran = 'Sisa utangnya kembali seperti sebelum setoran ini dicatat. '
        .'Catatan yang keliru tetap terbaca di riwayat, bertanda dibatalkan.';
@endphp

<div x-data>
    <x-kartu-alat
        judul="Kasbon"
        jumlah="{{ $jumlahBerutang }}"
        keterangan="Buku utang pelanggan. Tiap setoran dicatat sendiri berikut tanggalnya — jadi pertanyaan “kapan saya bayar yang seratus ribu itu?” ada jawabannya."
    >
        <x-slot:aksi>
            <a href="{{ route('owner.pelanggan') }}" wire:navigate
               class="tombol-kedua flex h-11 cursor-pointer items-center gap-2 px-4 text-[0.875rem]">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-6 7c0-2.8 2.7-5 6-5s6 2.2 6 5"
                          stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <span class="sm:hidden">Pelanggan</span>
                <span class="hidden sm:inline">Data pelanggan</span>
            </a>

            <button type="button" wire:click="tambahKasbon"
                    class="flex h-11 cursor-pointer items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <span class="sm:hidden">Baru</span>
                <span class="hidden sm:inline">Kasbon baru</span>
            </button>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <div class="relative min-w-0">
                    <label for="cari-kasbon" class="sr-only">Cari pelanggan</label>
                    <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                         fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <input id="cari-kasbon" type="search" wire:model.live.debounce.300ms="cari"
                           placeholder="Cari nama atau nomor HP…"
                           class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                </div>

                <div>
                    <label for="status-kasbon" class="sr-only">Saring status</label>
                    <select id="status-kasbon" wire:model.live="saringStatus"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-semibold text-ink focus:border-terracotta focus:outline-none sm:w-auto">
                        {{-- "Belum lunas" DULUAN dan jadi bawaan: yang dibawa orang ke layar
                             ini adalah menagih, bukan mengarsip. --}}
                        <option value="belum">Belum lunas</option>
                        <option value="lunas">Sudah lunas</option>
                        <option value="semua">Semua</option>
                    </select>
                </div>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- Total piutang SENGAJA tidak ikut saringan — lihat alasannya di komponen. Muncul
         hanya kalau ada isinya: kotak berbunyi "Rp 0" tiap hari mengajarkan mata untuk
         melewatinya, dan hari angkanya besar ia ikut terlewat. --}}
    @if ($totalPiutang > 0)
        <div class="kartu mb-4 flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <p class="eyebrow text-umber-soft"><x-jelaskan kunci="kasbon" sebagai="Total belum kembali" /></p>
                <p class="tabular mt-0.5 text-[1.375rem] font-bold text-ink">{{ $rupiah($totalPiutang) }}</p>
            </div>
            <p class="text-[0.8125rem] text-umber">
                Dari {{ $jumlahBerutang }} pelanggan. Angkanya tidak ikut berubah saat daftarnya disaring.
            </p>
        </div>
    @endif

    {{-- ── Daftar ────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong
            :ikon="$cari !== '' ? 'cari' : 'lembar'"
            judul="{{ $cari !== ''
                ? 'Tidak ada kasbon yang cocok'
                : ($saringStatus === 'belum' ? 'Tidak ada utang yang menggantung' : 'Belum ada kasbon') }}"
            keterangan="{{ $cari !== ''
                ? 'Coba ubah kata pencariannya, atau ganti saringan statusnya.'
                : ($saringStatus === 'belum'
                    ? 'Semua kasbon sudah lunas. Ganti saringannya ke “Semua” untuk melihat yang sudah selesai.'
                    : 'Kasbon lahir sendiri saat kasir menjual dengan cara bayar utang — atau catat manual di sini.') }}"
        >
            <x-slot:aksi>
                <button type="button" wire:click="tambahKasbon"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Kasbon baru
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        <div class="space-y-3">
            @foreach ($daftar as $kasbon)
                @php
                    $sisa = $kasbon->sisaUtang();
                    $lunas = $sisa <= 0;
                    $telat = $kasbon->isOverdue();
                    $setoran = $kasbon->payments;
                @endphp
                <div class="kartu p-4 sm:p-5" wire:key="kasbon-{{ $kasbon->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[1rem] font-bold text-ink">
                                {{ $kasbon->customer?->nama ?? 'Pelanggan terhapus' }}
                            </p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                Dicatat {{ $kasbon->created_at->locale('id')->translatedFormat('j M Y') }}
                                @if ($kasbon->tanggal_jatuh_tempo)
                                    · jatuh tempo {{ $kasbon->tanggal_jatuh_tempo->locale('id')->translatedFormat('j M Y') }}
                                @endif
                            </p>
                        </div>

                        {{-- Lencana STATUS, dan hanya status — bukan angka. Lencana di aplikasi
                             ini berarti keadaan; memakainya untuk nominal mengajarkan orang
                             bahwa lencana tidak berarti apa-apa. --}}
                        {{-- <x-lencana>, BUKAN span berwarna sendiri: kontras merahnya sudah
                             dihitung di komponen itu (merah-tua 7,14:1 di atas tint bg-merah/10,
                             sementara merah-deep cuma 4,15:1), dan titik di depan teks membuat
                             statusnya terbaca tanpa bergantung warna saja.

                             `denyut` dimatikan: denyut menarik mata ke PERUBAHAN, dan di daftar
                             sepuluh kasbon yang semuanya belum lunas, sepuluh titik berdenyut
                             sekaligus tidak menunjuk apa pun. --}}
                        @if ($lunas)
                            <x-lencana warna="hijau" :denyut="false" class="shrink-0">Lunas</x-lencana>
                        @elseif ($telat)
                            <x-lencana warna="merah" :denyut="false" class="shrink-0">Lewat jatuh tempo</x-lencana>
                        @else
                            <x-lencana :denyut="false" class="shrink-0">Belum lunas</x-lencana>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Utang</p>
                            <p class="tabular text-[0.9375rem] font-bold text-ink">{{ $rupiah($kasbon->jumlah_utang) }}</p>
                        </div>
                        <div class="rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Sudah dibayar</p>
                            <p class="tabular text-[0.9375rem] font-bold text-ink">{{ $rupiah($kasbon->jumlah_dibayar) }}</p>
                        </div>
                        {{-- Kotak sisa diberi warna karena ia angka yang dicari orang lebih
                             dulu daripada dua kotak di sebelahnya. Tint `bg-merah/8` dengan
                             teks `merah-tua`, sama seperti lencana merah — bukan tint acak,
                             supaya kontrasnya ikut yang sudah diukur. --}}
                        <div class="rounded-xl border px-3 py-2 {{ $lunas ? 'border-line' : 'border-merah/25 bg-merah/8' }}">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Sisa</p>
                            <p class="tabular text-[0.9375rem] font-bold {{ $lunas ? 'text-umber-soft' : 'text-merah-tua' }}">
                                {{ $lunas ? '—' : $rupiah($sisa) }}
                            </p>
                        </div>
                    </div>

                    @if ($kasbon->catatan)
                        <p class="mt-3 text-[0.8125rem] text-umber">{{ $kasbon->catatan }}</p>
                    @endif

                    {{-- ── Riwayat setoran ──────────────────────────────────────
                         Inilah sebabnya barisnya berbentuk kartu. Yang dibawa orang ke buku
                         kasbon bukan cuma "berapa sisanya", tapi "kapan saya bayar" — dan
                         selama aplikasi tidak bisa menjawab itu, buku tulis tetap menang. --}}
                    @if ($setoran->isNotEmpty())
                        <div class="mt-4 border-t border-line-soft pt-3">
                            <p class="eyebrow text-umber-soft">Riwayat setoran</p>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($setoran as $bayar)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-cream/60 px-3 py-2"
                                        wire:key="setor-{{ $bayar->id }}">
                                        <span class="min-w-0 text-[0.8125rem] text-umber">
                                            <span class="tabular font-bold text-ink">{{ $rupiah($bayar->jumlah) }}</span>
                                            · {{ $bayar->dibayar_pada_formatted }}
                                            {{-- Penerimanya disebut: setoran tanpa penerima
                                                 tidak bisa ditanyakan ke siapa pun kalau
                                                 angkanya nanti dipersoalkan. --}}
                                            @if ($bayar->penerima)
                                                · diterima {{ $bayar->penerima->name }}
                                            @endif
                                            @if ($bayar->catatan)
                                                <span class="block text-[0.75rem] text-umber-soft">{{ $bayar->catatan }}</span>
                                            @endif
                                        </span>

                                        <button type="button"
                                                aria-label="Batalkan setoran {{ $rupiah($bayar->jumlah) }} {{ $bayar->dibayar_pada_formatted }}"
                                                class="tombol-bahaya h-8 shrink-0 cursor-pointer rounded-lg px-3 text-[0.75rem] font-semibold"
                                                x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Batalkan setoran '.$rupiah($bayar->jumlah).'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanBatalSetoran) }}, tombolYa: 'Ya, batalkan', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.batalkanSetoran({{ \Illuminate\Support\Js::from($bayar->id) }}))">
                                            Batalkan
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @unless ($lunas)
                        <div class="mt-4 flex justify-end border-t border-line-soft pt-3">
                            <button type="button" wire:click="setor('{{ $kasbon->id }}')"
                                    class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                                Catat setoran
                            </button>
                        </div>
                    @endunless
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif

    {{-- ── Panel setoran ─────────────────────────────────────────────────── --}}
    @if ($panel && $kasbonTerpilih)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-navy-900/45 p-0 sm:items-center sm:p-4"
             wire:key="panel-setor">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px]">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-bold text-ink">Catat setoran</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">
                            {{ $kasbonTerpilih->customer?->nama ?? 'Pelanggan terhapus' }} · sisa
                            <span class="tabular font-bold text-merah-tua">{{ $rupiah($sisaTerpilih) }}</span>
                        </p>
                    </div>
                    <button type="button" wire:click="tutupPanel" aria-label="Tutup"
                            class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition hover:bg-cream">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="simpanSetoran" class="px-5 py-4">
                    <div class="space-y-4">
                        <div>
                            <label for="jumlah-setor" class="block text-[0.8125rem] font-semibold text-ink">
                                Jumlah setoran <x-wajib />
                            </label>
                            <div class="relative mt-1.5">
                                <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                                {{-- value= DITULIS SENDIRI: Livewire tidak mencetak nilai awal
                                     untuk kolom ber-wire:model, jadi tombol "Sisa semuanya" di
                                     bawah mengisi properti di server tapi kotaknya tetap
                                     terlihat kosong — dan orang mengetik ulang angkanya. --}}
                                <input id="jumlah-setor" type="text" inputmode="numeric" autofocus
                                       wire:model="jumlahSetor" value="{{ $jumlahSetor }}"
                                       autocomplete="off" placeholder="0"
                                       class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-[1rem] font-bold text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            </div>
                            <button type="button" wire:click="setorPenuh"
                                    class="mt-2 cursor-pointer text-[0.8125rem] font-semibold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">
                                Isi sisa semuanya ({{ $rupiah($sisaTerpilih) }})
                            </button>
                            @error('jumlahSetor')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="tanggal-setor" class="block text-[0.8125rem] font-semibold text-ink">
                                Tanggal setor <x-wajib />
                            </label>
                            <input id="tanggal-setor" type="date" wire:model="tanggalSetor"
                                   value="{{ $tanggalSetor }}" max="{{ now()->toDateString() }}"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                            {{-- Kalimat ini menjelaskan kenapa kotaknya ada sama sekali: tanpa
                                 itu orang mengira ia harus mencatat tepat saat uang diterima. --}}
                            <p class="mt-1 text-[0.75rem] text-umber">
                                Boleh tanggal kemarin kalau setorannya baru sempat dicatat sekarang. Tidak boleh tanggal besok.
                            </p>
                            @error('tanggalSetor')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="catatan-setor" class="block text-[0.8125rem] font-semibold text-ink">Catatan</label>
                            <input id="catatan-setor" type="text" wire:model="catatanSetor"
                                   value="{{ $catatanSetor }}" autocomplete="off"
                                   placeholder="boleh dikosongkan — mis. dititip anaknya"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('catatanSetor')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="tutupPanel"
                                class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:order-1">
                            Batal
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                                class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60 sm:order-2">
                            <span wire:loading.remove wire:target="simpanSetoran">Simpan setoran</span>
                            <span wire:loading wire:target="simpanSetoran">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── Panel kasbon baru ─────────────────────────────────────────────── --}}
    @if ($panelBaru)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-navy-900/45 p-0 sm:items-center sm:p-4"
             wire:key="panel-kasbon-baru">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px]">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-bold text-ink">Kasbon baru</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">
                            Untuk utang yang tidak lahir dari struk kasir — mis. dicatat di buku lalu dipindahkan ke sini.
                        </p>
                    </div>
                    <button type="button" wire:click="tutupPanelBaru" aria-label="Tutup"
                            class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition hover:bg-cream">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="simpanKasbon" class="px-5 py-4">
                    <div class="space-y-4">
                        <div>
                            <label for="pelanggan-kasbon" class="block text-[0.8125rem] font-semibold text-ink">
                                Pelanggan <x-wajib />
                            </label>
                            @if ($pelangganTersedia->isEmpty())
                                {{-- Daftar kosong TIDAK dibiarkan jadi <select> kosong: kotak
                                     pilihan tanpa isi terbaca sebagai aplikasi yang rusak,
                                     bukan sebagai "datanya memang belum ada". --}}
                                <p class="mt-1.5 rounded-xl border border-line bg-cream/60 px-4 py-3 text-[0.8125rem] text-umber">
                                    Belum ada pelanggan. Masukkan orangnya dulu di
                                    <a href="{{ route('owner.pelanggan') }}" wire:navigate
                                       class="font-semibold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">Data pelanggan</a>
                                    — kasbon selalu menempel pada nama, supaya bisa ditagih.
                                </p>
                            @else
                                <select id="pelanggan-kasbon" wire:model="pelangganId"
                                        class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                    <option value="">— pilih pelanggan —</option>
                                    @foreach ($pelangganTersedia as $p)
                                        <option value="{{ $p->id }}" @selected($pelangganId === $p->id)>
                                            {{ $p->nama }}@if ($p->no_hp) · {{ $p->no_hp }}@endif
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                            @error('pelangganId')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="jumlah-utang" class="block text-[0.8125rem] font-semibold text-ink">
                                    Jumlah utang <x-wajib />
                                </label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                                    <input id="jumlah-utang" type="text" inputmode="numeric"
                                           wire:model="jumlahUtang" value="{{ $jumlahUtang }}"
                                           autocomplete="off" placeholder="0"
                                           class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-[1rem] font-bold text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                </div>
                                @error('jumlahUtang')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                {{-- TIDAK berbintang: validatornya `nullable`. Bintang pada
                                     medan opsional membuat orang berhenti memercayai bintangnya
                                     — lalu melewatkan yang sungguh wajib. --}}
                                <label for="jatuh-tempo" class="block text-[0.8125rem] font-semibold text-ink">
                                    Jatuh tempo
                                </label>
                                <input id="jatuh-tempo" type="date" wire:model="jatuhTempo"
                                       value="{{ $jatuhTempo }}" min="{{ now()->toDateString() }}"
                                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                <p class="mt-1 text-[0.75rem] text-umber-soft">
                                    Boleh dikosongkan. Kalau diisi, kasbonnya bertanda merah setelah lewat.
                                </p>
                                @error('jatuhTempo')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label for="catatan-utang" class="block text-[0.8125rem] font-semibold text-ink">Catatan</label>
                            <input id="catatan-utang" type="text" wire:model="catatanUtang"
                                   value="{{ $catatanUtang }}" autocomplete="off"
                                   placeholder="boleh dikosongkan — mis. belanja 3 hari"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('catatanUtang')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="tutupPanelBaru"
                                class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:order-1">
                            Batal
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                                @disabled($pelangganTersedia->isEmpty())
                                class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60 sm:order-2">
                            <span wire:loading.remove wire:target="simpanKasbon">Simpan</span>
                            <span wire:loading wire:target="simpanKasbon">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
