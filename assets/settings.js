/* Evoke FIELDS — builder stron ustawień */
(function ($) {
    'use strict';

    // Transliteracja: usuwa tylko znaki diakrytyczne (ł→l, ą→a, ż→z…), nie całe litery.
    function evkTranslit(str) {
        str = String(str).replace(/[łŁđĐøØßæÆœŒþ]/g, function (c) {
            return { 'ł': 'l', 'Ł': 'L', 'đ': 'd', 'Đ': 'D', 'ø': 'o', 'Ø': 'O', 'ß': 'ss', 'æ': 'ae', 'Æ': 'AE', 'œ': 'oe', 'Œ': 'OE', 'þ': 'th' }[c];
        });
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    /**
     * Buduje element jQuery z szablonu HTML (string) z podmianą tokenów.
     * evkSpTpl.page i evkSpTpl.tab są wstrzykiwane przez PHP w admin_footer.
     */
    function build(tpl, repl) {
        var html = tpl;
        for (var k in repl) {
            if (Object.prototype.hasOwnProperty.call(repl, k)) {
                html = html.split(k).join(repl[k]);
            }
        }
        return $($.parseHTML($.trim(html), document, true));
    }

    // ── Dodaj stronę ustawień ──
    $(document).on('click', '.evk-sp-add-page', function () {
        if (typeof evkSpTpl === 'undefined' || !evkSpTpl.page) {
            console.error('[EVK] evkSpTpl.page nie jest dostępne.');
            return;
        }
        var $el = build(evkSpTpl.page, { '__PINDEX__': 'p' + Date.now() });
        $('#evk-settings-pages').append($el);
    });

    // ── Usuń stronę ──
    $(document).on('click', '.evk-sp-remove-page', function () {
        if (!window.confirm('Usunąć tę stronę ustawień?')) return;
        $(this).closest('.evk-settings-page').remove();
    });

    // ── Dodaj zakładkę ──
    // .evk-sp-tabs jest zagnieżdżone wewnątrz .evk-b-group-body → używamy .find()
    $(document).on('click', '.evk-sp-add-tab', function () {
        if (typeof evkSpTpl === 'undefined' || !evkSpTpl.tab) {
            console.error('[EVK] evkSpTpl.tab nie jest dostępne.');
            return;
        }
        var $card  = $(this).closest('.evk-settings-page');
        var pindex = $card.attr('data-pindex');
        var $tabs  = $card.find('.evk-sp-tabs').first();
        var $el    = build(evkSpTpl.tab, {
            '__PINDEX__': pindex,
            '__TINDEX__': 't' + Date.now()
        });
        $tabs.append($el);
    });

    // ── Usuń zakładkę ──
    $(document).on('click', '.evk-sp-remove-tab', function () {
        $(this).closest('.evk-sp-tab').remove();
    });

    // ── Slug → bezpieczny format ──
    $(document).on('input', '.evk-sp-slug', function () {
        var v = evkTranslit(this.value).toLowerCase().replace(/[^a-z0-9\-]+/g, '-').replace(/^-+/, '');
        if (v !== this.value) this.value = v;
    });

})(jQuery);
