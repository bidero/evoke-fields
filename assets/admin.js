/* Evoke FIELDS — metabox (edytor wpisu) */
(function ($) {
    'use strict';

    function tokenFor(depth) {
        depth = parseInt(depth, 10) || 0;
        return depth <= 0 ? '__INDEX__' : '__IDX' + depth + '__';
    }

    // ── WYSIWYG: inicjalizacja edytorów w danym zakresie ──
    function initWysiwyg($scope) {
        if (typeof wp === 'undefined' || !wp.editor) return;
        $scope.find('.evk-wysiwyg-area').each(function () {
            var id = this.id;
            if (!id) return;
            // Nie inicjalizuj ponownie
            if (typeof tinymce !== 'undefined' && tinymce.get(id)) return;
            wp.editor.initialize(id, {
                tinymce: {
                    wpautop: true,
                    plugins: 'charmap colorpicker directionality fullscreen hr image lists media paste tabfocus textcolor wordpress wpautoresize wpeditimage wpemoji wplink wptextpattern',
                    toolbar1: 'bold italic | bullist numlist | blockquote | alignleft aligncenter | link unlink | wp_more | fullscreen',
                },
                quicktags: true,
            });
        });
    }

    // ── WYSIWYG: usunięcie edytorów przed usunięciem wiersza ──
    function removeWysiwyg($scope) {
        if (typeof wp === 'undefined' || !wp.editor) return;
        $scope.find('.evk-wysiwyg-area').each(function () {
            if (this.id) wp.editor.remove(this.id);
        });
    }

    // ── Zapis TinyMCE → textarea przed submitem ──
    $(document).on('submit', 'form#post', function () {
        if (typeof wp !== 'undefined' && wp.editor) wp.editor.save();
    });

    // ── Repeater: dodaj wiersz ──
    $(document).on('click', '.evk-rep-add', function () {
        var $rep   = $(this).closest('.evk-rep');
        var token  = tokenFor($rep.attr('data-depth'));
        var tpl    = $rep.children('.evk-rep-template').html();
        var uid    = Date.now();
        var html   = tpl.split(token).join(uid);
        var $row   = $(html);
        $rep.children('.evk-rep-rows').append($row);
        syncRowTitle($row);
        initSortable();
        initWysiwyg($row);
        evkEvalAll($row);
    });

    // ── Repeater: usuń wiersz ──
    $(document).on('click', '.evk-rep-remove', function () {
        var $row = $(this).closest('.evk-rep-row');
        removeWysiwyg($row);
        $row.remove();
    });

    // ── Repeater: zwijanie wiersza ──
    $(document).on('click', '.evk-rep-row-toggle, .evk-rep-row-title', function () {
        $(this).closest('.evk-rep-row').toggleClass('collapsed');
    });

    // ── Media picker ──
    $(document).on('click', '.evk-rep-image-pick', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.evk-rep-image');
        var frame = wp.media({ title: 'Wybierz obraz', button: { text: 'Użyj' }, multiple: false });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            $field.find('.evk-rep-image-id').val(att.id);
            var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            $field.find('.evk-rep-image-preview').html('<img src="' + src + '" alt="">');
            $field.find('.evk-rep-image-clear').show();
        });
        frame.open();
    });

    $(document).on('click', '.evk-rep-image-clear', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.evk-rep-image');
        $field.find('.evk-rep-image-id').val('');
        $field.find('.evk-rep-image-preview').empty();
        $(this).hide();
    });

    // ── File picker (dowolny typ pliku) ──
    $(document).on('click', '.evk-rep-file-pick', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.evk-rep-file');
        var frame = wp.media({ title: 'Wybierz plik', button: { text: 'Użyj' }, multiple: false });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            $field.find('.evk-rep-file-id').val(att.id);
            var name = att.filename || att.url;
            $field.find('.evk-rep-file-preview').html(
                '<span class="dashicons dashicons-media-default"></span> ' +
                '<a href="' + att.url + '" target="_blank" rel="noopener"></a>'
            ).find('a').text(name);
            $field.find('.evk-rep-file-clear').show();
        });
        frame.open();
    });

    $(document).on('click', '.evk-rep-file-clear', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.evk-rep-file');
        $field.find('.evk-rep-file-id').val('');
        $field.find('.evk-rep-file-preview').empty();
        $(this).hide();
    });

    // ── Galeria: dodaj obrazy (multi) ──
    var evkGalSeq = 0;
    $(document).on('click', '.evk-gallery-add', function (e) {
        e.preventDefault();
        var $g     = $(this).closest('.evk-gallery');
        var $items = $g.children('.evk-gallery-items');
        var tpl    = $g.children('.evk-gallery-tpl').html();
        if (!tpl) return;
        var frame = wp.media({ title: 'Dodaj do galerii', button: { text: 'Dodaj' }, multiple: true, library: { type: 'image' } });
        frame.on('select', function () {
            frame.state().get('selection').each(function (att) {
                var a   = att.toJSON();
                var src = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
                var idx = 'g' + Date.now() + '_' + (evkGalSeq++);
                var html = tpl.split('__GIDX__').join(idx).split('__IMG__').join(a.id).split('__SRC__').join(src);
                $items.append($(html));
            });
            initGallerySortable();
        });
        frame.open();
    });

    $(document).on('click', '.evk-gallery-remove', function () {
        $(this).closest('.evk-gallery-item').remove();
    });

    function initGallerySortable() {
        if (!$.fn.sortable) return;
        $('.evk-gallery-items').each(function () {
            if ($(this).data('evk-sortable')) return;
            $(this).data('evk-sortable', true);
            $(this).sortable({ items: '> .evk-gallery-item', placeholder: 'evk-gallery-placeholder', forcePlaceholderSize: true });
        });
    }
    $(initGallerySortable);

    // ── Relacja (relationship): wyszukiwarka + wybór ──
    var evkRelTimer = null;
    $(document).on('input', '.evk-rel-search', function () {
        var $input   = $(this);
        var $rel     = $input.closest('.evk-rel');
        var $results = $rel.find('.evk-rel-results').first();
        var term     = $.trim($input.val());
        clearTimeout(evkRelTimer);
        if (term.length < 2) { $results.empty().hide(); return; }
        evkRelTimer = setTimeout(function () {
            if (typeof evkRel === 'undefined') return;
            $.getJSON(evkRel.url, {
                action: 'evk_rel_search',
                nonce: evkRel.nonce,
                s: term,
                source: $rel.attr('data-source') || 'post',
                post_types: $rel.attr('data-post-types') || 'post',
                roles: $rel.attr('data-roles') || ''
            }).done(function (res) {
                $results.empty();
                if (!res || !res.success || !res.data || !res.data.length) {
                    $results.html('<div class="evk-rel-noresult">Brak wyników</div>').show();
                    return;
                }
                res.data.forEach(function (p) {
                    var $r = $('<div class="evk-rel-result"></div>').attr('data-id', p.id);
                    $r.append($('<span class="evk-rel-result-title"></span>').text(p.title));
                    $r.append($('<span class="evk-rel-result-type"></span>').text(p.type));
                    $results.append($r);
                });
                $results.show();
            });
        }, 250);
    });

    $(document).on('click', '.evk-rel-result', function () {
        var $rel      = $(this).closest('.evk-rel');
        var id        = String($(this).attr('data-id'));
        var title     = $(this).find('.evk-rel-result-title').text();
        var $selected = $rel.children('.evk-rel-selected');
        if ($selected.find('.evk-rel-item[data-id="' + id + '"]').length) return; // już dodany
        if (String($rel.attr('data-multiple')) !== '1') $selected.empty();        // single = jeden
        var html  = $rel.children('.evk-rel-tpl').html().split('__RID__').join(id);
        var $item = $(html);
        $item.find('.evk-rel-title').text(title);
        $selected.append($item);
        $rel.find('.evk-rel-search').val('');
        $rel.find('.evk-rel-results').empty().hide();
        initRelSortable();
    });

    $(document).on('click', '.evk-rel-remove', function () {
        $(this).closest('.evk-rel-item').remove();
    });

    // Klik poza wyszukiwarką → schowaj wyniki
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.evk-rel-search-wrap').length) $('.evk-rel-results').hide();
    });

    function initRelSortable() {
        if (!$.fn.sortable) return;
        $('.evk-rel-selected').each(function () {
            if ($(this).data('evk-sortable')) return;
            $(this).data('evk-sortable', true);
            $(this).sortable({ handle: '.evk-rel-handle', items: '> .evk-rel-item', placeholder: 'evk-rel-placeholder', forcePlaceholderSize: true });
        });
    }
    $(initRelSortable);

    // ── Suwak: synchronizacja range ↔ number ──
    $(document).on('input change', '.evk-rep-range input[type=range]', function () {
        $(this).siblings('.evk-rep-range-value').val(this.value).trigger('input');
    });
    $(document).on('input change', '.evk-rep-range-value', function () {
        $(this).siblings('input[type=range]').val(this.value);
    });

    // ── Kafelki wyboru: aktualny stan wizualny ──
    $(document).on('change', '.evk-rep-button-group input[type=radio], .evk-rep-image-select input[type=radio]', function () {
        var name = this.name;
        $('input[type=radio]').filter(function () { return this.name === name; }).closest('label').removeClass('is-selected');
        $(this).closest('label').addClass('is-selected');
    });

    // ── Sortowanie wierszy ──
    function initSortable() {
        if (!$.fn.sortable) return;
        $('.evk-rep-rows').each(function () {
            if ($(this).data('evk-sortable')) return;
            $(this).data('evk-sortable', true);
            var depth = $(this).closest('.evk-rep').attr('data-depth') || '0';
            $(this).sortable({ handle: '.evk-rep-h' + depth, items: '> .evk-rep-row', placeholder: 'evk-rep-placeholder', forcePlaceholderSize: true });
        });
    }
    $(initSortable);

    // ── Zakładki ──
    $(document).on('click', '.evk-s-tab', function () {
        var $s = $(this).closest('.evk-s');
        var p  = String($(this).data('tab'));
        $s.children('.evk-s-tabs').children('.evk-s-tab').removeClass('active');
        $(this).addClass('active');
        var $panels = $s.children('.evk-s-panels').children('.evk-s-panel');
        $panels.removeClass('active');
        $panels.filter('[data-panel="' + p + '"]').addClass('active');
    });

    // ── Akordeon ──
    $(document).on('click', '.evk-s-acc-head', function (e) {
        e.preventDefault();
        $(this).closest('.evk-s-acc').toggleClass('open');
    });

    // ── Tytuł wiersza z wybranego/pierwszego pola ──
    function syncRowTitle($row) {
        var rowEl = $row[0];
        var tf    = $row.closest('.evk-rep').attr('data-title-field') || '';
        var $body = $row.children('.evk-rep-row-body');
        function own() { return $(this).closest('.evk-rep-row')[0] === rowEl; }
        function valOf(key) {
            var $f = $body.find('.evk-s-field[data-key="' + key + '"]').filter(own).find('input,textarea,select').first();
            return $f.length ? $.trim($f.val()) : '';
        }
        var v = '';
        if (tf.indexOf('{') !== -1) {                       // szablon, np. {tytul} | {cena}
            v = $.trim(tf.replace(/\{([a-zA-Z0-9_]+)\}/g, function (m, k) { return valOf(k); }));
        } else if (tf) {
            v = valOf(tf);
        }
        if (!v) {                                           // fallback: pierwsze pole tekstowe
            var $src = $body.find('input[type=text],input[type=email],input[type=url],textarea').filter(own).first();
            v = $src.length ? $.trim($src.val()) : '';
        }
        $row.children('.evk-rep-row-head').find('.evk-rep-row-title').first().text(v || 'Wiersz');
    }
    $(document).on('input', '.evk-rep-row-body input, .evk-rep-row-body textarea, .evk-rep-row-body select', function () {
        syncRowTitle($(this).closest('.evk-rep-row'));
    });

    $(function () {
        $('.evk-rep-row').each(function () { syncRowTitle($(this)); });
        // Inicjalizuj WYSIWYG w istniejących wierszach repeaterów
        initWysiwyg($('.evk-rep-rows'));
        $('.evk-rep-button-group input:checked, .evk-rep-image-select input:checked').closest('label').addClass('is-selected');
    });

    /* ── Przełącznik (toggle) — synchronizacja etykiet ON/OFF ── */
    function syncToggleLabel($cb) {
        var $wrap = $cb.closest('.evk-rep-toggle');
        $wrap.toggleClass('is-on', $cb.is(':checked'));
    }
    $(document).on('change', '.evk-rep-toggle-input', function () {
        syncToggleLabel($(this));
    });
    $(function () {
        $('.evk-rep-toggle-input').each(function () { syncToggleLabel($(this)); });
    });

    /* ── Logika warunkowa — runtime pokaż/ukryj ── */
    // Odczyt bieżącej wartości pola źródłowego z jego wrappera .evk-s-field.
    function evkReadFieldValue($wrap) {
        if ($wrap.is('.evk-rep-field--repeater')) return '';
        var $tog = $wrap.find('.evk-rep-toggle-input').first();
        if ($tog.length) {
            if ($tog.prop('checked')) return $tog.val();
            var $hid = $wrap.find('input[type=hidden]').first();
            return $hid.length ? $hid.val() : '';
        }
        var $radios = $wrap.find('input[type=radio]');
        if ($radios.length) {
            var $ck = $radios.filter(':checked').first();
            return $ck.length ? $ck.val() : '';
        }
        var $cb = $wrap.find('input[type=checkbox]').first();
        if ($cb.length) {
            return $cb.prop('checked') ? ($cb.val() || '1') : '';
        }
        var $sel = $wrap.find('select').first();
        if ($sel.length) {
            var sv = $sel.val();
            return sv == null ? '' : ($.isArray(sv) ? sv.join(',') : sv);
        }
        var $inp = $wrap.find('input, textarea').first();
        return $inp.length ? ($inp.val() || '') : '';
    }

    function evkEvalRule(val, op, target) {
        val    = String(val == null ? '' : val);
        target = String(target == null ? '' : target);
        switch (op) {
            case '==':        return val === target;
            case '!=':        return val !== target;
            case 'contains':  return val.indexOf(target) !== -1;
            case 'empty':     return val === '';
            case 'not_empty': return val !== '';
        }
        return true;
    }

    function evkEvalConditions($field) {
        var raw = $field.attr('data-evk-cond');
        if (!raw) return;
        var cond;
        try { cond = JSON.parse(raw); } catch (e) { return; }
        if (!cond || !cond.rules || !cond.rules.length) return;
        var $scope = $field.closest('.evk-s');
        if (!$scope.length) return;
        var results = $.map(cond.rules, function (r) {
            // Pole źródłowe = rodzeństwo w TYM samym .evk-s (nie zagnieżdżone w głębszym repeaterze).
            var $src = $scope.find('.evk-s-field[data-key="' + r.field + '"]').filter(function () {
                return $(this).closest('.evk-s')[0] === $scope[0];
            }).first();
            if (!$src.length || $src[0] === $field[0]) return false;
            return evkEvalRule(evkReadFieldValue($src), r.op, r.value);
        });
        var show = cond.relation === 'any'
            ? results.indexOf(true) !== -1
            : results.indexOf(false) === -1;
        $field.toggleClass('evk-cond-hidden', !show);
    }

    function evkEvalAll($scope) {
        ($scope && $scope.length ? $scope : $(document)).find('.evk-s-field[data-evk-cond]').each(function () {
            evkEvalConditions($(this));
        });
    }
    window.evkEvalAll = evkEvalAll;

    // Każda zmiana pola → przelicz wszystkie warunki (tanio, obsługuje zagnieżdżenia).
    $(document).on('change input', '.evk-s input, .evk-s select, .evk-s textarea', function () {
        evkEvalAll();
    });
    $(function () { evkEvalAll(); });

    /* ── Pole obliczeniowe (calc) — podgląd wyniku na żywo ──
       Lustro parsera z includes/calc.php (te same reguły: + - * / (), {klucz},
       SUM/COUNT/AVG/MIN/MAX(repeater.subpole), przecinek dziesiętny OK).
       To tylko PODGLĄD — wartość kanoniczną liczy serwer przy zapisie. */

    function evkCalcNum(v) {
        if (v == null) return 0;
        var n = parseFloat(String(v).replace(',', '.'));
        return isFinite(n) ? n : 0;
    }

    function evkCalcTokens(f) {
        var out = [], i = 0, m;
        var reAgg = /^(SUM|COUNT|AVG|MIN|MAX)\s*\(\s*([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\s*\)/i;
        var reNum = /^\d+(?:[\.,]\d+)?/;
        while (i < f.length) {
            var c = f[i];
            if (c === ' ' || c === '\t') { i++; continue; }
            if ('+-*/()'.indexOf(c) !== -1) { out.push(['op', c]); i++; continue; }
            if (c === '{') {
                var e = f.indexOf('}', i);
                if (e === -1) return null;
                var key = f.slice(i + 1, e).trim();
                if (!/^[a-zA-Z0-9_]+$/.test(key)) return null;
                out.push(['field', key.toLowerCase()]);
                i = e + 1;
                continue;
            }
            var rest = f.slice(i);
            if ((m = rest.match(reAgg))) { out.push(['agg', m[1].toUpperCase(), m[2].toLowerCase(), (m[3] || '').toLowerCase()]); i += m[0].length; continue; }
            if ((m = rest.match(reNum))) { out.push(['num', parseFloat(m[0].replace(',', '.'))]); i += m[0].length; continue; }
            return null;
        }
        return out;
    }

    // Parser zejść rekurencyjnych: expr → term → factor. null = błąd.
    function evkCalcEval(formula, fieldCb, aggCb) {
        formula = $.trim(formula || '');
        if (!formula) return null;
        var t = evkCalcTokens(formula);
        if (!t || !t.length) return null;
        var pos = { i: 0 };
        var v = evkCalcExpr(t, pos, fieldCb, aggCb);
        return (v === null || pos.i !== t.length) ? null : v;
    }
    function evkCalcExpr(t, p, fcb, acb) {
        var v = evkCalcTerm(t, p, fcb, acb);
        if (v === null) return null;
        while (p.i < t.length && t[p.i][0] === 'op' && (t[p.i][1] === '+' || t[p.i][1] === '-')) {
            var op = t[p.i][1]; p.i++;
            var r = evkCalcTerm(t, p, fcb, acb);
            if (r === null) return null;
            v = op === '+' ? v + r : v - r;
        }
        return v;
    }
    function evkCalcTerm(t, p, fcb, acb) {
        var v = evkCalcFactor(t, p, fcb, acb);
        if (v === null) return null;
        while (p.i < t.length && t[p.i][0] === 'op' && (t[p.i][1] === '*' || t[p.i][1] === '/')) {
            var op = t[p.i][1]; p.i++;
            var r = evkCalcFactor(t, p, fcb, acb);
            if (r === null) return null;
            if (op === '/') {
                if (Math.abs(r) < 1e-12) return null;
                v = v / r;
            } else v = v * r;
        }
        return v;
    }
    function evkCalcFactor(t, p, fcb, acb) {
        if (p.i >= t.length) return null;
        var tok = t[p.i];
        if (tok[0] === 'op' && tok[1] === '-') { p.i++; var n = evkCalcFactor(t, p, fcb, acb); return n === null ? null : -n; }
        if (tok[0] === 'num')   { p.i++; return tok[1]; }
        if (tok[0] === 'field') { p.i++; return fcb(tok[1]); }
        if (tok[0] === 'agg')   { p.i++; return acb(tok[1], tok[2], tok[3]); }
        if (tok[0] === 'op' && tok[1] === '(') {
            p.i++;
            var v = evkCalcExpr(t, p, fcb, acb);
            if (v === null) return null;
            if (p.i >= t.length || t[p.i][0] !== 'op' || t[p.i][1] !== ')') return null;
            p.i++;
            return v;
        }
        return null;
    }

    // Wartość inputa jak liczy ją serwer (checkbox → 1/0, reszta → num z .val()).
    function evkCalcInputVal($i) {
        if (!$i.length) return 0;
        if ($i.is(':checkbox')) return $i.is(':checked') ? 1 : 0;
        return evkCalcNum($i.first().val());
    }
    function evkCalcNotTemplate() {
        return $(this).closest('.evk-rep-template, .evk-gallery-tpl').length === 0;
    }

    // Agregat: wiersze repeatera identyfikowane wzorcami nazw inputów subpola.
    // (podgląd; serwer liczy z zapisanych wierszy, więc rozjazd = tylko chwilowy)
    function evkCalcAggregate(func, $candidates) {
        var rowsSeen = [], vals = [];
        $candidates.each(function () {
            var $inp = $(this);
            var row  = $inp.closest('.evk-rep-row')[0] || null;
            if (row && rowsSeen.indexOf(row) === -1) rowsSeen.push(row);
            var raw = $inp.is(':checkbox') ? ($inp.is(':checked') ? '1' : '') : String($inp.val() == null ? '' : $inp.val());
            if ($.trim(raw) === '') return;
            vals.push(evkCalcNum(raw));
        });
        switch (func) {
            case 'COUNT': return vals.length; // COUNT(rep.sub) = niepuste wartości; COUNT(rep) liczony w aggCb
            case 'SUM':   return vals.reduce(function (a, b) { return a + b; }, 0);
            case 'AVG':   return vals.length ? vals.reduce(function (a, b) { return a + b; }, 0) / vals.length : 0;
            case 'MIN':   return vals.length ? Math.min.apply(null, vals) : 0;
            case 'MAX':   return vals.length ? Math.max.apply(null, vals) : 0;
        }
        return 0;
    }

    function evkCalcRecalcOne($calc) {
        var formula = $calc.attr('data-evk-formula') || '';
        var base    = $calc.attr('data-evk-base') || '';
        var $row    = $calc.closest('.evk-rep-row');
        var inRow   = $row.length > 0;

        var fieldCb = function (key) {
            if (inRow) {
                // Ten sam wiersz; pomijamy inne pola calc (zakaz łańcuchów na poziomie).
                var $ins = $row.find('[name$="[' + key + ']"]').not('.evk-rep-calc').filter(function () {
                    return $(this).closest('.evk-rep-row')[0] === $row[0];
                });
                return evkCalcInputVal($ins);
            }
            var $top = $('[name="' + base + '[' + key + ']"]').not('.evk-rep-calc').filter(evkCalcNotTemplate);
            return evkCalcInputVal($top);
        };

        var aggCb = function (func, rep, sub) {
            var subSel = sub ? '[name$="[' + sub + ']"]' : '';
            var $cand;
            if (inRow) {
                // Zagnieżdżony repeater w tym wierszu.
                $cand = $row.find('[name*="[' + rep + ']"]' + subSel).filter(evkCalcNotTemplate);
            } else {
                // Repeater-pole w tej grupie → grupa-repeater (meta/opcje) → gdziekolwiek.
                var sels = [
                    '[name^="' + base + '[' + rep + ']["]',
                    '[name^="' + rep + '["]',
                    '[name^="evk_opt[' + rep + ']["]',
                    '[name*="[' + rep + ']["]'
                ];
                for (var s = 0; s < sels.length; s++) {
                    $cand = $(sels[s] + subSel).filter(evkCalcNotTemplate);
                    if ($cand.length) break;
                }
            }
            if (!sub) {
                // COUNT(repeater): policz unikalne wiersze po dowolnym inpucie z nazwą repeatera.
                var rows = [];
                ($cand || $()).each(function () {
                    var r = $(this).closest('.evk-rep-row')[0];
                    if (r && rows.indexOf(r) === -1) rows.push(r);
                });
                return func === 'COUNT' ? rows.length : 0;
            }
            return evkCalcAggregate(func, $cand || $());
        };

        var v = evkCalcEval(formula, fieldCb, aggCb);
        if (v === null) { $calc.val(''); return; }
        var decAttr = $calc.attr('data-evk-decimals');
        var dec = decAttr === '' || decAttr == null ? 6 : Math.max(0, Math.min(6, parseInt(decAttr, 10) || 0));
        var s = v.toFixed(dec).replace(/\.?0+$/, '');
        if (s === '' || s === '-0') s = '0';
        $calc.val(s);
    }

    var evkCalcTimer = null;
    function evkCalcRecalcAll() {
        // Dwa przebiegi: najpierw calc w wierszach (żeby SUM po nich widziała świeże
        // wartości), potem calc poza wierszami — jak na serwerze.
        var $all = $('.evk-rep-calc[data-evk-formula]').filter(evkCalcNotTemplate);
        $all.filter(function () { return $(this).closest('.evk-rep-row').length > 0; }).each(function () { evkCalcRecalcOne($(this)); });
        $all.filter(function () { return $(this).closest('.evk-rep-row').length === 0; }).each(function () { evkCalcRecalcOne($(this)); });
    }
    function evkCalcSchedule() {
        clearTimeout(evkCalcTimer);
        evkCalcTimer = setTimeout(evkCalcRecalcAll, 120);
    }

    $(document).on('input change', 'input, select, textarea', function () {
        if ($(this).hasClass('evk-rep-calc')) return; // readonly — nie zapętlaj
        evkCalcSchedule();
    });
    // Dodanie/usunięcie wiersza zmienia agregaty.
    $(document).on('click', '.evk-rep-add, .evk-rep-remove, .evk-gallery-add', evkCalcSchedule);
    $(function () { evkCalcRecalcAll(); });

})(jQuery);
