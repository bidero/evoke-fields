/* Evoke FIELDS — lista grup pól (toggle aktywności) */
(function ($) {
    'use strict';

    $(document).on('click', '.evk-toggle', function () {
        var $btn   = $(this);
        var $label = $btn.next('.evk-toggle-label');
        if ($btn.data('pending')) return;
        $btn.data('pending', true).css('opacity', .6);

        $.post(ajaxurl, {
            action:  'evk_toggle_group_active',
            post_id: $btn.data('id'),
            nonce:   $btn.data('nonce'),
        }, function (res) {
            $btn.removeData('pending').css('opacity', 1);
            if (res.success) {
                $btn.toggleClass('on', res.data.active);
                $label.text(res.data.active ? 'Tak' : 'Nie');
            }
        });
    });

})(jQuery);
