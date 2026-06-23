/* Evoke FIELDS — builder */
(function ($) {
    'use strict';

    function evkTranslit(str) {
        str = String(str).replace(/[łŁđĐøØßæÆœŒþ]/g, function (c) {
            return { 'ł': 'l', 'Ł': 'L', 'đ': 'd', 'Đ': 'D', 'ø': 'o', 'Ø': 'O', 'ß': 'ss', 'æ': 'ae', 'Æ': 'AE', 'œ': 'oe', 'Œ': 'OE', 'þ': 'th' }[c];
        });
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    var BADGE = { tab: 'ZAKŁADKA', accordion: 'AKORDEON', heading: 'NAGŁÓWEK', repeater: 'REPEATER', taxonomy: 'TAX', image_select: 'IMAGE SELECT', button_group: 'BUTTONS', range: 'SUWAK', gallery: 'GALERIA', relationship: 'RELACJA' };

    function applyType($field) {
        var t = $field.children('.evk-b-field-grid').find('.evk-b-type').first().val();
        $field.toggleClass('is-opts',     t === 'select' || t === 'radio' || t === 'button_group' || t === 'image_select');
        $field.toggleClass('is-layout',   t === 'tab' || t === 'accordion' || t === 'heading');
        $field.toggleClass('is-repeater', t === 'repeater');
        $field.toggleClass('is-taxonomy', t === 'taxonomy');
        $field.toggleClass('is-image-select', t === 'image_select');
        $field.toggleClass('is-range',    t === 'range');
        $field.toggleClass('is-gallery',  t === 'gallery');
        $field.toggleClass('is-relationship', t === 'relationship');
        $field.attr('data-ftype', t); // steruje widocznością opcji pola (placeholder/affix/rows)
        $field.children('.evk-b-field-top').find('.evk-b-badge').first().text(BADGE[t] || '');
    }
    window.applyType = applyType;

    function initFields($scope) {
        ($scope || $(document)).find('.evk-b-field').each(function () { applyType($(this)); });
    }

    var TITLE_TYPES = ['text', 'textarea', 'email', 'url', 'number'];
    function syncTitleSelect($field) {
        var $wrap = $field.children('.evk-b-subfields-wrap');
        var $sel  = $wrap.children('.evk-b-title-row').find('.evk-b-title-field').first();
        if (!$sel.length) return;
        var cur = $sel.val(), found = false;
        $sel.empty().append($('<option>').val('').text('— pierwsze pole tekstowe (auto) —'));
        $wrap.children('.evk-b-subfields').children('.evk-b-field').each(function () {
            var $sf   = $(this);
            var type  = $sf.children('.evk-b-field-grid').find('.evk-b-type').first().val();
            if (TITLE_TYPES.indexOf(type) === -1) return;
            var key   = ($sf.children('.evk-b-field-grid').find('.evk-b-key').first().val() || '').trim();
            if (!key) return;
            var label = ($sf.children('.evk-b-field-top').find('.evk-b-fld-label').first().val() || '').trim() || key;
            $sel.append($('<option>').val(key).text(label));
            if (key === cur) found = true;
        });
        $sel.val(found ? cur : '');
    }

    $(document).on('click', '#evk-b-add-group', function () {
        var html = $('#evk-b-group-tpl').html().split('__GINDEX__').join(Date.now());
        var $g = $(html);
        $('#evk-b-groups').append($g);
        initFields($g);
        initSortable();
    });

    $(document).on('click', '.evk-b-group-remove', function () {
        if (!window.confirm('Usunąć całą grupę? (zapis dopiero po „Zapisz schemat")')) return;
        $(this).closest('.evk-b-group').remove();
    });

    $(document).on('click', '.evk-b-field-add', function () {
        var $g     = $(this).closest('.evk-b-group');
        var gindex = $g.attr('data-gindex');
        var html   = $('#evk-b-field-tpl').html()
            .split('__GINDEX__').join(gindex)
            .split('__FINDEX__').join(Date.now());
        var $f = $(html);
        $g.find('.evk-b-fields').first().append($f);
        applyType($f);
        initSortable();
    });

    $(document).on('click', '.evk-b-subfield-add', function () {
        var $field  = $(this).closest('.evk-b-field');
        var subBase = $field.attr('data-base') + '[sub_fields][' + Date.now() + ']';
        var html    = $('#evk-b-subfield-tpl').html().split('__SUBBASE__').join(subBase);
        var $sf     = $(html);
        $field.children('.evk-b-subfields-wrap').children('.evk-b-subfields').append($sf);
        applyType($sf);
        syncTitleSelect($field);
        initSortable();
    });

    $(document).on('click', '.evk-b-field-remove', function () {
        var $f   = $(this).closest('.evk-b-field');
        var $rep = $f.parent().closest('.evk-b-field');
        $f.remove();
        if ($rep.length) syncTitleSelect($rep);
    });

    // Zwijanie / rozwijanie bloku każdego wygenerowanego pola po kliknięciu top-bara w interfejsie edycji schematu
    // Przełącznik „Kolumna" w pasku pola → pokaż/ukryj blok konfiguracji kolumny
    $(document).on('change', '.evk-b-col-enable', function () {
        $(this).closest('.evk-b-field').toggleClass('evk-col-on', this.checked);
    });

    $(document).on('click', '.evk-b-field-top', function (e) {
        if ($(e.target).is('input, button, select, .dashicons-no-alt')) return;
        if ($(e.target).closest('.evk-b-col-switch').length) return; // klik w przełącznik nie zwija pola
        // Tylko klasa — widocznością bloków steruje CSS. (slideToggle ustawiał inline
        // display:block na WSZYSTKICH blokach konfiguracji, odsłaniając ustawienia
        // niewłaściwych typów pól — stąd „pojawiały się ustawienia niezwiązanych pól".)
        $(this).closest('.evk-b-field').toggleClass('is-collapsed');
    });

    $(document).on('input change', '.evk-b-subfields .evk-b-key, .evk-b-subfields .evk-b-fld-label, .evk-b-subfields .evk-b-type', function () {
        var $rep = $(this).closest('.evk-b-subfields').closest('.evk-b-field');
        if ($rep.length) syncTitleSelect($rep);
    });

    $(document).on('change', '.evk-b-type', function () {
        applyType($(this).closest('.evk-b-field'));
    });

    // Galeria: źródło kategorii (brak / lista / taksonomia) → CSS przez data-cat-source
    $(document).on('change', '.evk-b-gallery-cat-source', function () {
        $(this).closest('.evk-b-field-gallery').attr('data-cat-source', this.value);
    });

    // Image Select: dodawanie obrazów z biblioteki mediów → dopisuje linie "URL : Etykieta"
    $(document).on('click', '.evk-b-img-pick', function (e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) return;
        var $ta = $(this).closest('.evk-b-field').children('.evk-b-field-opts').find('textarea').first();
        if (!$ta.length) return;
        var frame = wp.media({ title: 'Wybierz obrazy', button: { text: 'Dodaj do listy' }, multiple: true, library: { type: 'image' } });
        frame.on('select', function () {
            var lines = [];
            frame.state().get('selection').each(function (att) {
                var a = att.toJSON();
                var label = a.title || a.alt || a.filename || '';
                if (a.url) lines.push(a.url + ' : ' + label);
            });
            if (!lines.length) return;
            var cur = ($ta.val() || '').replace(/\s+$/, '');
            $ta.val((cur ? cur + '\n' : '') + lines.join('\n'));
        });
        frame.open();
    });

    // Ściągawka galerii: tagi proste odzwierciedlają klucz pola na żywo
    function updateGalleryCheat($field) {
        var key = ($field.find('.evk-b-key').first().val() || '').trim() || 'klucz';
        $field.find('.evk-b-cheat-tag').each(function () {
            var tpl = $(this).attr('data-tpl');
            if (tpl) $(this).text(tpl.split('%s').join(key));
        });
    }

    $(document).on('input', '.evk-b-key', function () {
        var v = evkTranslit(this.value).toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+/, '');
        if (v !== this.value) this.value = v;
        updateGalleryCheat($(this).closest('.evk-b-field'));
    });

    $(document).on('blur', '.evk-b-fld-label, .evk-b-group-label', function () {
        var $row = $(this).closest('.evk-b-field, .evk-b-group-head');
        var $key = $row.find('.evk-b-key').first();
        if ($key.length && $key.val() === '') {
            $key.val(evkTranslit(this.value).toLowerCase().trim().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, ''));
        }
        updateGalleryCheat($row);
    });

    // Sterowanie widocznością "Ustawień" grupy w metaboxie pobocznym po odkliknięciu typu "Repeater"
    function toggleGroupSettings() {
        var isRep = $('input[name="evk_group_repeater"]').is(':checked');
        $('input[name="evk_group_collapsed"]').closest('label').toggle(isRep);
        $('input[name="evk_group_add_label"]').closest('.evk-group-option-field').toggle(isRep);
        $('input[name="evk_group_title_field"]').closest('.evk-group-option-field').toggle(isRep);
        // Kolumny admina mają sens tylko dla grup pojedynczych — ukryj config w repeaterze.
        $('#evk-edit-fields').toggleClass('evk-cols-hidden', isRep);
    }
    $(document).on('change', 'input[name="evk_group_repeater"]', toggleGroupSettings);

    // Lokalizacja: przełączanie bloków (typy treści / taksonomie / użytkownik).
    // Widoczność steruje CSS przez atrybut data-object-type — JS tylko go aktualizuje.
    $(document).on('change', '.evk-group-object-type', function () {
        $(this).closest('.evk-group-location').attr('data-object-type', this.value);
    });

    function initSortable() {
        if (!$.fn.sortable) return;
        var $groups = $('#evk-b-groups');
        if (!$groups.data('evk-sortable')) {
            $groups.data('evk-sortable', true);
            $groups.sortable({ handle: '.evk-b-ghandle', items: '> .evk-b-group', placeholder: 'evk-b-placeholder', forcePlaceholderSize: true });
        }
        $('.evk-b-fields').each(function () {
            if ($(this).data('evk-sortable')) return;
            $(this).data('evk-sortable', true);
            $(this).sortable({ handle: '.evk-b-fhandle', items: '> .evk-b-field', placeholder: 'evk-b-placeholder', forcePlaceholderSize: true });
        });
        $('.evk-b-subfields').each(function () {
            if ($(this).data('evk-sortable')) return;
            $(this).data('evk-sortable', true);
            $(this).sortable({ handle: '.evk-b-subhandle', items: '> .evk-b-field', placeholder: 'evk-b-placeholder', forcePlaceholderSize: true });
        });
    }

    $(function () {
        initFields();
        initSortable();
        toggleGroupSettings();
        $('.evk-b-field.is-repeater').each(function () { syncTitleSelect($(this)); });
        $('.evk-b-col-enable:checked').closest('.evk-b-field').addClass('evk-col-on');
    });

})(jQuery);
