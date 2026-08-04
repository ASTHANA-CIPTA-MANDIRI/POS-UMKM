{{--
    PLACEHOLDER — tampilan sesungguhnya dikerjakan frontend.

    Berkas ini hanya ada supaya komponen bisa dirender oleh uji. Data yang sudah
    disediakan render() dan siap dipakai:

    $daftar        paginator baris stok (array: kunci, jenis, nama, kode, aktif,
                   product_id, raw_material_id, stock_id, punya_baris, sistem, minimum,
                   satuan, satuan_dasar, isi_per_satuan, status, kekurangan, beli,
                   opname_terakhir_pada, perlu_diperiksa)
    $harusBelanja  baris berstatus bukan `aman`, paling parah dulu; 'beli' berisi
                   {jumlah, satuan, satuan_dasar, isi_per_satuan} sudah dibulatkan ke atas
    $ringkasan     jumlah per status + perlu_diperiksa
    $barisKartu    baris yang kartu stoknya dibuka (atau null)
    $kartu         30 mutasi terakhir baris itu (waktu, tipe, alasan, jumlah,
                   saldo_sesudah, olehUser, catatan)
    $outletTersedia / $outletDipakai

    Aksi: ubahAmbang($kunci), simpanAmbang(), batalUbahAmbang(), bukaKartu($kunci),
    tutupKartu(). Properti ambang inline: $ambangKunci, $ambangNilai.
--}}
<div>
    <h1 class="text-xl font-semibold text-ink">Stok</h1>
</div>
