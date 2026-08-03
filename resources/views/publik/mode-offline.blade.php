@extends('layouts.publik')

@section('judul', 'Mode Offline')
@section('deskripsi', 'Kasir tetap bisa transaksi saat internet mati. Antrean disimpan di perangkat lalu dikirim sendiri begitu jaringan kembali, tanpa risiko transaksi tercatat dobel.')

@section('konten')
    @include('partials.landing.page-header', [
        'eyebrow' => '02 — Saat internet mati',
        'judul' => 'Listrik mati.<br class="hidden sm:block"> Kasir tetap jalan.',
        'intro' => 'Sinyal di gang sempit memang begitu. Kasir tidak perlu menunggu jaringan, dan tidak perlu melakukan apa pun saat jaringan kembali.',
    ])

    {{-- Tiga langkah alurnya. --}}
    <section class="relative grain py-16 sm:py-20">
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
            <div class="grid gap-5 lg:grid-cols-3" data-stagger>
                @foreach ([
                    ['01', 'Jaringan hilang', 'Kasir tidak diblokir. Transaksi tetap tercatat lengkap dengan item, pembayaran, dan waktu menurut jam perangkat.'],
                    ['02', 'Antre di perangkat', 'Struk tetap tercetak seperti biasa. Antrean menumpuk di perangkat dengan nomor identitas masing-masing.'],
                    ['03', 'Jaringan kembali', 'Antrean dikirim otomatis. Stok, kas, dan kasbon disesuaikan seperti transaksi itu terjadi online.'],
                ] as [$no, $judul, $isi])
                    <article class="rounded-2xl border border-line-soft bg-paper p-6 sm:p-7">
                        <span class="tabular font-mono text-xs tracking-[0.14em] text-terracotta-soft">{{ $no }}</span>
                        <h2 class="mt-5 text-xl font-semibold text-ink">{{ $judul }}</h2>
                        <p class="mt-3 text-[0.9375rem] text-umber">{{ $isi }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Bagian gelap: penjelasan idempotensi + kartu hasil sinkron. --}}
    <section class="relative grain overflow-hidden bg-ink py-20 text-cream sm:py-24">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-20 right-1/4 size-[28rem] rounded-full bg-[radial-gradient(circle_at_center,rgb(117_81_255/0.20),transparent_65%)] blur-3xl"></div>
            <div class="absolute -bottom-32 -left-20 size-[26rem] rounded-full bg-[radial-gradient(circle_at_center,rgb(117_81_255/0.18),transparent_68%)] blur-3xl"></div>
        </div>

        <div class="relative mx-auto grid max-w-7xl items-center gap-14 px-5 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:px-8">
            <div data-reveal>
                <p class="eyebrow text-amber-glow">Kenapa tidak pernah dobel</p>
                <h2 class="mt-5 text-[1.875rem] font-semibold text-balance text-cream sm:text-[2.5rem]">
                    Dikirim ulang berapa kali pun, tetap satu.
                </h2>
                <p class="mt-5 text-[1.0625rem] text-pretty text-cream/70">
                    Ini masalah yang paling sering merusak omzet di sistem offline: koneksi putus tepat setelah
                    server menyimpan, tapi sebelum perangkat menerima balasan. Perangkat mengira gagal, lalu
                    mengirim lagi.
                </p>
                <p class="mt-4 text-[1.0625rem] text-pretty text-cream/70">
                    Di Nampan tiap transaksi sudah punya nomor identitas sendiri sejak dibuat di perangkat, bukan
                    dibuat server. Kiriman kedua dikenali sebagai transaksi yang sama dan dilaporkan sebagai
                    duplikat — bukan dibuat ulang.
                </p>

                <ul class="mt-9 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'Stok ikut disesuaikan saat sinkron',
                        'Uang tunai masuk ke sesi kas yang benar',
                        'Satu transaksi gagal tidak menjatuhkan sisanya',
                        'Riwayat sinkron tercatat per perangkat',
                    ] as $poin)
                        <li class="flex items-start gap-2.5 text-[0.875rem] text-cream/85">
                            <svg viewBox="0 0 16 16" class="mt-1 size-3.5 shrink-0 text-amber-glow" fill="none" aria-hidden="true">
                                <path d="m3 8.4 3.2 3.2L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ $poin }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <div data-reveal="0.15" class="rounded-2xl border border-cream/10 bg-cream/[0.04] p-5 backdrop-blur-sm sm:p-7">
                <div class="flex items-center justify-between">
                    <p class="eyebrow text-cream/50">Antrean perangkat</p>
                    <span class="flex items-center gap-1.5 rounded-full bg-amber-glow/15 px-2.5 py-1">
                        <span class="size-1.5 rounded-full bg-amber-glow"></span>
                        <span class="font-mono text-[0.5625rem] tracking-wider text-amber-glow uppercase">Tersinkron</span>
                    </span>
                </div>

                <div class="mt-5 space-y-2">
                    @foreach ([
                        ['TRX-OFF-901', 'Nasi, Ayam, Es Teh', '42.000'],
                        ['TRX-OFF-902', 'Nasi, Kerupuk', '11.000'],
                        ['TRX-OFF-903', 'Nasi, Ayam', '27.000'],
                    ] as [$kode, $isi, $total])
                        <div class="flex items-center justify-between gap-3 rounded-xl bg-cream/[0.04] px-3.5 py-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[0.625rem] tracking-wider text-cream/45">{{ $kode }}</p>
                                <p class="mt-0.5 truncate text-[0.8125rem] text-cream/90">{{ $isi }}</p>
                            </div>
                            <span class="tabular shrink-0 font-mono text-[0.8125rem] text-amber-glow">Rp {{ $total }}</span>
                        </div>
                    @endforeach
                </div>

                <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-cream/10 pt-5 text-center">
                    @foreach ([['3', 'dibuat'], ['0', 'duplikat'], ['0', 'gagal']] as [$angka, $label])
                        <div>
                            <dd class="tabular font-display text-2xl font-semibold text-cream">{{ $angka }}</dd>
                            <dt class="mt-0.5 font-mono text-[0.5625rem] tracking-[0.12em] text-cream/45 uppercase">{{ $label }}</dt>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    {{-- Batas yang jujur. Menjanjikan offline tanpa batas justru bikin masalah nanti. --}}
    <section class="relative grain py-16 sm:py-20">
        {{-- Wadah max-w-7xl agar tepi kiri sejajar dengan section lain. --}}
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8" data-reveal>
            <p class="eyebrow text-terracotta">Yang perlu diketahui</p>
            <h2 class="mt-5 text-[1.75rem] font-semibold text-balance text-ink sm:text-[2rem]">
                Batasnya juga kami sebut.
            </h2>
            <div class="mt-8 max-w-3xl space-y-5">
                @foreach ([
                    ['Pembayaran QRIS saat offline', 'Struk tetap tercetak, tapi konfirmasi masuknya dana baru bisa dicocokkan setelah jaringan kembali. Untuk transaksi besar sebaiknya tunggu sinyal.'],
                    ['Harga & menu yang berubah', 'Perangkat memakai daftar harga terakhir yang sempat diunduh. Kalau owner mengubah harga saat kasir offline, perubahan berlaku setelah sinkron.'],
                    ['Beberapa perangkat sekaligus', 'Dua kasir yang offline bersamaan bisa menjual stok yang sama sampai minus. Selisihnya diselesaikan lewat stok opname, dan sistem tidak menyembunyikannya.'],
                ] as [$judul, $isi])
                    <div class="rounded-xl border border-line-soft bg-paper p-5">
                        <h3 class="text-[0.9375rem] font-semibold text-ink">{{ $judul }}</h3>
                        <p class="mt-2 text-[0.875rem] text-umber">{{ $isi }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
