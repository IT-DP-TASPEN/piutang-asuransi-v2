<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $letter->letter_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 10mm 13mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111827; font-family: "DejaVu Sans", Arial, sans-serif; font-size: 8.5pt; line-height: 1.28; }
        .screen-controls { position: fixed; top: 12px; right: 12px; z-index: 10; }
        .screen-controls button { border: 0; border-radius: 6px; background: #0f766e; color: white; cursor: pointer; padding: 9px 14px; }
        .page { width: 100%; }
        .logo { display: block; width: 175px; height: auto; margin-bottom: 4px; }
        .meta { margin-left: auto; width: 55%; }
        .date-line { margin: 0 0 4px; text-align: right; }
        .info-table, .detail-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 1px 3px; vertical-align: top; }
        .info-table .label { width: 74px; }
        .info-table .colon { width: 12px; }
        .recipient { margin: 12px 0 3px; }
        .recipient p { margin: 1px 0; }
        .attn { margin: 5px 0 10px; text-decoration: underline; }
        .body-section p { margin: 5px 0; text-align: justify; }
        .claim-table { width: 100%; margin: 6px 0; border-collapse: collapse; table-layout: fixed; }
        .claim-table th, .claim-table td { border: 1px solid #111827; padding: 3px 4px; text-align: center; vertical-align: middle; word-wrap: break-word; }
        .claim-table th { background: #e5e7eb; font-weight: 700; }
        .detail-table { margin: 5px 0 7px; }
        .detail-table td { border: 1px solid #9ca3af; padding: 3px 4px; }
        .detail-table .label { width: 24%; background: #f3f4f6; font-weight: 700; }
        ol { margin: 3px 0 6px 18px; padding: 0; }
        li { margin: 1px 0; }
        .signature { margin-top: 12px; page-break-inside: avoid; }
        .signature .company { font-weight: 700; }
        .footer { margin-top: 14px; padding-top: 5px; border-top: 1px solid #6b7280; color: #374151; font-size: 6.5pt; text-align: center; }
        .footer .company { font-weight: 700; }
        @media print { .screen-controls { display: none !important; } }
    </style>
</head>
<body>
<div class="screen-controls"><button type="button" onclick="window.print()">Print</button></div>
<section class="page">
    <img class="logo" src="{{ $logoUrl }}" alt="Bank DP Taspen">

    <div class="meta">
        <p class="date-line">Bekasi, {{ $letterDate }}</p>
        <table class="info-table" aria-label="Informasi surat">
            <tr><td class="label">Nomor</td><td class="colon">:</td><td><strong>{{ $letter->letter_number }}</strong></td></tr>
            <tr><td class="label">Sifat</td><td class="colon">:</td><td>Penting</td></tr>
            <tr><td class="label">Lampiran</td><td class="colon">:</td><td>1 (satu) berkas</td></tr>
        </table>
    </div>

    <div class="recipient">
        <p>Kepada Yth.</p>
        <p><strong>{{ $recipientName }}</strong><br>{!! nl2br(e($recipientAddress ?? '')) !!}</p>
    </div>
    <p class="attn">Up: Bagian Klaim Asuransi</p>

    <div class="body-section">
        <p>Dengan hormat,</p>
        <p>Dengan ini kami sampaikan pengajuan klaim untuk nasabah kami:</p>

        <table class="claim-table" aria-label="Data klaim asuransi">
            <thead>
            <tr>
                <th>Nama</th>
                <th>Nominal Pinjaman</th>
                <th>Nominal Klaim</th>
                <th>Tanggal Realisasi Kredit</th>
                <th>Tanggal Jatuh Tempo</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ $receivable->customer_name ?: '-' }}</td>
                <td>{{ $creditLimit }}</td>
                <td>{{ $claimAmount }}</td>
                <td>{{ $startPeriod }}</td>
                <td>{{ $endPeriod }}</td>
            </tr>
            </tbody>
        </table>

        <table class="detail-table" aria-label="Identitas rekening">
            <tr><td class="label">CIF</td><td>{{ $receivable->cif_no ?: ($receivable->cif_no_alt ?: '-') }}</td><td class="label">Cabang</td><td>{{ $receivable->branchOffice?->branch_name ?: $receivable->branch_code }}</td></tr>
            <tr><td class="label">Nomor Rekening</td><td>{{ $receivable->loan_account_number ?: '-' }}</td><td class="label">Alt Number</td><td>{{ $receivable->alt_number ?: '-' }}</td></tr>
            <tr><td class="label">Baki Debet</td><td>{{ $loanOutstanding }}</td><td class="label">Tanggal Meninggal</td><td>{{ $deathDate }}</td></tr>
        </table>

        @yield('claim-introduction')

        <p>Sebagai dasar pengajuan klaim, berikut dokumen yang dipersyaratkan:</p>
        <ol>
            @forelse ($attachments as $attachment)
                <li>{{ $attachment }}</li>
            @empty
                <li>-</li>
            @endforelse
        </ol>

        <p>Demikian kami sampaikan. Pembayaran klaim dapat ditransfer ke rekening MANDIRI nomor 167-001-811-9908 atas nama BPR DP TASPEN.</p>
        <p>Atas perhatian dan kerja samanya kami ucapkan terima kasih.</p>
    </div>

    <div class="signature">
        <p>Hormat kami,<br><span class="company">PT BPR DP TASPEN</span></p>
    </div>

    <footer class="footer">
        <div class="company">PT BPR DP TASPEN</div>
        <div>Jl. Raya Pondok Gede No.9 - Pondok Gede Bekasi 17413, Telp (021) 8467944 - 84971636, Fax (021) 8487577</div>
        <div>www.bankdptaspen.co.id | bprtaspen@gmail.com</div>
    </footer>
</section>
</body>
</html>
