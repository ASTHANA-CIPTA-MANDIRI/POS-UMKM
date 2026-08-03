{{--
    Terminal kasir 3D — kotak CSS 3D enam sisi, bukan gambar.

    Tablet dibangun sebagai balok: sisi depan berisi layar kasir yang hidup, sisi
    belakang berupa punggung gelap, dan empat sisi tepi memberi ketebalan nyata.
    Di bawahnya ada leher dan pelat dudukan yang berbaring di lantai (rotateX 72°),
    sehingga terminal terlihat berdiri di suatu ruang, bukan mengapung.

    Objek ini BERGOYANG (±20°), tidak berputar penuh. Layar kasir adalah isi
    terpentingnya; memutarnya sampai membelakangi penonton membuang satu-satunya
    bagian yang menjelaskan produk.
--}}
<div data-term-scene class="term-scene" aria-hidden="true">
    <div data-term-tilt class="term-tilt">
        <div data-term-spin class="term-spin">
            {{-- Pelat dudukan: berbaring di lantai --}}
            <span class="term-base"></span>

            {{-- Leher penopang, sedikit di belakang tablet --}}
            <span class="term-neck"></span>

            <div class="term-tablet">
                {{-- Tepi-tepi balok, memberi ketebalan --}}
                <span class="term-edge term-edge-top"></span>
                <span class="term-edge term-edge-bottom"></span>
                <span class="term-edge term-edge-left"></span>
                <span class="term-edge term-edge-right"></span>

                {{-- Punggung tablet --}}
                <span class="term-back">
                    <span class="term-back-mark"></span>
                </span>

                {{-- Sisi depan: bezel + layar kasir --}}
                <div class="term-front">
                    <div class="term-screen">
                        <div class="term-topbar">
                            <span class="term-outlet">Benjamin Pusat</span>
                            <span class="term-badge">
                                <span class="term-dot"></span>
                                Meja 3
                            </span>
                        </div>

                        {{-- Grid tombol besar: cara input untuk warung tanpa barcode --}}
                        <div class="term-grid">
                            @foreach ([
                                ['Nasi', '5rb', false],
                                ['Ayam', '12rb', true],
                                ['Telur', '7rb', false],
                                ['Es Teh', '4rb', false],
                                ['Kerupuk', '2rb', false],
                                ['Air', '4rb', false],
                            ] as [$nama, $harga, $aktif])
                                <span class="term-btn {{ $aktif ? 'term-btn-aktif' : '' }}">
                                    <span class="term-btn-nama">{{ $nama }}</span>
                                    <span class="term-btn-harga">{{ $harga }}</span>
                                </span>
                            @endforeach
                        </div>

                        <div class="term-total">
                            <span class="term-total-ket">4 item</span>
                            <span class="term-total-nilai">Rp 42.000</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Cahaya layar & bayangan lantai. Di luar wadah yang berputar supaya tidak
         ikut miring bersama terminal. --}}
    <span class="term-glow"></span>
    <span class="term-floor"></span>
</div>
