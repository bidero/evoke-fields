/* Evoke FIELDS — tłumaczenia wartości pól (1.70.0).
   Przełącznik języka nad grupą pól, leniwe edytory WYSIWYG języków,
   „Kopiuj z polskiego", „Sprawdzone", liczniki przetłumaczonych pól.
   Serwer i nazwy pól: includes/translations.php. */
(function ($) {
    'use strict';

    // Ten sam edytor co w wierszach repeatera (admin.js).
    var TINY = {
        wpautop: true,
        plugins: 'charmap colorpicker directionality fullscreen hr image lists media paste tabfocus textcolor wordpress wpautoresize wpeditimage wpemoji wplink wptextpattern',
        toolbar1: 'bold italic | bullist numlist | blockquote | alignleft aligncenter | link unlink | wp_more | fullscreen'
    };

    function baza($g) { return String($g.attr('data-evk-baza') || 'pl'); }

    function typPola($pole) {
        var $f = $pole.closest('.evk-tl-tak');
        if ($f.hasClass('evk-rep-field--wysiwyg')) return 'wysiwyg';
        if ($f.hasClass('evk-rep-field--link')) return 'link';
        return 'tekst';
    }

    // Edytor wizualny pola, gdy jest aktywny. W trybie „Tekst" źródłem jest samo pole.
    function edytor(id) {
        if (!id || !window.tinymce) return null;
        var ed = window.tinymce.get(id);
        if (!ed) return null;
        var $wrap = $('#wp-' + id + '-wrap');
        return (!$wrap.length || $wrap.hasClass('tmce-active')) ? ed : null;
    }

    // HTML → sam tekst. DOMParser, nie jQuery: jego dokument jest bezwładny
    // (obrazki się nie wczytują, atrybuty on… nie odpalają).
    function tekst(v, typ) {
        v = String(v == null ? '' : v);
        if (typ === 'wysiwyg') v = new DOMParser().parseFromString(v, 'text/html').body.textContent || '';
        return $.trim(v.replace(/[\s ]+/g, ' '));
    }

    // Wartość podstawowa pola; w WYSIWYG — HTML (z akapitami).
    function plWartosc($pole) {
        var $pl = $pole.closest('.evk-tl-tak').children('.evk-tl-pl');
        var typ = typPola($pole);
        if (typ === 'link') return $pl.find('.evk-rep-link-title').first().val() || '';
        var $ta = $pl.find('textarea').first();
        if ($ta.length) {
            var ed = edytor($ta[0].id);
            if (ed) return ed.getContent();
            var v = $ta.val() || '';
            return (typ === 'wysiwyg' && window.wp && wp.editor && wp.editor.autop) ? wp.editor.autop(v) : v;
        }
        return $pl.find('input[type=text]').first().val() || '';
    }

    function twinWartosc($pole) {
        var $w = $pole.find('.evk-tl-wejscie').first();
        var ed = $w.length ? edytor($w[0].id) : null;
        return ed ? ed.getContent() : ($w.val() || '');
    }

    function ustawTwin($pole, v) {
        var $w = $pole.find('.evk-tl-wejscie').first();
        if (!$w.length) return;
        var ed = edytor($w[0].id);
        if (ed) { ed.setContent(v); ed.save(); return; }
        if (typPola($pole) === 'wysiwyg' && window.wp && wp.editor && wp.editor.removep) v = wp.editor.removep(v);
        $w.val(v);
    }

    // ── Edytory WYSIWYG języków: start dopiero w widocznym polu ──
    function uruchomEdytory($zakres) {
        if (!window.wp || !wp.editor || !wp.editor.initialize) return;
        $zakres.find('textarea.evk-tl-wysiwyg').each(function () {
            var id = this.id;
            if (!id || (window.tinymce && window.tinymce.get(id)) || !$(this).is(':visible')) return;
            wp.editor.initialize(id, {
                tinymce: $.extend({}, TINY, {
                    // Pole pod edytorem zawsze aktualne — zapis wpisu w edytorze
                    // bloków i dodawanie termu idą bez zdarzenia submit.
                    setup: function (ed) {
                        ed.on('change keyup undo redo', function () {
                            ed.save();
                            $(ed.getElement()).trigger('evk-tl-edytor');
                        });
                    }
                }),
                quicktags: true
            });
        });
    }

    // Przed powrotem do języka podstawowego: treść do pola i bez edytorów —
    // przeciąganie wierszy przenosi DOM, a ramka TinyMCE traci wtedy treść.
    function zatrzymajEdytory($zakres) {
        if (!window.wp || !wp.editor || !wp.editor.remove) return;
        $zakres.find('textarea.evk-tl-wysiwyg').each(function () {
            if (!this.id || !window.tinymce || !window.tinymce.get(this.id)) return;
            var ed = edytor(this.id);
            if (ed) ed.save();
            wp.editor.remove(this.id);
        });
    }

    // ── Liczniki: przetłumaczone / pola z tekstem podstawowym ──
    function licz($g) {
        $g.children('.evk-tl-przelacznik').find('.evk-tl-jezyk').each(function () {
            var lang = this.getAttribute('data-lang');
            if (lang === baza($g)) return;
            var m = 0, n = 0;
            $g.find('.evk-tl-pole[data-lang="' + lang + '"]').each(function () {
                var $p = $(this), typ = typPola($p);
                if (!tekst(plWartosc($p), typ)) return;
                m++;
                if (tekst(twinWartosc($p), typ)) n++;
            });
            $(this).find('.evk-tl-licznik').text(n + '/' + m);
            $(this).find('.evk-tl-licznik-sr').text(', przetłumaczone ' + n + ' z ' + m);
        });
    }
    var liczTimer = null;
    function liczPozniej() {
        clearTimeout(liczTimer);
        liczTimer = setTimeout(function () { $('.evk-tl-grupa').each(function () { licz($(this)); }); }, 200);
    }

    // Podgląd tekstu podstawowego nad polem — z tego, co jest w polu TERAZ.
    function odswiezPodglady($grupy) {
        $grupy.find('.evk-tl-tak').each(function () {
            var $pola = $(this).children('.evk-tl-pole');
            if (!$pola.length) return;
            var t = tekst(plWartosc($pola.first()), typPola($pola.first()));
            if (t.length > 160) t = t.slice(0, 159) + '…';
            $pola.find('.evk-tl-oryginal-tekst').each(function () {
                if (t) $(this).text(t);
                else $(this).empty().append($('<em>').text('(puste)'));
            });
        });
    }

    // Aktywna zakładka bez pól tłumaczalnych znika w widoku języka — wtedy pierwsza z polami.
    function pokazZakladki($grupy) {
        $grupy.find('.evk-s').each(function () {
            var $tabs = $(this).children('.evk-s-tabs').children('.evk-s-tab');
            if ($tabs.filter('.active').hasClass('evk-tl-bez')) $tabs.not('.evk-tl-bez').first().trigger('click');
        });
    }

    // ── Przełącznik: jeden język dla wszystkich grup na ekranie ──
    // Pola bez tłumaczenia w widoku języka: zostają na miejscu (układ się nie
    // zmienia), przygaszone (CSS) i nieaktywne — `inert`: bez klikania i bez
    // fokusu z klawiatury. Tak samo dodawanie, usuwanie i przeciąganie wierszy:
    // strukturę zmienia się w oryginale.
    var NIEAKTYWNE = '.evk-s-field:not(.evk-tl-tak):not(.evk-tl-zawiera), .evk-rep-add-wrap, .evk-rep-remove, .evk-rep-handle';

    function przelacz(lang) {
        var $grupy = $('.evk-tl-grupa');
        if (!$grupy.length) return;
        var obcy = lang !== baza($grupy.first());
        if (!obcy) zatrzymajEdytory($grupy);
        $grupy.each(function () {
            var $g = $(this);
            $g.attr('data-evk-jezyk', lang).toggleClass('evk-tl-obcy', obcy);
            $g.find(NIEAKTYWNE).each(function () { this.inert = obcy; });
            $g.children('.evk-tl-przelacznik').find('.evk-tl-jezyk').each(function () {
                var wcisniety = this.getAttribute('data-lang') === lang;
                this.setAttribute('aria-pressed', wcisniety ? 'true' : 'false');
                $(this).toggleClass('button-primary', wcisniety);
            });
        });
        if (obcy) {
            odswiezPodglady($grupy);
            pokazZakladki($grupy);
            uruchomEdytory($grupy);
        }
        liczPozniej();
    }

    $(document).on('click', '.evk-tl-jezyk', function () {
        przelacz(String(this.getAttribute('data-lang') || ''));
    });

    // Wiersz, akordeon, zakładka odsłonięte w widoku języka → edytory w nich.
    $(document).on('click', '.evk-rep-row-toggle, .evk-rep-row-title, .evk-s-acc-head, .evk-s-tab', function () {
        var $g = $(this).closest('.evk-tl-grupa.evk-tl-obcy');
        if ($g.length) setTimeout(function () { uruchomEdytory($g); }, 0);
    });

    // ── Kopiuj z polskiego ──
    $(document).on('click', '.evk-tl-kopiuj', function () {
        var $p = $(this).closest('.evk-tl-pole'), typ = typPola($p);
        var v = plWartosc($p);
        if (tekst(twinWartosc($p), typ) && tekst(twinWartosc($p), typ) !== tekst(v, typ)
            && !window.confirm('Zastąpić tłumaczenie tekstem oryginału?')) return;
        ustawTwin($p, v);
        $p.attr('data-zmienione', '1').removeAttr('data-sprawdz');
        liczPozniej();
    });

    // ── Sprawdzone: tłumaczenie pasuje do bieżącego oryginału ──
    $(document).on('click', '.evk-tl-sprawdzone', function () {
        var $p = $(this).closest('.evk-tl-pole');
        var $z = $p.find('.evk-tl-zrodlo');
        if ($z.attr('data-pierwotne') === undefined) $z.attr('data-pierwotne', $z.val());
        $z.val('teraz');   // serwer wpisze skrót bieżącego oryginału przy zapisie
        $p.removeAttr('data-sprawdz data-ai');
        $p.find('.evk-tl-kopiuj').trigger('focus');   // przycisk znika — fokus nie może przepaść
    });

    // ── Tłumacz zmienił tłumaczenie → przy zapisie źródło = bieżący oryginał ──
    $(document).on('input change evk-tl-edytor', '.evk-tl-pole .evk-tl-wejscie', function () {
        var $p = $(this).closest('.evk-tl-pole');
        $p.attr('data-zmienione', '1').removeAttr('data-sprawdz');
        /* Poprawka po wpisie AI: tłumacz przejrzał tekst — bez znacznika AI. */
        var $z = $p.find('.evk-tl-zrodlo');
        if ($z.val() === 'ai') { $z.val($z.attr('data-pierwotne') || ''); $p.removeAttr('data-ai'); }
        liczPozniej();
    });

    // ── Oryginał zmienił się na ekranie → niezmienione tłumaczenia do sprawdzenia ──
    $(document).on('input change evk-tl-edytor', '.evk-tl-pl', function (e) {
        var $cel = $(e.target);
        if ($cel.closest('.evk-rep-link').length && !$cel.hasClass('evk-rep-link-title')) return;   // adres i cel linku to nie tekst
        $(this).closest('.evk-tl-tak').children('.evk-tl-pole').each(function () {
            var $p = $(this);
            if ($p.attr('data-zmienione') || !tekst(twinWartosc($p), typPola($p))) return;
            $p.attr('data-sprawdz', '1');
            var $z = $p.find('.evk-tl-zrodlo');
            if ($z.val() === 'teraz') $z.val($z.attr('data-pierwotne') || '');
        });
        liczPozniej();
    });

    $(document).on('click', '.evk-rep-add, .evk-rep-remove', liczPozniej);

    // ── Przetłumacz (AI) (1.75.0) ──
    /* Tłumacza podaje wtyczka od języków (filtr `evk_fields_tl_ai`, dane w
       window.evkRepTlAi). Wynik trafia do pola, zapis zostaje ręczny
       („Zaktualizuj”); źródło `ai` daje przy zapisie znacznik „AI — do
       sprawdzenia”. Kontekst: wszystkie pola języka na ekranie, z obecnymi
       tłumaczeniami — model trzyma się słownictwa reszty. */
    var AI = window.evkRepTlAi || null;
    var trwaAi = false;

    function kontekstAi(lang) {
        var lista = [];
        $('.evk-tl-pole[data-lang="' + lang + '"]').each(function () {
            var $p = $(this), typ = typPola($p);
            var pl = plWartosc($p);
            if (!tekst(pl, typ)) return;
            lista.push({ $p: $p, pl: pl, tl: twinWartosc($p), opis: String($p.attr('data-opis') || '') });
        });
        return lista;
    }

    function porcjeAi(lista) {
        var out = [], biez = [], suma = 0, enc = new TextEncoder();
        lista.forEach(function (x) {
            var b = enc.encode(x.pl).length;
            if (biez.length && (biez.length >= AI.porcja || suma + b > AI.znaki)) { out.push(biez); biez = []; suma = 0; }
            biez.push(x);
            suma += b;
        });
        if (biez.length) out.push(biez);
        return out;
    }

    function wpiszAi($p, v) {
        var $z = $p.find('.evk-tl-zrodlo');
        if ($z.attr('data-pierwotne') === undefined) $z.attr('data-pierwotne', $z.val());
        ustawTwin($p, v);
        $z.val('ai');
        $p.attr('data-ai', '1').removeAttr('data-sprawdz');
    }

    /* Porcja do serwera → {wpisane, pamiec, bezZmian, odrzucone, pominiete, blad}. */
    function tlumaczAi(lang, lista, doTl) {
        var kontekst = lista.map(function (x) { return { el: 'evk_fields', opis: x.opis, pole: '', poz: 0, pl: x.pl, tl: doTl.indexOf(x) === -1 ? x.tl : '' }; });
        var w = { wpisane: 0, pamiec: 0, bezZmian: 0, odrzucone: 0, pominiete: 0, blad: '' };
        var partie = porcjeAi(doTl);
        var krok = function (i) {
            if (i >= partie.length) return $.Deferred().resolve(w).promise();
            var teksty = {}, miejsca = {};
            partie[i].forEach(function (x, j) {
                teksty['k' + (j + 1)] = { n: lista.indexOf(x) + 1, bylo: x.tl };
                miejsca['k' + (j + 1)] = x;
            });
            return $.post(AI.ajax, { action: 'evk_tl_ai_pola', nonce: AI.nonce, post_id: AI.post, lang: lang,
                kontekst: JSON.stringify(kontekst), teksty: JSON.stringify(teksty) }).then(function (r) {
                if (r === -1 || r === '-1' || r === 0 || r === '0') { w.blad = 'Sesja wygasła albo brak uprawnień — przeładuj stronę.'; return w; }
                if (!r || !r.success) { w.blad = (r && typeof r.data === 'string' && r.data) || 'Serwer odmówił.'; return w; }
                var d = r.data || {};
                $.each(d.tlumaczenia || {}, function (k, v) {
                    var x = miejsca[k];
                    if (!x || typeof v !== 'string') return;
                    /* Oryginał albo pole zmienione w trakcie — pisanie wygrywa. */
                    if (plWartosc(x.$p) !== x.pl || twinWartosc(x.$p) !== x.tl) return;
                    wpiszAi(x.$p, v);
                    kontekst[lista.indexOf(x)].tl = v;
                    w.wpisane++;
                    if (d.zrodla && (d.zrodla[k] === 'pamiec' || d.zrodla[k] === 'wynik')) w.pamiec++;
                });
                w.bezZmian += (d.bez_zmian || []).length;
                w.odrzucone += (d.odrzucone || []).length;
                w.pominiete += (d.pominiete || []).length;
                if (d.blad) {
                    w.blad = d.czekaj ? 'Dostawca prosi o przerwę — spróbuj za ' + d.czekaj + ' s.' : String(d.blad);
                    if (d.stop || d.czekaj) return w;
                }
                return krok(i + 1);
            }, function () { w.blad = 'Brak połączenia z serwerem.'; return w; });
        };
        return krok(0);
    }

    function opisAi(w, jeden) {
        var model = AI.model ? ' · ' + AI.model : '';
        if (jeden) {
            if (w.wpisane) return 'Wpisane (' + (w.pamiec ? 'z pamięci' : 'AI') + model + ') — sprawdź i zapisz wpis.';
            if (w.blad) return w.blad;
            if (w.bezZmian) return 'AI zwróciło ten sam tekst — bez zmian.';
            if (w.odrzucone) return 'Tłumaczenie odrzucone: znaczniki HTML, tagi {…} albo shortcody nie zgadzają się z oryginałem.';
            if (w.pominiete) return 'Tego tekstu AI nie tłumaczy (sam tag danych dynamicznych albo bez liter).';
            return 'Brak tłumaczenia.';
        }
        var cz = [];
        if (w.wpisane) cz.push('wpisane ' + w.wpisane + (w.pamiec ? ' (z pamięci ' + w.pamiec + ')' : ''));
        if (w.bezZmian) cz.push('bez zmian ' + w.bezZmian);
        if (w.odrzucone) cz.push('odrzucone ' + w.odrzucone);
        if (w.pominiete) cz.push('pominięte ' + w.pominiete);
        return (cz.length ? cz.join(', ') : 'nic nie wpisane') + '.' + (w.blad ? ' ' + w.blad : '') + (w.wpisane ? ' Sprawdź i zapisz wpis.' : '');
    }

    function zajetyAi(tak, $b) {
        trwaAi = tak;
        $('.evk-tl-ai, .evk-tl-ai-grupa').prop('disabled', tak);
        if ($b) $b.attr('aria-busy', tak ? 'true' : 'false');
    }

    $(document).on('click', '.evk-tl-ai', function () {
        if (!AI || trwaAi) return;
        var $b = $(this), $p = $b.closest('.evk-tl-pole'), typ = typPola($p);
        var lang = String($p.attr('data-lang') || ''), L = lang.toUpperCase();
        var $stan = $p.find('.evk-tl-ai-stan');
        var lista = kontekstAi(lang);
        var x = lista.filter(function (e) { return e.$p[0] === $p[0]; })[0];
        if (!x) { $stan.text('Brak tekstu w oryginale.'); return; }
        if (tekst(x.tl, typ) && !window.confirm('Zastąpić obecne tłumaczenie ' + L + '?\n\n„' + tekst(x.tl, typ).slice(0, 200) + '”')) return;
        zajetyAi(true, $b);
        $stan.text('Tłumaczę na ' + L + '…');
        tlumaczAi(lang, lista, [x]).then(function (w) {
            $stan.text(opisAi(w, true));
            zajetyAi(false, $b);
            liczPozniej();
        });
    });

    $(document).on('click', '.evk-tl-ai-grupa', function () {
        if (!AI || trwaAi) return;
        var $b = $(this), $g = $b.closest('.evk-tl-grupa');
        var lang = String($g.attr('data-evk-jezyk') || ''), L = lang.toUpperCase();
        var $stan = $b.siblings('.evk-tl-ai-grupa-stan');
        if (lang === baza($g)) return;
        var lista = kontekstAi(lang);
        var puste = lista.filter(function (x) { return x.$p.closest('.evk-tl-grupa')[0] === $g[0] && !tekst(x.tl, typPola(x.$p)); });
        if (!puste.length) { $stan.text(L + ': wszystkie pola tej grupy są już przetłumaczone.'); return; }
        zajetyAi(true, $b);
        $stan.text('Tłumaczę na ' + L + '… (' + puste.length + ')');
        tlumaczAi(lang, lista, puste).then(function (w) {
            $stan.text(L + ': ' + opisAi(w, false));
            zajetyAi(false, $b);
            liczPozniej();
        });
    });

    // Edytory oryginału (WYSIWYG): pisanie w ramce nie daje zdarzeń na stronie.
    function podepnij(ed) {
        if (!ed || ed.evkTl) return;
        ed.evkTl = true;
        var el = ed.getElement ? ed.getElement() : null;
        if (el && $(el).hasClass('evk-tl-wysiwyg')) return;   // pola języków mają własny setup
        ed.on('change keyup undo redo', function () {
            var e2 = ed.getElement ? ed.getElement() : null;
            if (e2) $(e2).trigger('evk-tl-edytor');
        });
    }
    if (window.tinymce && window.tinymce.on) {
        window.tinymce.on('AddEditor', function (e) { podepnij(e.editor); });
    }

    // Dodanie termu (edit-tags.php) idzie AJAX-em, a WordPress czyści potem tylko
    // WIDOCZNE pola. W widoku języka oryginał jest ukryty, więc przeszedłby do
    // następnego termu. Po udanym dodaniu: pola języków i ukryty oryginał puste, widok oryginału.
    $(document).ajaxSuccess(function (e, xhr, o) {
        if (!o || typeof o.data !== 'string' || o.data.indexOf('action=add-tag') === -1) return;
        var $f = $('#addtag');
        var $g = $f.find('.evk-tl-grupa');
        if (!$g.length) return;
        zatrzymajEdytory($f);
        $f.find('.evk-tl-pole').removeAttr('data-zmienione data-sprawdz').find('.evk-tl-wejscie, input[type=hidden]').val('');
        $f.find('.evk-tl-zrodlo').removeAttr('data-pierwotne');
        $f.find('.evk-tl-pl').find('input[type=text], textarea').filter(':hidden').val('');
        przelacz(baza($g.first()));
    });

    // Pole wymagane w ukrytym widoku blokowałoby zapis bez widocznego powodu —
    // przeglądarka nie umie pokazać błędu w polu, którego nie widać.
    document.addEventListener('invalid', function (e) {
        var t = e.target;
        if (!t || !t.closest || t.closest('.evk-tl-pole')) return;
        var g = t.closest('.evk-tl-grupa.evk-tl-obcy');
        if (g) przelacz(baza($(g)));
    }, true);

    $(function () {
        if (!$('.evk-tl-grupa').length) return;
        if (window.tinymce && window.tinymce.editors) $.each(window.tinymce.editors, function (i, ed) { podepnij(ed); });
        liczPozniej();
    });
})(jQuery);
