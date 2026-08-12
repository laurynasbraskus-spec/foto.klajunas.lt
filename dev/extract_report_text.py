"""Istraukia teksta is klubo metiniu veiklos ataskaitu PDF.

Ataskaitose yra renginiu suvestines ir prizininkai - jos reikalingos tiems
albumams, kuriems klajunas.lt naujienose pranesimo nera.

Nieko nekeicia - tik raso .txt failus salia PDF.
"""
import sys
import pathlib

from pypdf import PdfReader

folder = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else ".")
pdfs = sorted(folder.glob("*.pdf"))
if not pdfs:
    print("KLAIDA: PDF failu nerasta:", folder)
    sys.exit(1)

total_chars = 0
for pdf in pdfs:
    try:
        reader = PdfReader(str(pdf))
    except Exception as exc:  # sugadintas ar uzsifruotas failas
        print(f"  {pdf.name}: NEPAVYKO ATIDARYTI - {exc}")
        continue

    parts = []
    for i, page in enumerate(reader.pages, 1):
        try:
            parts.append(f"\n--- psl. {i} ---\n" + (page.extract_text() or ""))
        except Exception as exc:
            parts.append(f"\n--- psl. {i} (klaida: {exc}) ---\n")

    text = "".join(parts)
    out = pdf.with_suffix(".txt")
    out.write_text(text, encoding="utf-8")
    total_chars += len(text)
    # Skenuotas PDF duotu beveik tuscia rezultata - tai reikia matyti is karto.
    flag = "  <- itariamai skenuotas, teksto beveik nera" if len(text) < 200 * len(reader.pages) else ""
    print(f"  {pdf.name}: {len(reader.pages)} psl., {len(text):,} simboliu{flag}")

print(f"\nIs viso istraukta: {total_chars:,} simboliu")
if total_chars == 0:
    print("KLAIDA: neistraukta nieko - PDF greiciausiai skenuoti, reikia OCR.")
    sys.exit(1)
