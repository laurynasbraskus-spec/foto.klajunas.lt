"""Istraukia datuotus renginius is klubo metiniu veiklos ataskaitu teksto.

Ataskaitose renginiai surasyti pavidalu:
    2024-01-13, 34-asis tradicinis begimas "Aplink Zelvos ezera"   157
    2024-08-25, Klajuno taure 2024 (orientavimosi sporto varzybos, Paalne)  190

PDF teksta laužo per eilutes, todel pavadinimas surenkamas is keliu eiluciu iki
kito datos zymens.

Nieko nekeicia - raso CSV.
"""
import csv
import pathlib
import re
import sys

folder = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else ".")
out_csv = pathlib.Path(sys.argv[2]) if len(sys.argv) > 2 else folder / "report_events.csv"

DATE = re.compile(r"(20\d{2})[-.](\d{1,2})[-.](\d{1,2})")
# dalyviu skaicius eilutes gale: " 157 347" arba " 190"
TRAIL_NUM = re.compile(r"\s+\d{1,4}(\s+\d{1,4})?\s*$")

rows = []
for txt in sorted(folder.glob("*.txt")):
    year_hint = re.search(r"(20\d{2})", txt.stem)
    raw = txt.read_text(encoding="utf-8")
    # susiaurinam tarpus, bet paliekam eilutes
    lines = [re.sub(r"[ \t]+", " ", ln).strip() for ln in raw.splitlines()]

    current = None
    for ln in lines:
        if not ln or ln.startswith("--- psl."):
            continue
        m = DATE.search(ln)
        if m and m.start() <= 4:
            if current:
                rows.append(current)
            y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
            rest = ln[m.end():].lstrip(" ,–-")
            current = {
                "Failas": txt.name,
                "Data": f"{y:04d}-{mo:02d}-{d:02d}",
                "Renginys": rest,
                "Dalyviai": "",
            }
            continue
        if current is not None:
            # tesinys: pavadinimas persikeles i kita eilute
            if DATE.search(ln):
                rows.append(current)
                current = None
                continue
            if len(ln) > 90 or ln.lower().startswith(("viso", "* ", "ii.", "i. ", "iii.")):
                rows.append(current)
                current = None
                continue
            current["Renginys"] = (current["Renginys"] + " " + ln).strip()
    if current:
        rows.append(current)

# atskiriam dalyviu skaiciu nuo pavadinimo galo
clean = []
seen = set()
for r in rows:
    name = r["Renginys"]
    m = TRAIL_NUM.search(name)
    if m:
        r["Dalyviai"] = m.group(0).strip()
        name = name[: m.start()]
    name = re.sub(r"\s+", " ", name).strip(" ,.;–-")
    if len(name) < 4:
        continue
    key = (r["Data"], name.lower()[:40])
    if key in seen:
        continue
    seen.add(key)
    r["Renginys"] = name
    clean.append(r)

if not clean:
    print("KLAIDA: neistraukta ne vieno renginio - patikrink teksto formata.")
    sys.exit(1)

clean.sort(key=lambda r: r["Data"])
with out_csv.open("w", encoding="utf-8-sig", newline="") as fh:
    w = csv.DictWriter(fh, fieldnames=["Data", "Renginys", "Dalyviai", "Failas"])
    w.writeheader()
    for r in clean:
        w.writerow({k: r[k] for k in w.fieldnames})

print(f"Istraukta renginiu: {len(clean)}")
years = {}
for r in clean:
    years[r["Data"][:4]] = years.get(r["Data"][:4], 0) + 1
for y in sorted(years):
    print(f"  {y}: {years[y]}")
print(f"\nCSV: {out_csv}")
