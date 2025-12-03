
jQuery(document).ready(function($) {
    // Обработчик клика по кнопке массового перевода (копирование)
    $(document).on('click', '#bulk-translate-button', function(e) {
        e.preventDefault();
        handleBulkTranslate(0);
    });
    
    // Обработчик клика по кнопке массового перевода (DeepL)
    $(document).on('click', '#bulk-translate-deepl-button', function(e) {
        e.preventDefault();
        handleBulkTranslate(1);
    });
    
    function handleBulkTranslate(useDeepL) {
        var checkedPosts = $('input[name="post[]"]').filter(':checked');
        
        if (checkedPosts.length === 0) {
            alert('Пожалуйста, выберите посты для перевода');
            return;
        }
        
        var postIds = [];
        checkedPosts.each(function() {
            postIds.push($(this).val());
        });
        
        var button = useDeepL ? $('#bulk-translate-deepl-button') : $('#bulk-translate-button');
        
        var originalText = button.val();
        
        button.val(pmt_ajax.translating_text).prop('disabled', true);
        
        $.ajax({
            url: pmt_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bulk_translate_posts',
                post_ids: postIds,
                use_deepl: useDeepL,
                nonce: pmt_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(pmt_ajax.success_text);
                    location.reload();
                } else {
                    alert(pmt_ajax.error_text + ' ' + response.data);
                }
            },
            error: function() {
                alert(pmt_ajax.error_text);
            },
            complete: function() {
                button.val(originalText).prop('disabled', false);
            }
        });
    }
    
    // Обработчик для dropdown bulk actions
    $('.tablenav select[name="action"], .tablenav select[name="action2"]').change(function() {
        var selectedAction = $(this).val();
        if (selectedAction === 'bulk_translate' || selectedAction === 'bulk_translate_deepl') {
            $(this).closest('.tablenav').find('#doaction, #doaction2').click(function(e) {
                e.preventDefault();
                if (selectedAction === 'bulk_translate_deepl') {
                    $('#bulk-translate-deepl-button').click();
                } else {
                    $('#bulk-translate-button').click();
                }
            });
        }
    });
});
