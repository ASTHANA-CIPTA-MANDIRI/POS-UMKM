{{--
    PLACEHOLDER — tampilan sesungguhnya dikerjakan frontend.

    Berkas ini hanya ada supaya komponen bisa dirender oleh uji. Data yang sudah
    disediakan render():

    $daftar          paginator baris (bentuknya sama dengan layar stok, termasuk barang
                     yang belum punya baris stocks: punya_baris=false, sistem=0)
    $alasanTersedia  [{nilai, label}] dari App\Enums\AlasanOpname
    $jumlahTerisi    berapa baris sudah diketik SELURUH lembar (bukan hanya halaman ini)
    $outletTersedia / $outletDipakai

    Ikatan per baris (WAJIB di-key id barang, bukan indeks baris):
      wire:model="fisik.{{ $baris['kunci'] }}"
      wire:model="alasan.{{ $baris['kunci'] }}"
      wire:model="catatan.{{ $baris['kunci'] }}"
    Galatnya: @error('fisik.'.$kunci) / alasan.<kunci> / catatan.<kunci>

    Aksi: simpan().
--}}
<div>
    <h1 class="text-xl font-semibold text-ink">Opname stok</h1>
</div>
