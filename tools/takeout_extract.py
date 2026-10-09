#!/usr/bin/env python3
"""Google Takeout -> OK Klajūnas varžybų archyvas.

Perskaito Gmail Takeout (.mbox) ir Google Photos/Drive failus, atrenka laiškus
apie varžybas (nuostatai, informacija, rezultatai, protokolai, prizininkai,
nuotraukos) ir sudeda viską į tvarkingą aplanką, iš kurio galima pildyti
old.klajunas.lt, klajunas.lt ir foto.klajunas.lt.

Naudojimas (Windows):
    py takeout_extract.py "D:\\GMAIL kopija info.klajunas.lt\\Takeout" "D:\\klajunas_archyvas"

Papildomai:
    --all          išsaugoti visus laiškus, ne tik susijusius su varžybomis
    --min-score N  minimalus "varžybiškumo" balas (numatyta 3)

Rezultatas (išvesties aplanke):
    summary.md          apžvalga pagal metus: data, tema, kategorijos, minimos datos/vietos, priedai
    emails.csv          visų atrinktų laiškų sąrašas (atsidaro Excel)
    emails.jsonl        tas pats + laiško tekstas (iki 20 000 simbolių)
    attachments/<metai>/<data>_<tema>/...   laiškų priedai (PDF, XLS, DOC, nuotraukos ir kt.)
    photos.csv          Takeout nuotraukos už pašto ribų (Google Photos/Drive) su fotografavimo data
Tik standartinė Python 3.8+ biblioteka.
"""
import argparse
import csv
import email
import email.policy
import hashlib
import html
import json
import mailbox
import os
import re
import sys
import unicodedata
from datetime import datetime, timezone
from email.utils import getaddresses, parsedate_to_datetime

BODY_LIMIT = 20000

# Raktažodžiai be diakritikų, mažosiomis. Svoris -> kiek prideda prie balo.
KEYWORDS = {
    "varzyb": 3, "orientavimosi sport": 3, "orientacin": 3, "nuostat": 3,
    "rezultat": 3, "protokol": 3, "prizinink": 3, "nugaletoj": 3,
    "startinis": 2, "starto protokol": 3, "biuletenis": 3, "bulletin": 3,
    "trasa": 2, "trasos": 2, "kontrolini punkt": 2, "zemelap": 2, "map": 1,
    "taure": 2, "cempionat": 3, "estafet": 2, "naktinis": 2, "sprint": 2,
    "registracij": 1, "dalyvi": 1, "apdovanoj": 2, "medali": 2, "diplom": 1,
    "foto": 1, "nuotrauk": 2, "galerij": 2, "rogaining": 3, "orientiravan": 3,
    "o-cup": 2, "losf": 2, "sportident": 2, "emit": 1, "si kortel": 2,
    "orienteering": 3, "results": 2, "invitation": 2, "kvietim": 2,
    "klajun": 1, "zelvos": 2, "moletu taure": 3, "takas": 1,
}

CATEGORIES = {
    "nuostatai": ["nuostat", "bulletin", "biuletenis", "informacij", "invitation", "kvietim"],
    "rezultatai": ["rezultat", "results", "split", "tarpiniai laikai"],
    "protokolai": ["protokol", "startinis", "start list", "startlist"],
    "prizininkai": ["prizinink", "nugaletoj", "apdovanoj", "medali", "laimetoj", "1 vieta", "i vieta"],
    "nuotraukos": ["foto", "nuotrauk", "galerij", "photo", "album"],
    "trasos_zemelapiai": ["trasa", "trasos", "zemelap", "map", "ocad", "kontrolini punkt"],
    "registracija": ["registracij", "dalyvi", "entries", "paraisk"],
    "finansai": ["saskait", "apmokej", "invoice", "mokest", "paysera", "pervedim"],
}

LT_MONTHS = {
    "sausio": 1, "vasario": 2, "kovo": 3, "balandzio": 4, "geguzes": 5, "birzelio": 6,
    "liepos": 7, "rugpjucio": 8, "rugsejo": 9, "spalio": 10, "lapkricio": 11, "gruodzio": 12,
}

