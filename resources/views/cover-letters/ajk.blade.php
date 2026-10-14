@extends('cover-letters.layout')

@section('title', 'Surat Pengantar Klaim AJK')
@section('section-id', 'surat-klaim-ajk')

@section('attachments')
      <p class="paragraph-tight">Telah meninggal dunia pada tanggal {{ $dateOfDeath }}. Oleh karena itu kami bermaksud mengajukan klaim asuransi jiwa kredit yang bersangkutan, dan sebagai dasar pengajuan klaim berikut kami lampirkan :</p>
      <ol class="before-alpha">
        <li>Surat Pengajuan Klaim</li>
        <li>Fotokopi Sertifikat Asuransi</li>
        <li>Fotokopi KTP/SIM dan KK, Peserta dan ahli waris</li>
        <li>Fotokopi perjanjian kredit atau bukti perjanjian pinjaman atau pembiayaan Debitur</li>
        <li>Rekening kredit nasabah yang bersangkutan/ SPK / Daftar Sisa Pinjaman</li>
        <li>Dokumen tambahan :</li>
      </ol>
      <ol class="alpha">
        <li>Surat Keterangan Kematian dari instansi/lembaga berwenang
          <ul class="bullets">
            <li>Surat keterangan dari Rumah Sakit apabila meninggal di Rumah Sakit;</li>
            <li>Surat keterangan dari Kepolisian jika meninggal dunia akibat kecelakaan; atau</li>
            <li>Surat dari Kedutaan jika meninggal dunia di luar negeri.</li>
          </ul>
        </li>
        <li>Apabila meninggal dunia di Rumah maka wajib melampirkan kronologis kematian dari Ahli Waris yang diketahui dan ditandatangani oleh ketua RT/RW setempat atau Akta Kematian dari Dukcapil.</li>
      </ol>
      <p class="payment">Demikian kami sampaikan, untuk pembayaran klaim dapat di transfer ke rekening MANDIRI dengan nomor rekening 167-001-811-9908 atas nama BPR DP TASPEN.</p>
      <p>Atas perhatian dan kerjasamanya kami ucapkan terima kasih.</p>
@endsection
