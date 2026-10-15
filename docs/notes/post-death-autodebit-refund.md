# Refund angsuran setelah debitur MD

Kewajiban debitur gugur pada tanggal meninggal dunia (MD). Setiap pembayaran loan yang tercatat
pada atau setelah tanggal MD (`payment_date >= date_of_death`, apa pun channel-nya) dikembalikan
ke rekening tabungan debitur saat approval Accounting Validation.

- Sumber data: `repayment_history` dari TRS contractual API (data DWH), respons yang sama dengan
  pengambilan Contract Outstanding.
- Transaksi: GL-to-GL `type G2` per journal, `trxType` = LSA sesuai produk loan,
  `destAccount` = rekening tabungan asli debitur (sebelum diganti ke OPER).
- Repayment angsuran dari rekening OPER oleh sistem ini dikecualikan (dicocokkan lewat `journalId`).
- Ditolak core → approval AV gagal, approve ulang = retry dengan reference baru.
  Status unknown / ada reversal → AV tertahan sampai direkonsiliasi manual.

## Poin untuk presentasi ke user

1. **Nominal Klaim tidak ikut berubah.** Nominal Klaim = outstanding kontraktual TRS per tanggal
   AV, bukan per tanggal MD. Angsuran setelah MD yang di-refund ditanggung bank lewat GL LSA dan
   tidak ditagihkan ke asuransi.
2. **Autodebet dari rekening OPER yang bukan berasal dari sistem ini dianggap tidak mungkin
   terjadi** (rekening OPER tidak bersaldo untuk dipotong autodebet). Jika ternyata terjadi,
   pembayaran itu akan ikut di-refund ke tabungan debitur.