PLACE_PATTERNS = [
    r"(?:varzybu centras|varzybu vieta|vieta|vyks|vyksta|place|venue|location)\s*[:\-–]\s*([^\n]{3,120})",
    r"\b([A-ZĄČĘĖĮŠŲŪŽ][a-ząčęėįšųūž]+(?:\s+[A-ZĄČĘĖĮŠŲŪŽ][a-ząčęėįšųūž]+)?\s+(?:r\.|raj\.|seniunij\w*|apyl\w*|ezer\w*|mišk\w*|misk\w*))",
]

IMAGE_EXT = {".jpg", ".jpeg", ".png", ".gif", ".heic", ".tif", ".tiff", ".webp", ".bmp"}


def fold(s):
    """Mažosios raidės be diakritikų (paieškai)."""
    s = unicodedata.normalize("NFKD", s or "")
    return "".join(c for c in s if not unicodedata.combining(c)).lower()


def slug(s, n=60):
    s = fold(s)
    s = re.sub(r"^(re|fw|fwd|atsk|psl)\s*:\s*", "", s.strip())
    s = re.sub(r"[^a-z0-9]+", "-", s).strip("-")
    return (s[:n].strip("-")) or "be-temos"


def safe_name(name):
    name = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", name or "").strip(" .")
    return name[:150] or "priedas"


def html_to_text(h):
    h = re.sub(r"(?is)<(script|style).*?</\1>", " ", h)
    h = re.sub(r"(?i)<br\s*/?>|</p>|</div>|</tr>|</li>|</h\d>", "\n", h)
    h = re.sub(r"(?i)</td>|</th>", "\t", h)
    h = re.sub(r"<[^>]+>", " ", h)
    h = html.unescape(h)
    h = re.sub(r"[ \t\xa0]+", " ", h)
    return re.sub(r"\n\s*\n+", "\n\n", h).strip()


def part_text(part):
    try:
        return part.get_content()
    except Exception:
        payload = part.get_payload(decode=True) or b""
        for enc in (part.get_content_charset(), "utf-8", "windows-1257", "latin-1"):
            if not enc:
                continue
            try:
                return payload.decode(enc)
            except (LookupError, UnicodeDecodeError):
                pass
        return ""


def body_and_attachments(msg):
    plain, htmls, atts = [], [], []
    for part in msg.walk():
        if part.is_multipart():
            continue
        ctype = part.get_content_type()
        fname = part.get_filename()
        disp = (part.get_content_disposition() or "").lower()
        if fname or disp == "attachment" or (ctype.startswith("image/") and disp != ""):
            data = part.get_payload(decode=True)
            if data:
                if not fname:
                    ext = "." + ctype.split("/")[-1]
                    fname = "priedas" + ext
                atts.append((fname, ctype, data))
            continue
        if ctype == "text/plain":
            plain.append(part_text(part))
        elif ctype == "text/html":
            htmls.append(html_to_text(part_text(part)))
    text = "\n".join(plain).strip() or "\n".join(htmls).strip()
    return text, atts


def header(msg, name):
    try:
        v = msg.get(name)
        return str(v) if v is not None else ""
    except Exception:
        return ""


def score_and_categories(subject, text, att_names):
    hay = fold(subject) * 3 + " " + fold(text[:15000]) + " " + fold(" ".join(att_names)) * 2
    score = sum(w for k, w in KEYWORDS.items() if k in hay)
    cats = sorted(c for c, keys in CATEGORIES.items() if any(k in hay for k in keys))
    return score, cats


def find_dates(text):
    found = set()
    t = fold(text[:15000])
    for y, m, d in re.findall(r"\b(19[89]\d|20[0-4]\d)[-./ ](\d{1,2})[-./ ](\d{1,2})\b", t):
        found.add((int(y), int(m), int(d)))
    for d, m, y in re.findall(r"\b(\d{1,2})\.(\d{1,2})\.(19[89]\d|20[0-4]\d)\b", t):
        found.add((int(y), int(m), int(d)))
    month_re = "|".join(LT_MONTHS)
    for y, mon, d in re.findall(r"\b(19[89]\d|20[0-4]\d)\s*m\.?\s*(" + month_re + r")\s*(\d{1,2})", t):
        found.add((int(y), LT_MONTHS[mon], int(d)))
    out = []
    for y, m, d in found:
        try:
            out.append(datetime(y, m, d).strftime("%Y-%m-%d"))
        except ValueError:
            pass
    return sorted(out)[:15]


