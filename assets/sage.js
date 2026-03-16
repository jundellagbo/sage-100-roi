(function ($) {
    $(function(){
        $('.sage-customer-search').each(function() {
            var $el = $(this);
            var action = $el.data('action') || 'sage_roi_customer_search';
            var minLength = parseInt($el.data('minimum-input-length'), 10) || 0;
            $el.select2({
                minimumInputLength: minLength,
                ajax: {
                    url: sage_roi_var.url,
                    data: function (params) {
                        return {
                            term   : params.term,
                            nonce  : sage_roi_var.nonce,
                            action : action
                        };
                    },
                    processResults: function( ret ) {
                        var data = typeof ret === 'string' ? JSON.parse(ret) : ret;
                        var terms = [];
                        if ( data ) {
                            $.each( data, function( index, param ) {
                                terms.push( { id: param.id, text: param.text } );
                            });
                        }
                        return { results: terms };
                    },
                    cache: true
                }
            });
        });
    });
})(jQuery);