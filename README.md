# Evoke FIELDS

System własnych pól dla WordPressa zintegrowany z **Bricks Builder**: grupy pól (w tym repeatery z zagnieżdżeniem), strony ustawień, własne typy treści i taksonomie, tagi dynamiczne i pętle w Bricks, pola obliczeniowe, kolumny w panelu admina.

- **Wymagania:** WordPress 6.x, PHP 7.4+, Bricks Builder (dla tagów/pętli; pola działają też bez Bricks przez API PHP).
- **Instalacja:** Wtyczki → Dodaj nową → Wyślij (ZIP) → Włącz. Menu: **Evoke FIELDS**.
- Podgląd PDF pola „Plik" wymaga **Imagick z obsługą PDF** (Ghostscript) na serwerze.

## Szybki start

1. **Evoke FIELDS → Grupy pól → Nowa grupa** — ustaw klucz grupy, lokalizację (typ treści / taksonomia / użytkownik / media) i dodaj pola.
2. Wartości wpisujesz w metaboxie na ekranie edycji wpisu (termu, profilu, załącznika).
3. W Bricks wstawiasz dane przez tagi `{evk_field_klucz}` (picker ⚡ → grupy „EVK: …") albo pętle Query Loop („EVK: …").

## Grupy pól

- **Grupa pojedyncza** — każde pole to osobna meta (`klucz pola` = klucz mety).
- **Grupa-repeater** — cała grupa to powtarzalne wiersze; meta pod kluczem grupy (tablica wierszy).
- **Lokalizacje:** wpisy/CPT (wybrane typy treści), **termy** wybranych taksonomii, **profil użytkownika**, **media** (panel załącznika — tylko proste typy pól: tekst, liczba, select itd.).
- Aktywność grupy przełączasz na liście grup (publish/draft). Grupę można **duplikować** (akcja „Duplikuj" na liście).

## Typy pól

| Grupa | Typy |
|---|---|
| Tekstowe | Tekst, Tekst wielowierszowy, E-mail, Link (URL), Edytor WYSIWYG |
| Liczbowe | Liczba, Suwak (range), **Pole obliczeniowe (calc)** |
| Wybór | Lista rozwijana, Radio, Grupa przycisków, Image Select, Checkbox, Przełącznik (toggle) |
| Data/czas | Data, Czas, Data i godzina (zapis ISO; opcjonalny format wyświetlania) |
| Media | Obraz, Galeria (z kategoriami), **Plik** (dowolny typ z biblioteki mediów) |
| Relacje | Taksonomia, Relacja (posty), Użytkownik — z opcją **relacji dwukierunkowej** (`reverse_key`) |
| Inne | Kolor, Link/przycisk (URL + etykieta + cel), Repeater (pola powtarzalne) |
| Układ | Zakładka, Akordeon, Nagłówek, Opis (nie zapisują danych) |

Wspólne opcje pól: szerokość, placeholder, wymagane, prefiks/sufiks, instrukcja + tooltip, wartość domyślna, walidacja (min/max — **egzekwowane też serwerowo**, wzorzec regex, własny komunikat), **logika warunkowa** (pokaż/ukryj wg innych pól, reguły all/any), kolumna w panelu admina.

## Tagi dynamiczne (Bricks)

- Pola wpisu: `{evk_field_klucz}` · Pola stron ustawień: `{evk_opt_grupa_klucz}`
- Wariant po `__`: `{evk_field_klucz__prop}` — dostępne warianty zależą od typu pola:

| Typ pola | Warianty (`__prop`) |
|---|---|
| Obraz | `id`, `alt`, nazwa rozmiaru (`thumbnail`, `medium`, `large`, `full`, …) — domyślnie URL `full` |
| Plik | `id`, `filename`, `preview` (URL JPG podglądu PDF) — domyślnie URL pliku; w PHP też `path`, `title` |
| Select / Radio / Grupa przycisków / Image Select | `label` — domyślnie wartość |
| Taksonomia | `id`, `slug` — domyślnie nazwy termów |
| Galeria | `ids`, `count` — domyślnie URL pierwszego obrazu |
| Relacja | `ids`, `count`, `url` — domyślnie tytuły |
| Użytkownik | `ids`, `count`, `email`, `url`, `avatar` — domyślnie display name |
| Link/przycisk | `title`, `target`, `html` — domyślnie URL |
| Data / Czas / Data i godzina | `raw` (ISO), `timestamp` — domyślnie wg formatu wyświetlania |

- **Meta powiązanego obiektu:** `{evk_field_klucz__meta:inny_klucz}` (dla pól Użytkownik / Relacja / Taksonomia).
- W elemencie **Image** Bricks używaj wariantu `__id` (pełny srcset i lightbox); `__preview` pliku PDF w kontekście Image zwraca ID podglądu automatycznie.

## Pętle (Query Loop)

W Query Loop elementu Bricks: **„EVK: …"** (wiersze repeatera; wewnątrz pętli tagi subpól), **„EVK Opcje: …"** (repeater ze strony ustawień), **„EVK Galeria: …"** + **„EVK Galeria kategorie: …"** (galeria z filtrami), **„EVK Relacja: …"**, **„EVK Użytkownicy: …"**, **„EVK Termy (pole): …"**. Pętle zagnieżdżają się (repeater w repeaterze).

## Pole obliczeniowe (calc)

Pole tylko do odczytu; wynik liczy **serwer przy zapisie** (wartości z formularza dla calc są ignorowane) i zapisuje jako zwykłą metę — sortowanie w pętlach/kolumnach działa numerycznie.

- Formuła: `+ - * / ( )`, liczby, `{klucz}` (pole z tego samego poziomu; w wierszu repeatera — z tego wiersza).
- Agregaty po wierszach repeatera: `SUM / COUNT / AVG / MIN / MAX(repeater.subpole)`, `COUNT(repeater)` = liczba wierszy. Na poziomie głównym repeater może pochodzić z **innej grupy** tego samego wpisu.
- Kolejność stała: wiersze (od najgłębszych) → poziom główny. `SUM(pozycje.wartosc)` po wierszowym calc działa; **łańcuchy calc→calc na tym samym poziomie są zabronione** (dają 0). Brak głębokich ścieżek (`SUM(a.b.c)`) — użyj calc-mostka w wierszu pośrednim.
- Przecinek dziesiętny w danych jest normalizowany; opcja „miejsca dziesiętne" zaokrągla wynik.
- Po **zmianie formuły** istniejące wpisy przelicz w **Narzędzia → Przelicz pola obliczeniowe**; po programowych zmianach danych wołaj `evk_rep_recalc()`.

## Pole „Plik" i podgląd PDF

Pole przechowuje ID załącznika, domyślnie zwraca URL. Dla **PDF** wtyczka tworzy przy uploadzie osobny załącznik JPG (1. strona, 300 DPI, bestfit 1200×1200), ukryty w bibliotece mediów i kasowany razem z PDF-em; tag `__preview` zwraca jego URL. Dla starszych PDF-ów podgląd generuje się przy pierwszym użyciu.

## Strony ustawień

**Evoke FIELDS → Strony ustawień**: strona (menu własne lub podmenu) → zakładki → przypisane grupy pól. Wartości globalne (opcje witryny, `autoload=false`). Odczyt: tagi `{evk_opt_…}` albo `evk_rep_get_option()` / `evk_get_option_field()`.

## Kolumny w panelu admina

Przełącznik „Kolumna" przy polu grupy pojedynczej: etykieta, pozycja, **„Umożliw sortowanie"** (Liczba/Suwak/Calc sortują numerycznie; uwaga WP: przy sortowaniu po mecie wpisy **bez wartości** znikają z listy). Wyszukiwarka listy wpisów i użytkowników uwzględnia wartości kolumn EVK.

## Publiczne API PHP

```php
evk_get_field( string $klucz, int $post_id = 0, string $prop = '' ); // jak tag dynamiczny
evk_rows( string $klucz, int $post_id = 0 ): array;                  // wiersze repeatera
evk_get_option_field( string $grupa, string $klucz = '', $default = '' ); // strony ustawień
evk_rep_recalc( int $object_id, string $meta_type = 'post' );        // przelicz pola calc
```

## Narzędzia

**Eksport/Import** (JSON: grupy, CPT, taksonomie, strony ustawień, wartości opcji; merge po slugu/kluczu, opcja nadpisywania) · **Przelicz pola obliczeniowe** (hurtowo, ze wznowieniem po limicie czasu hostingu) · **Czyszczenie osieroconych kluczy**.

## CPT i taksonomie

**Evoke FIELDS → Typy treści / Taksonomie** — rejestracja własnych typów i taksonomii (z kolumną admina, REST, hierarchią). Permalinki odświeżają się automatycznie po zapisie definicji.

- **Metabox wyboru** per taksonomia: **Checkboxy** (WP, wielokrotny), **Lista rozwijana** lub **Radio** (pojedynczy wybór z opcją „— brak —"); zapis przypisuje prawdziwe termy (`wp_set_object_terms`).
- **Zależne metaboxy**: opcja „Filtruj opcje wg taksonomii" + klucz pola relacji (pole Taksonomia EVK na termach, term meta) — wybór termu nadrzędnego (np. Trenera) zawęża opcje zależnej taksonomii (np. Grupy); działa kaskadowo, termy bez relacji są zawsze widoczne.
- **Kolumna taksonomii** na liście wpisów jest **sortowalna** po nazwie termu; wpisy bez termu pozostają na liście (sortują się na początku/końcu).

## Deinstalacja

`uninstall.php` usuwa **konfigurację** wtyczki (grupy pól, definicje CPT/taksonomii, strony ustawień i ich wartości, transienty). **Nie usuwa** danych treści: meta wpisów/termów/użytkowników z wartościami pól i załączniki zostają.

## Wersjonowanie

SemVer; szczegóły każdej wersji w [CHANGELOG.md](CHANGELOG.md).
