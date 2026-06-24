# Changelog — Evoke FIELDS

Format wg [Keep a Changelog](https://keepachangelog.com/), wersjonowanie [SemVer](https://semver.org/).

## [1.33.4] — 2026-06-25

### Zmienione

- **Cofnięto przełącznik „Seamless (bez tła)"** — usuwanie białego tła wokół pól nie podobało
  się na froncie. Białe (jasne) tło wraca; **nagłówek-karta „Definicja pól" zostaje na stałe**.
  (`includes/builder.php`, `assets/builder.css`, `assets/builder.js`)

## [1.33.3] — 2026-06-25

### Zmienione

- **Nagłówek-karta „Definicja pól" znów zawsze włączony** (bez przełącznika) — był OK na stałe.
- **Przełącznik w pasku przerobiony na „Seamless (bez tła)"** — po włączeniu usuwa białe tło
  wokół pól (`.evk-b-fields`); karta i nagłówek zostają. Stan pamiętany w `localStorage`,
  klasa `evk-gf-seamless`. (`includes/builder.php`, `assets/builder.css`)

## [1.33.2] — 2026-06-25

### Zmienione

- **Wygląd „seamless" metaboxa „Definicja pól" to teraz przełącznik** (a nie styl na stałe)
  — checkbox „Seamless nagłówek" w pasku narzędzi, zapamiętywany w `localStorage`. Włączony
  → zaokrąglona karta z oddzielonym, wyższym nagłówkiem (klasa `evk-gf-seamless`); wyłączony
  → standardowy metabox. (`includes/builder.php`, `assets/builder.js`, `assets/builder.css`)

## [1.33.1] — 2026-06-25

### Naprawione

- **Pola na stronach opcji nadal nie były pełnej szerokości** — `.evk-s-field`/kontrolki są
  gdzieś elementem flex, a `flex-shrink` ściskał `width:100%` poniżej 100%. Dodano
  `min-width:100%` (twarda podłoga) do select/text/textarea/data w `.evk-s-field`.
  (`assets/evk-admin.css`)
- **Nagłówek karty „Definicja pól"** — dodano warianty stylu dla starszego markupu WP
  (`.hndle` bezpośrednio w `.postbox`, bez `.postbox-header`), żeby nagłówek był ostylowany
  niezależnie od wersji WP. (`assets/builder.css`)

## [1.33.0] — 2026-06-25

### Dodane

- **„Pola zawsze zwinięte"** — przełącznik w pasku narzędzi „Definicji pól", zapamiętywany
  w `localStorage` (per przeglądarka). Gdy włączony, pola startują zwinięte przy każdym
  wczytaniu edytora grup. (`includes/builder.php`, `assets/builder.js`, `assets/builder.css`)

### Zmienione

- **Metabox „Definicja pól" jako zaokrąglona karta** — ramka + `border-radius` jak reszta
  kart EVK, nagłówek oddzielony (tło + linia) i nieco wyższy, mniejszy padding `.inside`.
  (`assets/builder.css`)

### Naprawione

- **Pola `<select>`/tekst na stronach opcji nie miały 100% szerokości** — twarda reguła
  `width:100%` dla bezpośrednich dzieci `.evk-s-field` w `evk-admin.css` (ładowany na każdym
  ekranie wtyczki, wyższa specyficzność). (`assets/evk-admin.css`)
- **`<select>` z tooltipem** — `flex:1 1 0` + `width:auto`, by szerokość wyznaczał wyłącznie
  flex-grow, nie najdłuższa opcja. (`assets/admin.css`)

## [1.32.3] — 2026-06-25

### Naprawione

- **Pola `<select>` z tooltipem nie miały 100% szerokości** — przy `flex:1 1 auto` natywny
  `<select>` przyjmuje szerokość najdłuższej opcji (quirk flexa), ignorując `width:100%`.
  Zmieniono na `flex:1 1 0`, więc kontrolka rośnie od zera i wypełnia wiersz niezależnie od
  treści. (`assets/admin.css`)

## [1.32.2] — 2026-06-25

### Zmienione

- Większy odstęp nad polem „Klucz pola odwrotnego" w konfiguracji relacji dwukierunkowej
  (8px → 18px) — oddziela je wyraźniej od przełącznika. (`includes/builder.php`)

## [1.32.1] — 2026-06-25

### Naprawione

- **Pola owinięte w `<div>` (Relacja, Użytkownik, Link, suwak) zwężały się** do ~180px, gdy
  miały tooltip — regresja po 1.31.1. Wrapper był „shrink-to-fit" w wierszu flex, więc input
  `width:100%` liczył się od skurczonego kontenera. Przywrócono domyślne wypełnianie
  (`flex:1`) z wyjątkiem kontrolek kompaktowych (toggle, kolor), które dalej nie rozciągają
  się. (`assets/admin.css`)

## [1.32.0] — 2026-06-25

### Dodane — relacje dwukieronkowe (Faza ACF/MB, partia B, generyczna)

- **Dwukierunkowość dla pól Relacja, Taksonomia i Użytkownik.** Na polu włączasz „Relacja
  dwukierunkowa" + podajesz **klucz pola odwrotnego** (ręcznie). Przy zapisie obiektu EVK
  automatycznie aktualizuje pole odwrotne na powiązanych obiektach:
  - Relacja → posty, **Taksonomia → termy** (np. judo: grupy↔trenerzy jako taksonomie),
    Użytkownik → użytkownicy. Meta drugiej strony wyznacza typ pola.
  - Dodanie powiązania dopisuje ID po drugiej stronie; usunięcie — usuwa. Działa dla grup
    pojedynczych na post/term/user (nie repeater, nie strony opcji).
- **Ochrona przed pętlą:** zapis strony odwrotnej idzie bezpośrednio przez `update_metadata`
  (nie odpala `save_post`/`edited_term`/`profile_update`) + statyczny flag.
- **Sprzątanie przy usuwaniu:** `before_delete_post` / `pre_delete_term` / `delete_user`
  usuwają ID kasowanego obiektu z pól odwrotnych powiązanych obiektów.
  (`includes/bidirectional.php` [nowy], `includes/metabox.php`, `includes/builder.php`,
  `evk-repeater.php`)

## [1.31.1] — 2026-06-25

### Naprawione

- **Tooltip „?" przy kontrolkach kompaktowych** (toggle, kolor, suwak) był wypychany na
  prawą krawędź z dużą luką, bo pierwszy element wiersza miał `flex:1 1 auto` i rozciągał
  się na pełną szerokość. Usunięto wymuszone rozciąganie — pola pełnoszerokościowe
  (width:100%) i tak wypełniają wiersz, a kompaktowe zostają przy swojej szerokości,
  więc „?" siedzi tuż obok pola. (`assets/admin.css`)

## [1.31.0] — 2026-06-25

### Dodane — pole „Użytkownik" (Faza ACF/MB, partia A)

- **Nowy typ pola „Użytkownik"** (`user`) — wybór jednego lub wielu użytkowników przez tę
  samą wyszukiwarkę AJAX co relacja (`evk-rel`, tryb `data-source="user"`). Konfiguracja:
  wielokrotny wybór + opcjonalny filtr ról. Zapis = tablica ID userów.
- **Wyszukiwarka AJAX** rozszerzona o tryb użytkowników (`WP_User_Query` po login/email/
  display_name, filtr `role__in`). (`includes/metabox.php`, `assets/admin.js`)
- **Tagi Bricks:** `{evk_field_klucz}` = nazwa pierwszego, `__ids`, `__count`, `__email`,
  `__url` (URL autora). Whitelist propów rozszerzona o `email`.
- Kolumna admina = lista nazw użytkowników. (`includes/builder.php`, `includes/bricks.php`,
  `includes/admin-columns.php`, `assets/builder.js`, `assets/builder.css`)

## [1.30.3] — 2026-06-25

### Zmienione

- **Tooltip „?" przeniesiony na prawą stronę pola** (zamiast przy etykiecie) — input
  i ikona stoją w wierszu flex, więc pole skraca się odrobinę, robiąc miejsce na „?".
  Repeater zachowuje „?" przy etykiecie (to widżet, nie pojedynczy input).
  (`includes/metabox.php`, `assets/admin.css`)

## [1.30.2] — 2026-06-25

### Naprawione

- **Tooltip „?" nie pokazywał się przy polu bez etykiety.** Ikona była doklejana wewnątrz
  bloku `<label class="evk-s-label">`, który renderuje się tylko gdy etykieta jest niepusta.
  Teraz blok etykiety renderuje się również, gdy ustawiono sam tooltip (pokazuje wtedy samo
  „?"). Dotyczy pól danych i repeaterów. (`includes/metabox.php`)

## [1.30.1] — 2026-06-25

### Naprawione

- **Pole bez klucza znikało przy zapisie.** Gdy użytkownik nie wpisał etykiety (a więc JS
  nie wygenerował klucza), pole danych było wyrzucane (`return null`) podczas zapisu —
  utrata skonfigurowanego pola. Teraz przy pustym kluczu parser próbuje wyprowadzić klucz
  z etykiety, a jeśli i ta pusta — nadaje standardowy fallback `pole_N`. Żadne pole nie
  ginie. (`includes/builder.php`)
- **Tooltip (ikona „?") nie był widoczny.** Opierał się na foncie dashicons, który
  w metaboksie bywa zawodny. Zamieniony na literalne „?" w kółku (CSS, bez zależności od
  fontu ikon). (`includes/metabox.php`, `assets/admin.css`)

## [1.30.0] — 2026-06-24

### Dodane — instrukcje pola + tooltip

- **Instrukcja pola** — opcjonalna szara podpowiedź (`instructions`) renderowana **pod
  inputem** w metaboxie, na stronach opcji i w wierszach repeatera. Per-pole, niezależna
  od typu układu „Opis".
- **Tooltip** — opcjonalny dymek (`tooltip`) pokazywany po najechaniu/zogniskowaniu ikony
  „?" przy etykiecie pola. Dymek CSS na `::after` (z resetem `font-family`, bo dashicony
  zajmują `::before` glifem); dostępny też z klawiatury (`tabindex`, `aria-label`).
- Oba ustawiane w bloku „Opcje pola" w builderze; działają dla pól danych i pól
  powtarzalnych (sub-pól). (`includes/builder.php`, `includes/metabox.php`,
  `assets/admin.css`)

## [1.29.0] — 2026-06-24

### Dodane — pole Link

- **Nowy typ pola „Link / przycisk"** (`link`) — URL + etykieta + „Otwórz w nowym oknie"
  w jednym polu (zamiast dwóch pól text). Zapis jako tablica `{url, title, target}`.
  Działa w grupach pojedynczych, repeaterach, stronach opcji i kolumnach admina.
- **Tagi Bricks:** `{evk_field_klucz}` = URL (do bindowania linku przycisku),
  `__title` = etykieta, `__target` = `_blank`/puste, `__html` = gotowy `<a href target>`.
  Lista propów Bricks rozszerzona o `title|target|html|raw|timestamp`.
  (`includes/builder.php`, `includes/metabox.php`, `includes/bricks.php`,
  `includes/admin-columns.php`, `assets/admin.css`, `assets/builder.js`)

### Naprawione

- **Hint formatu daty** błędnie podawał składnię propów Bricks jako `:raw`/`:timestamp` —
  poprawne to `__raw`/`__timestamp` (i wcześniej nie były w ogóle w whiteliście propów,
  teraz są). (`includes/builder.php`, `includes/bricks.php`)

## [1.28.0] — 2026-06-24

### Dodane — klaster daty

- **Nowe typy pól: „Czas (godzina)"** (`time`, zapis `H:i`) i **„Data i godzina"**
  (`datetime`, zapis `Y-m-d H:i`). Natywne inputy `time` / `datetime-local`.
- **Format wyświetlania daty/czasu** — nowa opcja `date_format` (string PHP, np. `d.m.Y`,
  `j F Y`, `H:i`, `d.m.Y H:i`) widoczna w konfiguracji pól date/time/datetime. **Zapis
  pozostaje ISO** (niezależny od formatu — stabilne sortowanie, logika warunkowa, kolumny);
  format steruje tylko **wyświetlaniem** na froncie/Bricks i w kolumnie admina. Puste =
  ustawienie witryny (`date_format`/`time_format`).
- W danych dynamicznych Bricks dla pól daty dostępne props: domyślnie sformatowana data,
  **`:raw`** (wartość ISO z bazy) i **`:timestamp`** (unix). Formatowanie przez `date_i18n`.
- Time/datetime dostępne też w polach załącznika (modal mediów).
  (`includes/builder.php`, `includes/metabox.php`, `includes/bricks.php`,
  `includes/admin-columns.php`, `assets/builder.css`)

### Rationale

Rozdzielenie zapisu (ISO) od wyświetlania (format) celowo unika problemu znanego z Meta Box,
gdzie format zapisu = format wyświetlania — tam zmiana formatu rozjeżdża istniejące wpisy
i psuje sortowanie/porównania.

## [1.27.0] — 2026-06-24

### Dodane — Faza 4b cz. 2: wyszukiwanie po wartości kolumny (użytkownicy)

- **Pole „Szukaj" na liście użytkowników obejmuje wartości pól kolumnowych EVK**
  (`usermeta`). Brak dedykowanego filtra search jak przy postach, więc modyfikujemy
  `query_where` w `pre_user_query`: do grupy wyszukiwania (tej z `user_login`) doklejamy
  `OR ID IN (SELECT user_id FROM usermeta WHERE meta_key IN (…) AND meta_value LIKE …)`.
  Ograniczone do ekranu `users.php`, `$wpdb->prepare`, podzapytanie zamiast JOIN.

### Naprawione

- **Wyszukiwanie wpisów (4b cz.1)** — fraza zawierająca znaki specjalne replacementu
  regex (`$1`, `\`) mogła zepsuć doklejane podzapytanie. Zamiana `preg_replace`
  na `preg_replace_callback`. (`includes/admin-columns.php`)

## [1.26.0] — 2026-06-24

### Dodane — Faza 4b cz. 1: wyszukiwanie po wartości kolumny (wpisy)

- **Pole „Szukaj" na liście wpisów obejmuje teraz wartości pól kolumnowych EVK**
  (oznaczonych „Pokaż jako kolumnę"). Wpisanie frazy znajduje wpisy, gdzie pasuje tytuł
  **lub** wartość pola EVK zapisana w `postmeta`. Filtr `posts_search` dokleja podzapytanie
  `ID IN (SELECT … FROM postmeta WHERE meta_key IN (…) AND meta_value LIKE …)` —
  bez JOIN-u, więc bez duplikatów wyników. Zapytanie budowane przez `$wpdb->prepare`.
  Tylko panel admina, tylko zapytanie główne, klucze ograniczone do pól danego typu treści.
  (`includes/admin-columns.php`)

  Termy i użytkownicy — w kolejnych częściach.

## [1.25.1] — 2026-06-24

### Dodane

- **Szerokość kafelka galerii w edytorze** — nowa opcja `gallery_item_width` (px, 60–400)
  w konfiguracji pola galeria. Ustawia szerokość `.evk-gallery-item` w metaboxie
  (miniatura skaluje się, bo zachowuje `aspect-ratio 1/1`). Puste = domyślne 108px.
  Stosowane też w szablonie nowego kafelka. (`includes/builder.php`, `includes/metabox.php`)

## [1.25.0] — 2026-06-24

### Dodane

- **Modal potwierdzenia usunięcia** w builderze (pola, pola powtarzalne, grupy) — zamiast
  natychmiastowego usuwania / natywnego `window.confirm`. Ładny dialog z ikoną, „Anuluj"
  i czerwonym „Usuń"; obsługa Esc/Enter i klik w tło. (`assets/builder.js`, `assets/builder.css`)
- **Ostrzeżenie o niezapisanych zmianach** — przy próbie opuszczenia edytora grupy z
  niezapisanymi zmianami (edycja/dodanie/usunięcie/przesunięcie pól) przeglądarka pokazuje
  natywne ostrzeżenie. Flaga czyszczona przy zapisie formularza. (`assets/builder.js`)

### Zmienione

- **Ikony kopiowania/usuwania pola** w „Definicji pól" — przeniesione na styl boksowanych
  przycisków 30×30 jak w taksonomiach i stronach opcji; ikona usuwania zmieniona z „×"
  (`dashicons-no-alt`) na kosz (`dashicons-trash`). (`includes/builder.php`, `assets/builder.css`)

## [1.24.0] — 2026-06-24

### Dodane — Faza 5 cz. 2: logika warunkowa (runtime w metaboxie)

- **Pola pokazują/ukrywają się na żywo** wg reguł zdefiniowanych w builderze (część 1).
  Wrapper pola dostaje `data-evk-cond` z regułami; JS w `admin.js` przelicza widoczność
  przy każdej zmianie pola i przy starcie. Ukryte pole = klasa `.evk-cond-hidden`
  (`display:none`). Wartość nie jest kasowana (pole tylko znika z widoku).
- **Operatory:** `==`, `!=`, `zawiera`, `puste`, `niepuste`; tryb `wszystkie` (AND) /
  `dowolny` (OR). Odczyt wartości obsługuje toggle, checkbox, radio, button group,
  select i pola tekstowe.
- **Zasięg per kontekst:** pole źródłowe szukane jest wśród rodzeństwa w tym samym
  `.evk-s` — w wierszu repeatera warunki odnoszą się do pól tego samego wiersza, w grupie
  pojedynczej do pól grupy. Nowo dodane wiersze repeatera są od razu przeliczane.
- Działa na wszystkich ekranach danych (wpis, term, profil, strony opcji — wszędzie tam
  ładuje się `admin.js`). (`includes/metabox.php`, `assets/admin.js`, `assets/admin.css`)

## [1.23.3] — 2026-06-24

### Zmienione

- **„Powiązane typy treści"** w taksonomiach zajmuje teraz **całą szerokość wiersza**
  (`grid-column: 1 / -1`), więc chipy układają się poziomo zamiast tłoczyć w wąskiej
  kolumnie siatki. (`includes/taxonomies.php`, `assets/evk-admin.css`)

## [1.23.2] — 2026-06-24

### Zmienione

- **„Powiązane typy treści" w taksonomiach** — zamiast `<select multiple>` (mało czytelny,
  wymaga Ctrl+klik) teraz siatka **chipów-checkboxów**, taka sama jak wybór grup pól na
  stronach opcji (klasy `.evk-sp-tab-groups` / `.evk-sp-group-pick`). Zmiana w wierszu
  istniejącym i w szablonie nowego wiersza (JS). Zapis bez zmian — brak zaznaczeń nadal
  domyślnie `post`. (`includes/taxonomies.php`, `assets/evk-admin.css`)

## [1.23.1] — 2026-06-24

### Dodane

- **Przycisk „Zwiń wszystko / Rozwiń wszystko"** nad listą pól w metaboksie „Definicja
  pól" — zwija/rozwija wszystkie pola najwyższego poziomu jednym kliknięciem.
  (`includes/builder.php`, `assets/builder.js`, `assets/builder.css`)

### Naprawione

- **Układ „Etykieta wiersza (z pola)"** w polu powtarzalnym — label i select stoją teraz
  w pionie (jak wiersz szablonu), a checkbox „Wiersze zwinięte na start" przeniesiony do
  osobnej linii. Wcześniej checkbox dryfował w prawo (`margin-left:auto`) i wyglądał
  niechlujnie. (`includes/builder.php`, `assets/builder.css`)
- **Nierówny odstęp pod tytułem metaboksów grupy pól** — rdzeniowa reguła WP
  `#poststuff .inside{margin:6px 0 0}` (id+klasa) bije `.postbox .inside{margin:11px 0}`,
  dając asymetryczny margines. Dodano regułę o równej specyficzności dla metaboksów
  `#evk_group_*`, przywracającą symetryczny `margin:11px 0`. (`assets/builder.css`)

## [1.23.0] — 2026-06-24

### Dodane — Faza 5 cz. 1: logika warunkowa (UI w builderze)

- **Blok „Logika warunkowa"** w każdej karcie pola — zwijany `<details>`, domyślnie
  zwinięty (spójny z „Opcje pola"). Pozwala zdefiniować, kiedy pole ma być widoczne.
- **Reguły** w formie wierszy: `[pole ▾] [operator ▾] [wartość]` + przycisk usuwania.
  Operatory: `jest równe`, `różne od`, `zawiera`, `puste`, `niepuste` (dla dwóch ostatnich
  pole wartości znika). Tryb relacji: **wszystkie** (AND) / **dowolny** (OR).
- **Lista „pole"** to rodzeństwo na tym samym poziomie (pola grupy albo pola powtarzalne
  tego repeatera), budowana na żywo w JS — odświeża się przy zmianie klucza/etykiety oraz
  dodaniu/usunięciu/klonowaniu pól. Zapisany wybór przeżywa klonowanie (`data-selected`).
- **Schemat:** zapis do `conditions` = `{relation: all|any, rules: [{field, op, value}]}`
  (puste/niepuste bez `value`). Brak reguł = pole zawsze widoczne.
- Działa dla pól danych i powtarzalnych. **Runtime** (faktyczne pokaż/ukryj w metaboxie)
  przyjdzie w części 2. (`includes/builder.php`, `assets/builder.js`, `assets/builder.css`)

## [1.22.2] — 2026-06-24

### Zmienione

- **Blok „Opcje pola"** (placeholder / prefiks / sufiks / wiersze / wymagane) jest teraz
  zwijanym `<details>` — domyślnie **zwinięty**, rozwija się po kliknięciu nagłówka
  (chevron obraca się). Mniej szumu w konfiguracji każdego pola. (`includes/builder.php`,
  `assets/builder.css`)

## [1.22.1] — 2026-06-24

### Zmienione

- **Ściągawka „Jak wyświetlić w Bricks"** (pole galeria) jest teraz zwijanym blokiem
  `<details>` — domyślnie **zamknięta**, rozwija się po kliknięciu nagłówka (chevron
  obraca się). Wcześniej zajmowała dużo miejsca w konfiguracji każdego pola galerii.
  (`includes/builder.php`, `assets/builder.css`)

### Usunięte

- **Generyczny baner z podpowiedziami tagów** (`evk-b-info`) nad listą pól w edytorze
  grupy — zbędny, bo ściągawki tagów są teraz przy konkretnych polach. Klasa CSS
  `.evk-b-info` zostaje (używana na stronie Narzędzia). (`includes/builder.php`)

## [1.22.0] — 2026-06-24

### Dodane

- **Klonowanie pola w builderze** — przycisk „Klonuj pole" (ikona strony) obok przycisku
  „Usuń" w nagłówku każdego pola. Tworzy głęboką kopię pola (wartości, typ, opcje),
  zamienia indeksy formularza na unikalne, dodaje sufiks `_kopia` do klucza pola.
  Działa dla pól głównych i pól powtarzalnych (sub-fields). (`includes/builder.php`,
  `assets/builder.js`, `assets/builder.css`)

### Naprawione

- **Pozycja checkboxów „Zwijany / Zwinięty na start" w polu Opis** — oba miały
  `margin-left:auto` w kontenerze flex, co dzieliło wolne miejsce i przesuwało oba
  do środka. Pierwszy checkbox dostał `margin-left:0` → układ „lewo / prawo".
  (`includes/builder.php`)

## [1.21.2] — 2026-06-23

### Dodane

- **Opcja „Ukryj tytuł grupy"** — checkbox w ustawieniach grupy pól (metabox „Ustawienia").
  Gdy zaznaczony, `<h2 class="evk-settings-group-title">` nie jest renderowany na stronie
  ustawień. Niezależny od opcji „Bezramkowy" — można ukryć sam tytuł zachowując ramkę
  (lub odwrotnie). Zapisywany jako `_evk_hide_title` w post meta grupy.
  (`includes/builder.php`, `includes/field-groups.php`, `includes/settings.php`)

## [1.21.1] — 2026-06-23

### Naprawione

- **Przełącznik — etykieta ON nie zmieniała się po kliknięciu.** Selektor CSS
  `~*` nie działa na elementach nie-rodzeństwo. Zamienione na klasę `is-on`
  dodawaną na wrapperze przez JS (`admin.js`); `admin.css` używa `.evk-rep-toggle.is-on`.

- **Separator nagłówka wyświetlał się zawsze.** Selektor `.has-separator .evk-s-heading`
  nie pasował do `<hX class="evk-s-heading--h3">`. Naprawiono jako
  `.has-separator > h1/h2/h3/h4/h5` (child combinator). (`assets/admin.css`)

- **Puste etykiety pola** — jeśli etykieta jest pusta, `<label>` nie jest renderowany
  (metabox — pola single i repeatera). Builder pozwala teraz zapisać pustą etykietę
  zamiast wstawiać klucz pola jako fallback. (`includes/metabox.php`, `includes/builder.php`)

### Dodane

- **H1** jako opcja rozmiaru pola Nagłówek (builder + metabox + CSS).
  (`includes/builder.php`, `includes/metabox.php`, `assets/admin.css`)

- **Padding opisu zwijany** — `.evk-s-desc--collapsible .evk-s-desc-body`
  zmieniony na `12px 15px 12px`. (`assets/admin.css`)

## [1.21.0] — 2026-06-23

### Dodane — Faza 6B: nowe typy pól i rozbudowa układu

- **Przełącznik (toggle)** — nowy typ pola danych. iOS-style slider z konfigurowanymi
  wartościami ON/OFF (domyślnie `1`/`0`) oraz etykietami (domyślnie „Tak"/„Nie").
  Wartości i etykiety ustawiane w builderze. Zapis jak `text`. (`includes/metabox.php`,
  `includes/builder.php`, `assets/admin.css`, `assets/builder.css`, `assets/builder.js`)

- **Opis (blok tekstowy)** — nowy typ układu `description`. Wyświetla sformatowany
  blok informacyjny (HTML dozwolony przez `wp_kses_post`). Tryb zwijany
  (`desc_collapsible` = klik w tytuł rozwija/zwija przez `<details>`) z opcją
  „Zwinięty na start" (`desc_collapsed`). Tytuł z etykiety pola.

- **Nagłówek — konfiguracja rozszerzona**: preset rozmiaru (H2/H3/H4/H5),
  opcjonalny podtekst (`heading_sub`) oraz separator-linia (`heading_separator`).
  Wsteczna zgodność: stare wpisy bez konfiguracji renderują się jak dotąd (H3).

## [1.20.1] — 2026-06-23

### Naprawione
- **„Opcje pola" nie pokazywały się w polach repeatera.** Selektor ukrywający blok
  używał descendant combinatora (`.is-repeater .evk-b-field-extra`), więc chował go
  też w polach zagnieżdżonych. Zmienione na child (`>`). (`assets/builder.css`)

### Zmienione
- Nazewnictwo: „pod-pola" → **„pola powtarzalne"** (typ pola, tytuł sekcji, przycisk
  „Dodaj pole powtarzalne"). (`includes/builder.php`)

## [1.20.0] — 2026-06-23

### Dodane — szybkie opcje pól (Faza 6 cz. A)
- **Placeholder** dla pól tekstowych (tekst, textarea, liczba, e-mail, URL).
- **Przełącznik „Pole wymagane"** — atrybut `required` (gdzie formularz to wspiera)
  + czerwona gwiazdka przy etykiecie.
- **Liczba wierszy dla textarea** (1–50).
- **Prefiks / sufiks** wokół pola (np. `PLN`, `$`) dla tekst/liczba/e-mail/URL/data —
  jak na przykładzie „30 | PLN".
- **Szablon tytułu wiersza repeatera z kluczy**, np. `{tytul} | {cena_dania}` —
  na poziomie grupy (pole „Etykieta wiersza") i pola repeatera (osobne pole szablonu).
  Podgląd na żywo w builderze. Ma pierwszeństwo przed pojedynczym kluczem.
- Opcje pola pokazują się kontekstowo wg typu (`data-ftype`).
  (`includes/builder.php`, `includes/metabox.php`, `assets/{builder,admin}.{js,css}`)

## [1.19.1] — 2026-06-23

### Zmienione — media w panelu szczegółów załącznika
- Pola grupy „Media" pokazują się teraz w **panelu „Szczegóły załącznika" w modalu
  mediów** (przy podglądzie, z prawej) przez `attachment_fields_to_edit` /
  `attachment_fields_to_save`, zamiast metaboxa pod podglądem. Obsługiwane proste
  typy pól (tekst, textarea, lista, radio, checkbox, liczba, URL, e-mail, kolor,
  data). Odczyt w pętli galerii (Isotope) bez zmian. (`includes/metabox.php`,
  `includes/builder.php`)

## [1.19.0] — 2026-06-23

### Dodane — lokalizacja „Media (załączniki)"
- Grupa pól może celować w **Media (załączniki)** — pola pojawiają się na ekranie
  edycji załącznika (szczegóły obrazka), zapis do post meta załącznika. Reużywa pełny
  render metaboxa (wszystkie typy pól). (`includes/builder.php`, `includes/metabox.php`,
  `includes/field-groups.php`)
- **Pola media dostępne w pętli galerii jako pola bieżącego obrazu** — w iteracji
  pętli galerii (zwykłej i spłaszczonej) tag pola media (np. `{evk_field_rozmiar}`)
  rozwiązuje się z meta aktualnego obrazu. Idealne do klas Isotope (rozmiar/format
  per obraz). Poza pętlą działa też dla oglądanego załącznika (queried object).
  (`includes/bricks.php`)

## [1.18.0] — 2026-06-23

### Dodane — sortowanie galerii
- Pole Galeria ma opcję **Sortowanie obrazów (front)**: kolejność dodania (domyślnie),
  **losowo**, **losowo — zmiana co godzinę**, **losowo — zmiana co dzień**. Tasowanie
  „co godzinę/dzień" jest deterministyczne w obrębie okna (stabilne dla UX, Isotope i
  cache), różne per galeria. Dotyczy pętli galerii: zwykłej, opcji oraz spłaszczonej
  z repeatera. (`includes/builder.php`, `includes/bricks.php`)

## [1.17.0] — 2026-06-23

### Zmienione — spłaszczona galeria niesie pola wiersza
- W pętli **„EVK Galeria — wszystkie wiersze: …"** każdy obraz niesie teraz także
  **pozostałe (skalarne) pola swojego wiersza repeatera** — np. `{evk_field_tytul}`.
  Dzięki temu spłaszczone obrazy można rozróżniać/filtrować po dowolnym polu wiersza
  (np. tytule nad zdjęciami), nie tylko po kategorii galerii. Pola galerii/relacji
  z wiersza są pomijane (to tablice). (`includes/bricks.php`)

## [1.16.0] — 2026-06-23

### Dodane — spłaszczona galeria z repeatera (Isotope)
- **Pętla „EVK Galeria — wszystkie wiersze: …"** — zwraca WSZYSTKIE obrazy ze
  wszystkich wierszy repeatera jako **jedną płaską listę** (jeden kontener = jedna
  siatka Isotope, obrazy obok siebie). Każdy obraz zachowuje swoją kategorię.
  Rozwiązuje problem zagnieżdżonych pętli renderujących osobne galerie pod sobą.
- **Pętla „EVK Galeria kategorie — wszystkie: …"** — kategorie użyte we wszystkich
  wierszach (distinct) na przyciski filtrów dla spłaszczonej listy.
- Oba z wariantem „(Opcje)" dla stron opcji. (`includes/bricks.php`)

## [1.15.0] — 2026-06-23

### Dodane — galeria/relacja w repeaterze
- **Pętle galerii i relacji rejestrowane także dla pod-pól repeatera** (ścieżki
  zagnieżdżone, np. `repeater.galeria`). Pozwala zagnieździć w Bricks pętlę galerii
  wewnątrz pętli repeatera: wiersz repeatera → jego galeria (obrazy + kategorie).
  Dotyczy też relacji oraz wariantu „(Opcje)" i pętli „EVK Galeria kategorie".
  (`includes/bricks.php`)

## [1.14.4] — 2026-06-23

### Naprawione — obraz w danych dynamicznych Bricks (rozwiązanie z forum)
- Element Image bierze **`$value[0]`** ze zwrotu tagu, więc w kontekście `image`/`media`
  tag zwraca teraz **tablicę indeksowaną z URL-em pod `[0]`** (`[$url]`), zgodnie ze
  wzorcem z forum Bricks (`$value = !empty($value) ? [$value] : [];`). Wcześniej:
  string → `src="h"` (pierwszy znak), tablica asocjacyjna → brak `[0]` → pusto.
  Teraz element Image renderuje obraz z pola „dane dynamiczne" (z linkiem do lightbox).
  (`includes/bricks.php`)

## [1.14.3] — 2026-06-23

### Naprawione — obraz w danych dynamicznych Bricks (właściwa naprawa)
- W kontekście `image`/`media` Bricks robi **dostęp tablicowy** na zwrocie tagu
  (`$value['url']` / `['id']`). String URL dawał `src="h"` (pierwszy znak `https`),
  a samo ID — pusto. Tag obrazka zwraca teraz **znormalizowaną tablicę**
  `['id','url','src','size','full','alt']`, więc element Image w polu „dane dynamiczne"
  renderuje obraz poprawnie (z kontrolą rozmiaru, srcset, lightbox). (`includes/bricks.php`)

## [1.14.2] — 2026-06-23

### Naprawione — obraz w danych dynamicznych Bricks
- **Wiązanie obrazka w polu „dane dynamiczne" (np. element Image) zwracało pusto.**
  Element Image woła filtr z kontekstem `image`/`media` i oczekuje **ID załącznika**,
  a nasze tagi zwracały URL niezależnie od kontekstu. Teraz w kontekście obrazka tag
  zwraca ID → binding działa, z kontrolą rozmiaru i srcset. (Wcześniej działało tylko
  przez „niestandardowy URL", bo to kontekst tekstowy.) (`includes/bricks.php`)
- **Podgląd galerii w builderze Bricks** używa najnowszego obrazu z biblioteki jako
  placeholdera (zamiast pustego ID 0), więc pętla galerii nie wygląda na „pustą".
  (`includes/bricks.php`)

## [1.14.1] — 2026-06-23

### Zmienione
- **Ściągawka galerii** zaleca teraz wiązanie obrazka w Bricks przez
  `{evk_field_img__id}` + Size „Large/Full" (zamiast URL). Bricks narzucał sztywne
  `width/height` (np. 800×600) na obrazkach wiązanych przez URL, bo nie znał ich
  wymiarów — wiązanie przez ID daje rzeczywiste wymiary per obraz, srcset i poprawny
  lightbox. (`includes/builder.php`)

## [1.14.0] — 2026-06-23

### Dodane — galeria
- **Pętla „EVK Galeria kategorie: …"** — zwraca tylko kategorie faktycznie UŻYTE w
  danej galerii (distinct, `{slug, name}`), idealne na przyciski filtrów Isotope
  pasujące 1:1 do zawartości. Wariant „(Opcje)" dla stron opcji. (`includes/bricks.php`)
- **Kontrola rozmiaru obrazka w tagach**: `{evk_field_img__large}` (oraz `medium`,
  `full`, `medium_large`, `thumbnail`, własne rozmiary) zwraca URL danego rozmiaru.
  Rozwiązuje niską rozdzielczość i kwadratowe przycięcie przy wiązaniu przez `__id`
  z rozmiarem „thumbnail". (`includes/bricks.php`)

### Zmienione
- Ściągawka galerii: dodano sekcję przycisków filtrów oraz wskazówkę o rozmiarze obrazka.

## [1.13.0] — 2026-06-23

### Dodane — pętla termów taksonomii
- **Nowy typ pętli Bricks „EVK Termy: …"** dla każdej publicznej taksonomii (w tym
  własnych). Zwraca termy z **`hide_empty = false`** (puste termy też się pokazują —
  częsta przyczyna „brak wyników" w natywnej pętli Terms), z natywnym kontekstem
  termu Bricks (tagi `{term_*}`) oraz działającymi tagami pól EVK termu
  (`{evk_field_…}`). Idealne pod filtry Isotope. (`includes/bricks.php`)
- Filtry `loop_object_type` / `loop_object_id` rozpoznają teraz wprost
  `WP_Term` / `WP_User` / `WP_Post`, dzięki czemu pętle relacji i termów dostają
  poprawny kontekst obiektu w Bricks.

## [1.12.2] — 2026-06-23

### Naprawione — galeria
- **Pętla galerii (i relacji, pod-repeatera) na stronie opcji nie zwracała danych.**
  Dane opcji grupy pojedynczej są w `evk_rep_opt_{grupa}` zagnieżdżone pod kluczem
  pola, a pętla rejestrowała ścieżkę opcji jako sam klucz pola → szukała nieistniejącej
  opcji. Ścieżka opcji to teraz `grupa.pole`. Błąd dotyczył wszystkich pętli pól w
  grupach pojedynczych na stronach opcji. (`includes/bricks.php`)
- **Przełączenie galerii z „kategorie" na „prostą" nie zapisywało się.** Parser przy
  zapisie wnioskował źródło z pozostałej treści textarea (przywracał `manual`). Teraz
  wartość selektora źródła jest respektowana wprost. (`includes/builder.php`)
- Ściągawka galerii wskazuje wariant pętli „EVK Galeria Opcje: …" dla stron opcji.

## [1.12.1] — 2026-06-23

### Dodane
- **Ściągawka w konfiguracji pola Galeria** — po wybraniu typu „Galeria" pokazuje
  się krótka instrukcja „jak wyświetlić w Bricks" (pętla + tagi `{evk_field_img}` /
  `{evk_field_cat__label}` oraz proste tagi `__ids` / `__count`). Proste tagi
  aktualizują się na żywo wg klucza pola. (`includes/builder.php`,
  `assets/builder.{css,js}`)

## [1.12.0] — 2026-06-23

### Dodane — pole Relationship
- **Nowy typ pola „Relacja (posty)"** — wybór powiązanych wpisów z wyszukiwarką AJAX,
  lista wybranych z drag-sortem i usuwaniem, tryb pojedynczy/wielokrotny, konfigurowalne
  typy treści do przeszukania. (`includes/builder.php`, `includes/metabox.php`,
  `assets/admin.{js,css}`)
- **Bricks:** relacja rejestruje się jako **pętla zwracająca powiązane wpisy** z
  natywnym kontekstem posta (renderujesz je zwykłymi tagami posta). Tagi pojedyncze:
  `{evk_field_klucz__ids}`, `__count`, `__url` (link 1.), `{evk_field_klucz}` (tytuł 1.).
  Bulk-prime cache powiązanych postów. (`includes/bricks.php`)

### Zmienione — galeria
- **Źródło kategorii obrazów**: oprócz listy ręcznej można teraz pobrać kategorie
  **z taksonomii** (termy danej taksonomii jako opcje kategorii per obraz).
  (`includes/builder.php`, `includes/metabox.php`, `includes/bricks.php`)
- **Dopracowane UI galerii**: większe, zaokrąglone kafelki z hover, kosz na hover,
  `object-fit: cover`, spójny przycisk dodawania. (`assets/admin.css`)

## [1.11.0] — 2026-06-23

### Dodane — pole Galeria
- **Nowy typ pola „Galeria"** — multi-wybór obrazów z biblioteki mediów, miniatury
  z drag-sortem i usuwaniem. Jedno pole, dwa tryby zależne od konfiguracji:
  - **Puste „Kategorie obrazów"** = prosta galeria (lista ID, jak ACF Gallery).
  - **Wypełnione kategorie** (format `wartość : Etykieta`) = każdy obraz dostaje
    selektor kategorii; front zwraca jedną płaską listę z kategorią przy każdym
    obrazie (filtrowalną). (`includes/builder.php`, `includes/metabox.php`,
    `assets/admin.{js,css}`, `assets/builder.{css,js}`)
- **Integracja z Bricks:**
  - Galeria rejestruje się jako **pętla** (rows `{img, cat}`) — w pętli używasz
    natywnych tagów obrazu + kategorii do renderu i filtrowania.
  - Tagi pojedyncze: `{evk_field_klucz__ids}` (CSV ID), `{evk_field_klucz__count}`,
    `{evk_field_klucz}` (URL pierwszego). (`includes/bricks.php`)
- **Wydajność:** przy pętli galerii jeden bulk-prime cache załączników
  (`_prime_post_caches`) zamiast N zapytań — zamiast osobnej warstwy „Object Cache".

## [1.10.1] — 2026-06-23

### Naprawione
- **Zwijanie pola w builderze odsłaniało ustawienia niewłaściwych typów** (Image
  Select, suwak, taksonomia, pod-pola repeatera pojawiały się przy polu innego
  typu; znikały po zapisie). Przyczyna: `slideToggle` ustawiał inline `display:block`
  na wszystkich blokach konfiguracji. Zwijanie jest teraz wyłącznie klasowe
  (CSS `.is-collapsed`), więc widocznością steruje typ pola. (`assets/builder.js`,
  `assets/builder.css`)
- **Kolumna bez ustawionej „Pozycji" doklejała się na samym końcu tabeli** i wyglądała
  jakby nie działała. Domyślnie wstawiana jest teraz tuż po kolumnie głównej
  (tytuł / nazwa / nazwa użytkownika). (`includes/admin-columns.php`)

## [1.10.0] — 2026-06-22

### Dodane (Faza 4 — kolumny w panelu admina)
- **Pola można pokazać jako kolumny** w tabelach wpisów, termów i użytkowników
  (`includes/admin-columns.php`). Per pole (grupy pojedyncze): przełącznik „Pokaż
  jako kolumnę", **tytuł kolumny**, **pozycja** i **sortowanie**.
  - Wartości formatowane wg typu: obraz → miniatura, kolor → próbka, checkbox →
    znacznik, taksonomia → nazwy termów, listy → etykieta, link → odnośnik,
    tekst/WYSIWYG → skrócony podgląd.
  - Sortowanie przez `meta_key` + `orderby` (`meta_value`/`meta_value_num`) na
    `pre_get_posts` / `pre_get_users` / `get_terms_args`.
  - Konfiguracja kolumny w builderze pola (`includes/builder.php`,
    `assets/builder.{css,js}`) — ukrywana dla separatorów, repeaterów i grup-repeaterów.

### Uwagi
- Kolumny dotyczą grup **pojedynczych** (w repeaterze wartość to tablica wierszy).
- **Wyszukiwanie po wartości kolumny** (searchable) = Faza 4b — wymaga modyfikacji
  zapytań SQL i trafi w osobnej paczce po weryfikacji rdzenia na żywo.

## [1.9.0] — 2026-06-22

### Dodane (Faza 3b)
- **Tagi Bricks dla pól termów i użytkowników działają na froncie.** Resolver
  rozpoznaje typ obiektu grupy i czyta z właściwego źródła:
  - pola termów → `term meta` bieżącego termu (queried object archiwum lub
    iteracja natywnej pętli Bricks po termach),
  - pola użytkownika → `user meta` bieżącego użytkownika (archiwum autora lub
    pętla po użytkownikach). (`includes/bricks.php`)
- **Image Select: picker z biblioteki mediów.** Przycisk „Dodaj obrazy z biblioteki"
  w konfiguracji pola dopisuje wybrane obrazy jako linie `URL : Etykieta` — koniec
  ręcznego wklejania adresów. (`includes/builder.php`, `assets/builder.js`,
  `includes/field-groups.php`)

### Zmienione
- **Dopracowane UI metaboxu „Lokalizacja"**: stylizowany select typu obiektu oraz
  pozycje typów treści / taksonomii jako zaznaczalne karty z podświetleniem
  wybranych. (`assets/builder.css`)

## [1.8.0] — 2026-06-22

### Dodane (Faza 3 — lokalizacje)
- **Grupa pól może teraz celować w różne typy obiektów**, nie tylko wpisy. Nowy
  metabox **Lokalizacja** (zastępuje „Typy treści") z wyborem „Pokaż w":
  - **Wpisy / strony** — jak dotąd (typy treści).
  - **Termy taksonomii** — pola na ekranie dodawania i edycji termu wybranych
    taksonomii; zapis do term meta.
  - **Profil użytkownika** — pola na ekranie edycji profilu; zapis do user meta.
  (`includes/builder.php`, `includes/locations.php`, `assets/builder.{css,js}`)

### Zmienione
- **Render i zapis pól uogólnione na dowolny typ meta** (`post` / `term` / `user`)
  przez `get_metadata()` / `update_metadata()`. Wspólne helpery
  `evk_rep_render_group_object()` i `evk_rep_save_group_object()` w `metabox.php`;
  metabox wpisu i zapis `save_post` przepisane na nie (rejestrowane tylko dla grup
  o lokalizacji „wpisy"). Schemat grupy zyskał `object_type` i `taxonomies`.
  (`includes/metabox.php`, `includes/field-groups.php`)

### Uwagi
- Integracja z Bricks (tagi/pętle) działa jak dotąd dla wpisów i stron opcji.
  Front-end dla termów/użytkowników to osobny krok (Faza 3b).

## [1.7.1] — 2026-06-22

### Naprawione
- **Ikony w przyciskach (np. Import / Eksport) nie były w jednej linii z tekstem.**
  Zamiast inline-hacków per przycisk dodano jedną globalną regułę: każdy przycisk
  `.button` z dashiconem w panelu wtyczki dostaje `inline-flex` + wyrównanie do osi.
  Usunięto ręczne `vertical-align` z przycisków Narzędzi. (`assets/evk-admin.css`,
  `includes/tools.php`)

## [1.7.0] — 2026-06-22

### Dodane (Faza 2)
- **Narzędzia** — nowa podstrona `Evoke FIELDS → Narzędzia` (`includes/tools.php`):
  - **Eksport** pełnej konfiguracji do JSON: grupy pól, typy treści, taksonomie,
    strony ustawień oraz wartości stron opcji (`evk_rep_opt_*`). Bez danych pól z
    wpisów (postmeta) — są nieprzenośne między witrynami.
  - **Import** pliku eksportu z mergem (grupy wg klucza, CPT/taksonomie/strony wg
    slug, opcje wg nazwy) i opcjonalnym nadpisywaniem istniejących.
  - **Czyszczenie osieroconych kluczy** (bezpieczne): usuwa osierocone opcje
    `evk_rep_opt_{klucz}` po skasowanych grupach oraz martwe odwołania do grup w
    stronach ustawień. Skan z podglądem przed usunięciem.

### Zmienione
- **Wydajność:** `evk_rep_groups()` i `evk_rep_loops()` memoizowane w obrębie
  żądania (wcześniej dziesiątki odczytów transientu / przebudów drzewa pętli na
  jednej stronie z wieloma tagami). Inwalidacja spięta z `evk_groups_cache_clear()`.
  (`includes/field-groups.php`, `includes/bricks.php`)
- **Odporność:** zapis typów treści i taksonomii pomija slugi zarezerwowane przez
  WordPress (np. `post`, `page`, `category`) i puste, oraz przycina długość
  (CPT ≤ 20, taksonomia ≤ 32 znaki). Pominięte slugi raportowane w komunikacie.
  (`includes/cpt.php`, `includes/taxonomies.php`)

## [1.6.3] — 2026-06-22

### Naprawione
- **Przełączniki w „Typach treści" / „Taksonomiach" — włączone (zielone) wyglądały
  nierówno.** Reguła WP core `input[type=checkbox]:checked::before` (checkmark +
  ujemny margines) przeciekała do gałki przełącznika tylko w stanie włączonym i
  przesuwała ją w lewo-górę. Dodano `margin: 0` oraz `content: ""` na pseudo-elemencie
  gałki, neutralizując styl rdzenia. (`assets/evk-admin.css`)

## [1.6.2] — 2026-06-22

### Naprawione (Faza 1)
- **Strona ustawień: nie dało się wyczyścić repeatera.** Po usunięciu wszystkich
  wierszy i zapisie pozycje wracały. Handler zapisu iterował po `$_POST['evk_opt']`,
  a pusty repeater nie wysyła żadnych pól. Teraz iterujemy po grupach aktywnej
  zakładki — pusty repeater zapisuje się jako pusta tablica. (`includes/settings.php`)
- **Bezramkowa grupa (seamless) nie działała na stronie ustawień.** Render zawsze
  owijał grupę w kartę z ramką i tytułem. Dodano modyfikator
  `.evk-settings-group--seamless` (bez ramki/tytułu), respektujący flagę grupy.
  (`includes/settings.php`, `assets/evk-admin.css`)
- **Pole „Klucz grupy" — etykieta obok zamiast nad polem.** Przepisano z układu
  tabelarycznego na stertę (etykieta → pole → opis), spójnie z „Etykietą przycisku".
  (`includes/builder.php`)
- **Image Select miał odwróconą kolejność `wartość : nazwa`.** Ujednolicono format do
  `URL obrazka : Etykieta` (jak inne selecty). Parser jest odporny na kolejność i
  wykrywa, która strona jest URL-em — stare wpisy `Etykieta : URL` nadal działają.
  (`includes/builder.php`, `includes/metabox.php`)
- **Przyciski w „Typach treści" / „Taksonomiach" się rozjeżdżały.** Zastąpiono
  przyciski ↑/↓ uchwytem przeciągania (jQuery UI sortable) i jednym przyciskiem
  usuwania — spójnie z builderem grup pól. (`includes/cpt.php`,
  `includes/taxonomies.php`, `assets/evk-admin.css`, `evk-repeater.php`)
- **Kosmetyka:** komunikat migracji pokazywał „(0 grup)" — teraz liczy faktycznie
  zmigrowane grupy. (`includes/field-groups.php`)

## [1.6.1]
- Wersja bazowa przed Fazą 1 (CPT grup pól, integracja Bricks v8.2, strony ustawień).
