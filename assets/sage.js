(function ($) {
    $(function(){
        $('.sage-customer-search').select2({
            ajax: {
                url: sage_roi_var.url,
                data: function (params) {
                    return {
                        term         : params.term,
                        nonce        : sage_roi_var.nonce,
                        action       : $(this).attr('data-action'),
                        security: $(this).attr('data-security'),
                    };
                },
                processResults: function( ret ) {
                    var data = JSON.parse(ret);
                    var terms = [];
                    if ( data ) {
                        $.each( data, function( index, param ) {
                            terms.push( { id: param.id, text: param.text } );
                        });
                    }

                    console.log(terms)
                    return {
                        results: terms
                    };
                },
                cache: true
            }
        });
    });
})(jQuery)