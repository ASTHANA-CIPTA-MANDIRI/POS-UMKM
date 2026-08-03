@extends('layouts.publik')

@section('judul', 'Cara Jualan')
@section('deskripsi', 'Tiga mode transaksi dalam satu kasir: bayar langsung untuk kelontong, buka bill untuk warteg, dan titip-ambil untuk depot air serta laundry.')

@section('konten')
    @include('partials.landing.page-header', [
        'eyebrow' => '01 — Cara jualan',
        'judul' => 'Tiga cara jualan,<br class="hidden sm:block"> satu kasir.',
        'intro' => 'Warung yang juga jual sembako tidak harus memilih salah satu. Mode dipilih kasir tiap kali mulai transaksi baru, bukan dikunci satu untuk seluruh outlet.',
    ])

    <section class="relative grain py-16 sm:py-20">
        <div class="relative mx-auto max-w-7xl space-y-5 px-5 sm:px-6 lg:px-8">
            @foreach ([
                [
                    'anchor' => 'bayar-langsung',
                    'no' => '01',
                    'judul' => 'Bayar langsung',
                    'untuk' => 'Kelontong, retail, depot air',
                    'isi' => 'Alur paling klasik: pembeli datang, ambil barang, bayar di kasir. Tidak ada jeda antara pesan dan bayar, jadi kasir tidak perlu menyimpan apa pun.',
                    'detail' => [
                        'Scan barcode beberapa item sekaligus',
                        'Konversi satuan — beli per dus, jual per pcs, harga ikut menyesuaikan',
                        'Bayar campuran dalam satu transaksi: tunai + QRIS',
                        'Diskon per item atau per transaksi',
                        'Kasbon dicatat setelah struk tercetak, terpisah dari proses bayar',
                    ],
                ],
                [
                    'anchor' => 'buka-bill',
                    'no' => '02',
                    'judul' => 'Buka bill dulu, bayar di akhir',
                    'untuk' => 'Warteg, rumah makan',
                    'isi' => 'Pelanggan pesan beberapa kali sebelum pulang. Tiap pesanan masuk ke satu bill yang tetap terbuka, dan struk final baru dicetak saat pelanggan mau bayar.',
                    'detail' => [
                        'Banyak bill terbuka sekaligus, bisa dibuka-tutup tidak berurutan',
                        'Label bill berupa nomor meja atau nama pelanggan',
                        'Grid tombol besar per lauk — tanpa barcode, tinggal tap',
                        'Modifier: pedas atau tidak, nasi banyak atau sedikit',
                        'Stok bahan baku berkurang otomatis lewat resep tiap porsi',
                    ],
                ],
                [
                    'anchor' => 'titip-ambil',
                    'no' => '03',
                    'judul' => 'Pesan-antar &amp; titip-ambil',
                    'untuk' => 'Depot air, laundry, katering',
                    'isi' => 'Barang atau pekerjaan diterima hari ini, diambil beberapa hari kemudian, dan bayarnya belum tentu saat itu. Statusnya berjalan sampai tuntas.',
                    'detail' => [
                        'Status berjalan: diterima → diproses → siap diambil → selesai &amp; dibayar',
                        'Nota titipan tercetak saat barang diterima, jadi bukti klaim pelanggan',
                        'Struk lunas tercetak terpisah saat pembayaran selesai',
                        'Hitung per kg (laundry) atau per item (bed cover, katering)',
                        'Galon kosong titipan dilacak terpisah dari stok galon isi',
                    ],
                ],
            ] as $mode)
                <article id="{{ $mode['anchor'] }}" data-tilt-in class="scroll-mt-28 rounded-2xl border border-line-soft bg-paper p-6 sm:p-9">
                    <div class="grid gap-8 lg:grid-cols-[1fr_1.2fr] lg:gap-14">
                        <div>
                            <div class="flex items-baseline gap-4">
                                <span class="tabular font-mono text-sm tracking-[0.14em] text-terracotta-soft">{{ $mode['no'] }}</span>
                                <span class="eyebrow text-umber-soft">{{ $mode['untuk'] }}</span>
                            </div>
                            <h2 class="mt-5 text-[1.75rem] font-semibold text-balance text-ink sm:text-[2rem]">{!! $mode['judul'] !!}</h2>
                            <p class="mt-4 text-[0.9375rem] text-pretty text-umber">{{ $mode['isi'] }}</p>
                        </div>

                        <ul class="space-y-3 lg:border-l lg:border-line-soft lg:pl-10">
                            @foreach ($mode['detail'] as $detail)
                                <li class="flex items-start gap-3 text-[0.9375rem] text-ink">
                                    <svg viewBox="0 0 16 16" class="mt-1 size-4 shrink-0 text-olive-soft" fill="none" aria-hidden="true">
                                        <path d="m3 8.4 3.2 3.2L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    {!! $detail !!}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- Yang berlaku di semua mode. --}}
    <section class="relative grain border-t border-line-soft bg-cream-deep/40 py-16 sm:py-20">
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
            <h2 class="max-w-xl text-[1.75rem] font-semibold text-balance text-ink sm:text-[2rem]" data-reveal>
                Berlaku di ketiga mode.
            </h2>

            <div class="mt-10 grid gap-x-10 gap-y-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger>
                @foreach ([
                    ['Buka &amp; tutup kasir', 'Modal awal dicatat, kas fisik dibandingkan dengan hitungan sistem saat tutup shift.'],
                    ['Void dengan alasan', 'Batal transaksi wajib mengisi alasan dan butuh persetujuan owner.'],
                    ['Cetak thermal', 'Struk, rekap tutup kasir, rekap utang — semua lewat printer yang sudah ada.'],
                    ['Kasbon pelanggan', 'Utang tercatat per pelanggan lengkap dengan jatuh tempo dan sisa.'],
                    ['Mode offline', 'Semua mode tetap jalan tanpa internet, lalu tersinkron sendiri.'],
                    ['Branding sendiri di struk', 'Nama dan logo warung Anda di struk — tersedia di semua paket.'],
                ] as [$judul, $isi])
                    <div class="rule pt-5">
                        <h3 class="text-[0.9375rem] font-semibold text-ink">{!! $judul !!}</h3>
                        <p class="mt-2 text-[0.875rem] text-umber">{{ $isi }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
