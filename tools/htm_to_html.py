#!/usr/bin/env python3
"""old.klajunas.lt: *.htm -> *.html pervadinimas ir nuorodų suderinimas.

Paima svetainės kopiją (pvz. parsisiųstą per FTP) ir sukuria naują kopiją, kurioje:
  * visi *.htm failai pervadinti į *.html;
  * visose nuorodose (HTML, PHP, CSV, JS, CSS, TXT) į pervadintus failus .htm pakeista į .html –
    santykinėse, absoliučiose (/kelias) ir pilnose (https://old.klajunas.lt/..., klajunas.lt/archyvas/...);
  * http://klajunas.lt/... ir http://old.klajunas.lt/... nuorodos į šios svetainės failus
    suvienodinamos į https://old.klajunas.lt/...;
  * nukreipimų nedaroma.

Naudojimas:
    python3 htm_to_html.py SVETAINES_KOPIJA NAUJA_KOPIJA [--report ataskaita.csv]

Jei šalia X.htm jau yra X.html (pvz. archyvas.htm ir archyvas.html), paliekamas X.html,
o X.htm į naują kopiją nekopijuojamas – jį serveryje reikia ištrinti.
Failų turinys keičiamas baitų lygiu, todėl išlieka originali koduotė (utf-8 / windows-1257).
"""
import argparse
import csv
import os
import posixpath
import re
import shutil
import sys
from urllib.parse import unquote

TEXT_EXT = {'.html', '.htm', '.php', '.csv', '.js', '.css', '.txt', '.xml', '.shtml'}
SITE_HOSTS = {b'old.klajunas.lt', b'www.old.klajunas.lt'}
# klajunas.lt/archyvas/... ir pan. dabar nukreipia į old.klajunas.lt – laikome šios svetainės nuorodomis
ALIAS_HOSTS = {b'klajunas.lt', b'www.klajunas.lt', b'klajunas.com', b'www.klajunas.com'}
CANON = b'https://old.klajunas.lt'

# nuoroda, kurios kelias baigiasi .htm (po to – ne raidė/skaičius, t.y. ne .html)
REF = re.compile(rb'''(?P<url>(?:(?:https?:)?//(?P<host>[A-Za-z0-9.-]+))?(?P<path>[^\s"'<>()=,;]*?\.htm))(?![A-Za-z0-9_])''', re.I)


def site_files(root):
    for d, _, fs in os.walk(root):
        for f in fs:
            full = os.path.join(d, f)
            yield '/' + os.path.relpath(full, root).replace(os.sep, '/')


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('src')
    ap.add_argument('dst')
    ap.add_argument('--report', default=None)
    a = ap.parse_args()
    if os.path.exists(a.dst):
        sys.exit(f'{a.dst} jau yra – nurodyk naują aplanką')

    files = set(site_files(a.src))
    lower = {f.lower(): f for f in files}
    rename = {}       # /kelias/x.htm -> /kelias/x.html
    dropped = []      # .htm, kurių .html jau yra
    for f in sorted(files):
        if f.lower().endswith('.htm'):
            new = f[:-4] + '.html'
            if new.lower() in lower:
                dropped.append(f)
            rename[f] = new
    rename_l = {k.lower(): v for k, v in rename.items()}

    report = []       # (failas, sena nuoroda, nauja nuoroda)
    unresolved = []   # nuorodos į .htm, kurių failo kopijoje nėra

    def fix(content, file_path):
        base = posixpath.dirname(file_path)

        def repl(m):
            url, host, path = m.group('url'), m.group('host'), m.group('path')
            h = host.lower() if host else None
            if h is not None and h not in SITE_HOSTS and h not in ALIAS_HOSTS:
                return url  # svetimas domenas
            p = unquote(path.decode('latin-1'))
            if h is None and not p.startswith('/'):
                target = posixpath.normpath(posixpath.join(base, p))
            else:
                target = posixpath.normpath(p if p.startswith('/') else '/' + p)
            if target.lower() not in rename_l:
                if h is None and '_files/' in target:
                    # Word/Excel „Išsaugoti kaip tinklalapį“ priedų nuorodos (pvz. *_files/header.htm),
                    # kurių failų serveryje nėra – tik suvienodinama galūnė
                    report.append((file_path, url.decode('latin-1'), (url[:-4] + b'.html').decode('latin-1')))
                    return url[:-4] + b'.html'
                unresolved.append((file_path, url.decode('latin-1')))
                return url
            if h is None:
                new = url[:-4] + b'.html'                  # santykinė / absoliuti – tik galūnė
            else:
                new = CANON + rename_l[target.lower()].encode('latin-1')
            report.append((file_path, url.decode('latin-1'), new.decode('latin-1')))
            return new

        return REF.sub(repl, content)

    for f in sorted(files):
        if f in dropped:
            continue
        out_rel = rename.get(f, f)
        src = os.path.join(a.src, f.lstrip('/'))
        dst = os.path.join(a.dst, out_rel.lstrip('/'))
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        if os.path.splitext(f)[1].lower() in TEXT_EXT:
            with open(src, 'rb') as fh:
                data = fh.read()
            with open(dst, 'wb') as fh:
                fh.write(fix(data, f))
        else:
            shutil.copy2(src, dst)

    print(f'Pervadinta: {len(rename) - len(dropped)} failų (.htm -> .html)')
    print(f'Pakeista nuorodų: {len(report)}')
    if dropped:
        print('Nekopijuota (jau yra .html, serveryje ištrinti .htm):')
        for f in dropped:
            print('  ', f)
    if unresolved:
        print(f'Nuorodos į .htm, kurių failo kopijoje nėra ({len(unresolved)}) – nepakeistos:')
        for f, u in sorted(set(unresolved)):
            print(f'   {f}: {u}')
    if a.report:
        with open(a.report, 'w', newline='', encoding='utf-8') as fh:
            w = csv.writer(fh)
            w.writerow(['tipas', 'failas', 'buvo', 'tapo'])
            for old, new in sorted(rename.items()):
                w.writerow(['pervadinta' if old not in dropped else 'istrinti', old, old, '' if old in dropped else new])
            for f, old, new in report:
                w.writerow(['nuoroda', f, old, new])
            for f, u in sorted(set(unresolved)):
                w.writerow(['nerasta', f, u, ''])


if __name__ == '__main__':
    main()
