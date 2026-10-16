# old.klajunas.lt archyvo atnaujinimas (2017–2026)

`archyvas-by-year.csv` – serverio failo https://old.klajunas.lt/archyvas-by-year.csv kopija (eilutės 1989–2016 nepakeistos) **su naujomis eilutėmis 2017–2026**.

Kaip įkelti:
1. Atidaryti https://old.klajunas.lt/archyvas-update.php
2. Įvesti slaptažodį, pasirinkti šį `archyvas-by-year.csv` ir spausti „Sukelti iš CSV failo“ (senas `archyvas.htm` automatiškai išsaugomas `/archyvas-backups/`).

Kas pridėta:
- **2017–2018** – perkelta iš dabartinio `archyvas.htm` (ten jau buvo, bet CSV šaltinyje trūko – kitaip įkėlus CSV jos dingtų) ir papildyta: Klajūno taurė 2018, Molėtų taurės finalai 2018.
- **2019–2026** – rezultatų nuorodos iš klajunas.lt įrašų ir dbsportas.lt (Želvos bėgimas, Snaigė / OS „Žiema“, Kopija, Moksleivių žaidynės, Klajūno taurė, Molėtų taurės finalai ir galutiniai rezultatai 2019, Sprintas 2019, Sezono uždarymas).
- Rasti, bet archyve nenurodyti puslapiai: `archyvas/snaige19.htm`, `kopija19.htm`, `mokslzaid19.htm`, `uzdarymas24.htm`.
- Visos 40 naujų nuorodų patikrintos (HTTP 200) 2026-10-13.

Tuščia langelis = rezultatų nerasta (žr. `../rezultatai-2017-2026.md` skyrių „Spragos“). Ikonos: `T.png` – dbtopas/dbsportas, `kp.jpg` – klajunas.lt puslapis.

## ⚠ VU Rektoriaus taurės lentelė

`archyvas-update.php` perrašo `archyvas.htm` pagal savo šabloną. Šablone yra Kantanto taurės lentelė, bet **nėra VU Rektoriaus taurės lentelės** (2015, 2017–2019, Žukaučizna) – ji buvo įrašyta į HTML rankiniu būdu, todėl po CSV įkėlimo (2026-10-16) dingo.

Atkūrimas:
- `archyvas.html` – dabartinis puslapis (su 2017–2026 eilutėmis) + grąžinta Rektoriaus lentelė po Kantanto lentele. Įkelti per FTP / hostingo failų tvarkyklę į `https://old.klajunas.lt/archyvas.html`.
- `archyvas.htm` – nukreipimas į `archyvas.html` (kaip `old.klajunas.lt` pradžios puslapyje), kad veiktų senos nuorodos (klajunas.lt meniu „Varžybų archyvas“ ir kt.). Įkelti vietoje esamo `archyvas.htm`.

⚠ `archyvas-update.php` rašo į `archyvas.htm`: kol skriptas nepakeistas, kitas CSV įkėlimas nukreipimą vėl pakeis pilnu puslapiu (be Rektoriaus lentelės), o `archyvas.html` liks senas. Skripte reikia pakeisti išvesties failą į `archyvas.html` ir į šabloną įdėti Rektoriaus lentelę.
- `vu-rektoriaus-taure.html` – tik pati lentelė, jei patogiau įklijuoti ranka.


