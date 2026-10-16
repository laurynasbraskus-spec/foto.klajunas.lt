# old.klajunas.lt: perėjimas prie .html

Visi old.klajunas.lt `*.htm` failai pervadinti į `*.html`, o visos nuorodos į juos pakeistos (be nukreipimų).
Patikrinta 2026-10-16: 283 puslapiai, 559 pakeistos nuorodos, 515 vidinių nuorodų – nė vienos neveikiančios.

## Failai

| Failas | Kas tai |
|---|---|
| `svetaine/` | Visa svetainės HTML dalis jau su `.html` pavadinimais ir pataisytomis nuorodomis (`archyvas/`, `old/`, `old/nariai/`, `lc/`, `files/temp/`, `archyvas.html`, `archyvas-by-year.csv`). Paveikslėliai ir kiti failai nesikeičia – jų kelti nereikia. |
| `archyvas.html` | Archyvo puslapis (su 2017–2026 eilutėmis, VU Rektoriaus taurės lentele, visos nuorodos į `.html`). Tas pats kaip `svetaine/archyvas.html`. |
| `archyvas-by-year.csv` | Archyvo CSV su `.html` nuorodomis. Tas pats kaip `svetaine/archyvas-by-year.csv`. |
| `vu-rektoriaus-taure.html` | Tik VU Rektoriaus taurės lentelė (įdėti į `archyvas-update.php` šabloną). |
| `pervadinimai.csv` | Kas pervadinta ir kurios nuorodos kuriuose failuose pakeistos. |
| `klajunas-lt-nuorodos.csv` | klajunas.lt (WordPress) įrašai ir puslapiai su nuorodomis į senus `.htm` – kuriuos keisti. |

## Kaip pritaikyti serveryje

**Saugiausias būdas – paleisti įrankį ant pilnos FTP kopijos** (jis apims ir failus, kurių iš išorės nesimato, pvz. `archyvas-update.php`):

1. Per FTP parsisiųsti visą `old.klajunas.lt` svetainę į aplanką, pvz. `D:\old_klajunas`.
2. Paleisti:
   ```
   py tools\htm_to_html.py "D:\old_klajunas" "D:\old_klajunas_html" --report "D:\pervadinimai.csv"
   ```
3. Peržiūrėti ekrane išvardintas „nerastas“ nuorodas.
4. Serveryje ištrinti visus `*.htm` failus ir įkelti `D:\old_klajunas_html` turinį.

Įrankis pakeičia ir PHP failus – pvz. `archyvas-update.php` eilutę, kurioje rašoma į `archyvas.htm`, į `archyvas.html`. Po to vis tiek reikia į to skripto šabloną įdėti VU Rektoriaus taurės lentelę (`vu-rektoriaus-taure.html`), kitaip ji vėl dings po kito CSV įkėlimo.

**Arba** įkelti jau paruoštą `svetaine/` turinį ir serveryje ištrinti senus `*.htm` (sąrašas – `pervadinimai.csv`, tipas „pervadinta“ ir „istrinti“), o `archyvas-update.php` pataisyti ranka.

## klajunas.lt (WordPress)

`klajunas-lt-nuorodos.csv`:
- **keisti** (108) – nuorodos į old.klajunas.lt / klajunas.lt `.htm` puslapius, stulpelyje `nauja_nuoroda` – nauja.
- **wp-media** (5) – `.htm` failai pačiame WordPress (`wp-content/uploads/2019/…`, Molėtų taurės 2019 lentelės). Juos reikėtų iš naujo įkelti kaip `.html` (jei WordPress leidžia) ir pakeisti nuorodas.
- **svetimas** (8) – kitų svetainių `.htm` (tengris.lt, aleliunas.lt ir kt.) – jų pervadinti negalima.

Visas 108 nuorodas galima pakeisti vienu kartu įskiepiu „Search Regex“ (arba „Better Search Replace Pro“), lentelėje `wp_posts`, stulpelyje `post_content`:

Paeiliui atlikti 3 pakeitimus (įjungti „Regex“):

| # | Ieškoti (regex) | Keisti į |
|---|---|---|
| 1 | `https?://(?:www\.)?(?:old\.)?klajunas\.(?:lt\|com)/((?:archyvas\|old\|lc\|files/temp)/[^"'\s]+?)\.htm(?=["'\s#?])` | `https://old.klajunas.lt/$1.html` |
| 2 | `https?://(?:www\.)?old\.klajunas\.lt/archyvas\.htm(?=["'\s#?])` | `https://old.klajunas.lt/archyvas.html` |
| 3 | `(["'])/((?:archyvas\|old\|lc\|files/temp)/[^"'\s]+?)\.htm(?=["'\s#?])` | `$1https://old.klajunas.lt/$2.html` |

Šie šablonai patikrinti su visomis 121 rastomis nuorodomis: visos 108 „keisti“ pakeičiamos teisingai, kitos (wp-media, svetimos) nepaliečiamos. Prieš keičiant – padaryti duomenų bazės atsarginę kopiją.

`wp-content/uploads` nuorodos šiais šablonais nekeičiamos.

Taip pat ranka pakeisti **meniu** (Išvaizda → Meniu) punktą „Varžybų archyvas“: `https://old.klajunas.lt/archyvas.html`.