def fold_1to1(s):
    """Kaip fold(), bet kiekvienam simboliui lieka lygiai vienas (pozicijos sutampa su originalu)."""
    return "".join((fold(c) or c)[:1] for c in s)


def find_places(text):
    places = []
    src = text[:15000]
    folded = fold_1to1(src)
    for m in re.finditer(PLACE_PATTERNS[0], folded, re.IGNORECASE):
        p = re.sub(r"\s+", " ", src[m.start(1):m.end(1)]).strip(" .,;")
        if p and p not in places:
            places.append(p)
    for m in re.findall(PLACE_PATTERNS[1], src):
        p = re.sub(r"\s+", " ", m).strip(" .,;")
        if p and p not in places:
            places.append(p)
    return places[:10]


def msg_date(msg):
    try:
        dt = parsedate_to_datetime(header(msg, "Date"))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt
    except Exception:
        return None


def iter_mbox_files(root):
    for dirpath, _, files in os.walk(root):
        for f in files:
            if f.lower().endswith(".mbox"):
                yield os.path.join(dirpath, f)


def process_mail(takeout, out, min_score, keep_all, seen_ids, seen_hashes, rows):
    for path in iter_mbox_files(takeout):
        print(f"[pastas] {path}", file=sys.stderr)
        box = mailbox.mbox(path, factory=lambda f: email.message_from_binary_file(f, policy=email.policy.default),
                           create=False)
        for i, msg in enumerate(box):
            if i and i % 1000 == 0:
                print(f"  ... {i} laiškų, atrinkta {len(rows)}", file=sys.stderr)
            if msg is None:
                continue
            try:
                mid = header(msg, "Message-ID") or f"{path}#{i}"
                if mid in seen_ids:
                    continue
                seen_ids.add(mid)
                subject = header(msg, "Subject")
                text, atts = body_and_attachments(msg)
                att_names = [a[0] for a in atts]
                score, cats = score_and_categories(subject, text, att_names)
                if not keep_all and score < min_score:
                    continue
                dt = msg_date(msg)
                day = dt.strftime("%Y-%m-%d") if dt else "0000-00-00"
                year = day[:4]
                folder = os.path.join("attachments", year, f"{day}_{slug(subject)}")
                saved = []
                for fname, ctype, data in atts:
                    h = hashlib.sha1(data).hexdigest()
                    if h in seen_hashes:
                        saved.append(seen_hashes[h])
                        continue
                    rel = os.path.join(folder, safe_name(fname))
                    base, ext = os.path.splitext(rel)
                    n = 1
                    while os.path.exists(os.path.join(out, rel)):
                        rel = f"{base}_{n}{ext}"
                        n += 1
                    os.makedirs(os.path.join(out, folder), exist_ok=True)
                    with open(os.path.join(out, rel), "wb") as fh:
                        fh.write(data)
                    seen_hashes[h] = rel
                    saved.append(rel)
                rows.append({
                    "date": dt.isoformat() if dt else "",
                    "day": day,
                    "from": header(msg, "From"),
                    "to": ", ".join(a for _, a in getaddresses([header(msg, "To")]) if a),
                    "subject": subject,
                    "score": score,
                    "categories": ";".join(cats),
                    "dates_mentioned": ";".join(find_dates(subject + "\n" + text)),
                    "places_mentioned": ";".join(find_places(text)),
                    "labels": header(msg, "X-Gmail-Labels"),
                    "attachments": ";".join(saved),
                    "mbox": os.path.basename(path),
                    "body": text[:BODY_LIMIT],
                })
            except Exception as e:  # vienas sugadintas laiškas neturi sustabdyti viso darbo
                print(f"  ! laiškas #{i}: {e}", file=sys.stderr)


