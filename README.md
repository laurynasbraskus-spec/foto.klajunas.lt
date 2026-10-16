# foto.klajunas.lt
OK Klajūnas albumų galerija

## Archyvo ištraukimas iš Gmail Takeout

`tools/takeout_extract.py` perskaito Google Takeout kopiją (paštą `.mbox` ir nuotraukas) ir atrenka varžybų medžiagą:
nuostatus, rezultatus, protokolus, prizininkus, datas, vietas, priedus ir nuotraukas.

```
py tools\takeout_extract.py "D:\GMAIL kopija info.klajunas.lt\Takeout" "D:\klajunas_archyvas"
```

Rezultatas: `summary.md` (apžvalga pagal metus), `emails.csv` / `emails.jsonl`, `attachments/<metai>/...`, `photos.csv`.
Reikia tik Python 3.8+ (be papildomų paketų).
