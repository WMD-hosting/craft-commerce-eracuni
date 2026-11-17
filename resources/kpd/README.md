# KPD 2025 list

`kpd-2025.json` is a byte-for-byte copy of `resources/kpd/kpd-2025.json` in WMD's Business Suite
(`WMD-hosting/craft-suite`, taken at commit `948478b`), where `scripts/build-kpd.php` builds it from:

- the KPD 2025 list of the Državni zavod za statistiku (KLASUS, published on data.gov.hr under the
  Otvorena dozvola; the file header carries the attribution and the download URL), and
- the amendments in that repository's `resources/kpd/amendments/`, so far NN 119/26, in force from
  1 January 2027, which ends 17 six-digit codes, starts 12 and renames 72.

Do not edit it by hand. When the Suite's build changes (a new amendment), copy the file over again,
keep this note's commit current, and run the tests: `tests/core/KpdCatalogTest.php` pins the header
and the number of codes.

Format: `sections` maps a two-digit division to its section letter. `items` maps a code of level 4
to 6 (`62.10`, `62.10.1`, `62.10.11`) to its title, or to `{t, from, to, titles}` where an amendment
starts, ends or renames it.