def process_photos(takeout, out):
    rows = []
    for dirpath, _, files in os.walk(takeout):
        for f in files:
            ext = os.path.splitext(f)[1].lower()
            if ext not in IMAGE_EXT:
                continue
            p = os.path.join(dirpath, f)
            taken, title, desc, geo = "", "", "", ""
            for side in (p + ".json", p + ".supplemental-metadata.json", os.path.splitext(p)[0] + ".json"):
                if os.path.exists(side):
                    try:
                        with open(side, encoding="utf-8") as fh:
                            meta = json.load(fh)
                        ts = (meta.get("photoTakenTime") or meta.get("creationTime") or {}).get("timestamp")
                        if ts:
                            taken = datetime.fromtimestamp(int(ts), timezone.utc).strftime("%Y-%m-%d %H:%M")
                        title = meta.get("title", "")
                        desc = meta.get("description", "")
                        g = meta.get("geoData") or {}
                        if g.get("latitude"):
                            geo = f"{g['latitude']},{g['longitude']}"
                    except Exception:
                        pass
                    break
            if not taken:
                taken = datetime.fromtimestamp(os.path.getmtime(p)).strftime("%Y-%m-%d %H:%M") + " (failo data)"
            rows.append({
                "path": os.path.relpath(p, takeout),
                "album": os.path.basename(dirpath),
                "taken": taken,
                "title": title,
                "description": desc,
                "geo": geo,
                "size_kb": os.path.getsize(p) // 1024,
            })
    rows.sort(key=lambda r: (r["taken"], r["path"]))
    if rows:
        with open(os.path.join(out, "photos.csv"), "w", newline="", encoding="utf-8-sig") as fh:
            w = csv.DictWriter(fh, fieldnames=list(rows[0]))
            w.writeheader()
            w.writerows(rows)
    return rows


def write_outputs(out, rows, photos):
    rows.sort(key=lambda r: r["date"])
    fields = [k for k in rows[0] if k != "body"] if rows else []
    with open(os.path.join(out, "emails.csv"), "w", newline="", encoding="utf-8-sig") as fh:
        if rows:
            w = csv.DictWriter(fh, fieldnames=fields, extrasaction="ignore")
            w.writeheader()
            w.writerows(rows)
    with open(os.path.join(out, "emails.jsonl"), "w", encoding="utf-8") as fh:
        for r in rows:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")

    lines = ["# OK Klajūnas – archyvas iš Gmail Takeout", "",
             f"Atrinkta laiškų: {len(rows)}; nuotraukų už pašto ribų: {len(photos)}", ""]
    by_year = {}
    for r in rows:
        by_year.setdefault(r["day"][:4], []).append(r)
    for year in sorted(by_year):
        lines += [f"## {year}", ""]
        for r in by_year[year]:
            lines.append(f"- **{r['day']}** — {r['subject'] or '(be temos)'}  ")
            lines.append(f"  _{r['from']}_ · {r['categories'] or '-'} · balas {r['score']}  ")
            if r["dates_mentioned"]:
                lines.append(f"  Minimos datos: {r['dates_mentioned'].replace(';', ', ')}  ")
            if r["places_mentioned"]:
                lines.append(f"  Vietos: {r['places_mentioned'].replace(';', '; ')}  ")
            if r["attachments"]:
                for a in r["attachments"].split(";"):
                    lines.append(f"  - [{os.path.basename(a)}]({a.replace(os.sep, '/')})")
        lines.append("")
    with open(os.path.join(out, "summary.md"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("takeout", help="Takeout aplankas (pvz. D:\\GMAIL kopija info.klajunas.lt\\Takeout)")
    ap.add_argument("out", help="Kur sudėti rezultatus")
    ap.add_argument("--all", action="store_true", help="Išsaugoti visus laiškus")
    ap.add_argument("--min-score", type=int, default=3)
    a = ap.parse_args()
    if not os.path.isdir(a.takeout):
        sys.exit(f"Nerastas aplankas: {a.takeout}")
    os.makedirs(a.out, exist_ok=True)
    rows = []
    process_mail(a.takeout, a.out, a.min_score, a.all, set(), {}, rows)
    photos = process_photos(a.takeout, a.out)
    write_outputs(a.out, rows, photos)
    print(f"Baigta: {len(rows)} laiškų, {len(photos)} nuotraukų -> {a.out}", file=sys.stderr)


if __name__ == "__main__":
    main()
