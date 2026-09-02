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
- Opcje grupy: repeater, zwinięte wiersze, bezramkowy, ukryj tytuł grupy, **etykieta z lewej** (input wyrównany do wspólnej kolumny; szerokość kolumny w Narzędzia → Wygląd pól).
- Aktywność grupy przełączasz na liście grup (publish/draft). Grupę można **duplikować** (akcja „Duplikuj" na liście).

## Typy pól

| Grupa | Typy |
|---|---|
| Tekstowe | Tekst, Tekst wielowierszowy, E-mail, Link (URL), Edytor WYSIWYG |
| Liczbowe | Liczba, Suwak (range), **Pole obliczeniowe (calc)** |
| Wybór | Lista rozwijana, Radio, Grupa przycisków, Image Select, Checkbox, Przełącznik (toggle) |
| Data/czas | Data, Czas, Data i godzina (zapis ISO; opcjonalny format wyświetlania) |
| Media | Obraz (kafelek z podglądem, konfigurowalny rozmiar podglądu), Galeria (z kategoriami), **Plik** (dowolny typ z biblioteki mediów) |
| Relacje | Taksonomia, Relacja (posty), Użytkownik — z opcją **relacji dwukierunkowej** (`reverse_key`); Relacja i Użytkownik: pigułki+wyszukiwarka lub lista rozwijana; Użytkownik: awatary, opcja „domyślnie bieżący użytkownik" |
| Inne | Kolor, Link/przycisk (URL + etykieta + cel), Repeater (pola powtarzalne) |
| Układ | Zakładka, Akordeon, Koniec akordeonu, Nagłówek, Opis (nie zapisują danych) |

Wspólne opcje pól: szerokość, placeholder, wymagane, prefiks/sufiks, instrukcja + tooltip, wartość domyślna, walidacja (min/max — **egzekwowane też serwerowo**, wzorzec regex, własny komunikat), **logika warunkowa** (pokaż/ukryj wg innych pól, reguły all/any), kolumna w panelu admina, **pole wrażliwe** (ochrona przed wyciekiem — patrz „Ochrona danych").

## Ochrona danych (pola wrażliwe / typy chronione)

Dwa niezależne mechanizmy, do użycia osobno lub razem:

- **Pole wrażliwe** (flaga na polu EVK) — wartość renderuje się tylko dla **redakcji** (uprawnienie do edycji wpisu, w każdym kontekście) oraz na froncie **na stronie tego wpisu wejściem z kluczem** (`?key=…`). W pętlach/rankingach, na cudzych stronach i w tagach poza autoryzacją zwraca pustkę. Pola niewrażliwe działają normalnie — ranking pokazuje imię/punkty, a adres/telefon nie wyciekają.
- **Typ treści „Chroniony"** (opcja CPT) — pełna blokada: pojedynczy wpis daje 404 bez klucza/uprawnienia; poza REST, wyszukiwarką, archiwum, sitemapą i oEmbed; chronione typy znikają z frontowych pętli gości.
- **Klucz dostępu** (`_evk_access_key`, per-wpis) autoryzuje odsłonięcie danych **tego jednego wpisu**. Metabox „Dostęp (klucz)": kopiuj link, przegeneruj (unieważnia stare), wyślij na e-mail (adres ręcznie lub z pola EVK). Traktuj link jak hasło.
- **Granice** (żadna wtyczka ich nie domknie): kod motywu z jawnym `get_post_meta($id,…)` / `evk_rows()`, bezpośredni URL załącznika w polu wrażliwym, cache pełnostronicowy (wymaga wykluczenia chronionych URL-i z cache).

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
- Po **zmianie formuły** istniejące wartości przelicz w **Narzędzia → Przelicz pola obliczeniowe** (zakres: typ treści / strony ustawień / termy / użytkownicy); po programowych zmianach danych wołaj `evk_rep_recalc()` (obiekty) lub `evk_rep_recalc_options()` (opcje).

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
evk_rep_recalc( int $object_id, string $meta_type = 'post' );        // przelicz pola calc obiektu
evk_rep_recalc_options(): int;                                       // przelicz pola calc stron ustawień
```

## Import / Eksport CSV

**Evoke FIELDS → Import / Eksport CSV**. **Import**: upload → mapowanie kolumn (rdzeń wpisu, taksonomie po nazwie, proste pola EVK) → tworzenie/aktualizacja z kluczem dopasowania; przetwarzanie porcjami przez AJAX (pasek postępu), po imporcie przeliczają się pola calc i uzupełnia „tytuł z pola". **Eksport**: wybór typu treści, kolumn, zakresu, separatora/kodowania (+ BOM dla Excela); plik streamowany. Nagłówki eksportu są zgodne z auto-mapowaniem importu — pełny round-trip (eksport → arkusz → import). **Eksport wartości stron opcji**: osobna sekcja — wybierasz grupę pól przypisaną do strony ustawień; grupa-repeater eksportuje się jako tabela (wiersz = wiersz repeatera), grupa pojedyncza jako pary „Pole / Wartość" (proste typy pól).

## Narzędzia

**Eksport/Import** (JSON: grupy — z lokalizacją, CPT, taksonomie, strony ustawień, wartości opcji; merge po slugu/kluczu, opcja nadpisywania, **import selektywny** wg sekcji) · **Kopie zapasowe konfiguracji** (auto-zrzut przy każdej zmianie struktury do chronionego katalogu w `uploads`, rotacja 30 kopii; ręczna kopia, przywracanie, pobieranie) · **Przelicz pola obliczeniowe** (hurtowo — wpisy / strony ustawień / termy / użytkownicy, ze wznowieniem po limicie czasu hostingu) · **Wygląd pól** (tokeny stylów: wielkość/odstęp etykiety, szerokość etykiety z lewej, odstęp nagłówka, padding pola) · **Czyszczenie osieroconych kluczy**.

## Import CSV

**Evoke FIELDS → Import CSV** — import do typów treści z mapowaniem kolumn: rdzeń wpisu (tytuł, treść, zajawka, status, slug, data, kolejność), taksonomie (po nazwie/slug, z opcją tworzenia brakujących termów) oraz **proste pola EVK** grup pojedynczych. Wybór separatora i kodowania (UTF-8 / Windows-1250 / ISO-8859-2), auto-dopasowanie kolumn po nazwie, podgląd wartości. Tryb **twórz i aktualizuj** z kluczem dopasowania (ID / slug / tytuł / pole EVK). Duże pliki przetwarzane porcjami ze wznowieniem; pola calc przeliczane po imporcie. Puste komórki domyślnie nie nadpisują istniejących wartości. Zakres: proste typy pól (bez repeaterów, relacji po nazwie i wgrywania obrazów z URL — planowane).

## CPT i taksonomie

**Evoke FIELDS → Typy treści / Taksonomie** — rejestracja własnych typów i taksonomii (z kolumną admina, REST, hierarchią). Permalinki odświeżają się automatycznie po zapisie definicji.

- **Tytuł wpisu z pola** (Typy treści → „Tytuł wpisu z pola"): klucz pola EVK **lub szablon** z wielu pól (np. `{imie} {nazwisko}`) — wartość staje się tytułem wpisu przy zapisie (lista, wyszukiwarka, relacje) i uzupełnia się także podczas importu CSV. Do tego przełączniki per CPT: **„Ukryj pole tytułu na ekranie edycji"** (tytuł zostaje w bazie), **„Ukryj kolumnę tytułu na liście"** (pierwsza kolumna EVK przejmuje link edycji i akcje wiersza; wymaga pola z opcją „Kolumna") i **„Losowa nazwa skrócona"** (nowe wpisy dostają unikalny losowy slug 6 znaków; istniejące permalinki nieruszane, ręczna zmiana respektowana).

- **Metabox wyboru** per taksonomia: **Checkboxy** (WP, wielokrotny), **Lista rozwijana** lub **Radio** (pojedynczy wybór z opcją „— brak —"); zapis przypisuje prawdziwe termy (`wp_set_object_terms`).
- **Zależne metaboxy**: opcja „Filtruj opcje wg taksonomii" + klucz pola relacji (pole Taksonomia EVK na termach, term meta) — wybór termu nadrzędnego (np. Trenera) zawęża opcje zależnej taksonomii (np. Grupy); działa kaskadowo, termy bez relacji są zawsze widoczne.
- **Kolumna taksonomii** na liście wpisów jest **sortowalna** po nazwie termu; wpisy bez termu pozostają na liście (sortują się na początku/końcu).

## Dostęp i uprawnienia

Panel wtyczki (Grupy pól, Typy treści, Taksonomie, Strony ustawień, Narzędzia, Import/Eksport CSV) otwiera **administrator** (`manage_options`) **albo** posiadacz uprawnienia **`evk_access_fields`** — nadawanego przez **Role Managera w Evoke ONE**. Jedna bramka `evk_rep_can_manage()` obowiązuje wszędzie: w menu, w handlerach zapisu i w punktach AJAX (samo odsłonięcie ekranu bez tego pękłoby przy pierwszym „Zapisz").

- **Evoke ONE nie jest wymagane.** Gdy go nie ma, nikt nie ma `evk_access_fields` w bazie — administrator i tak wchodzi, bo FIELDS dynamicznie (filtr `user_has_cap`, bez zapisu do ról) traktuje `manage_options` jako to uprawnienie.
- **Grupy pól** to CPT `evk_field_group` z **własnym zestawem uprawnień** (`edit_evk_field_groups` itd.), a nie uprawnieniami wpisów. Dzięki temu dostęp do definicji pól nadaje się **bez** rozdawania praw do edycji treści; te capy dostaje automatycznie każdy, kto ma `manage_options` lub `evk_access_fields`.
- `evk_access_fields` **nie daje** `manage_options` ani `edit_posts` — nie jest furtką do reszty witryny.
- **Nietknięte celowo:** strony ustawień mają własne, konfigurowalne uprawnienie (pole „Uprawnienie", domyślnie `manage_options`); ochrona danych (pola wrażliwe / typy chronione) dalej chodzi po `edit_post` danego wpisu; wyszukiwarka użytkowników w polu „Użytkownik" nadal wymaga `list_users` (zwraca adresy e-mail).

## Deinstalacja

`uninstall.php` usuwa **konfigurację** wtyczki (grupy pól, definicje CPT/taksonomii, strony ustawień i ich wartości, transienty). **Nie usuwa** danych treści: meta wpisów/termów/użytkowników z wartościami pól i załączniki zostają.

## Wersjonowanie

SemVer; szczegóły każdej wersji w [CHANGELOG.md](CHANGELOG.md).
