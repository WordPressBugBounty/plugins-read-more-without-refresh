/**
 * Read More Without Refresh - Notice Dismiss Handler
 * 
 * @package ReadMoreWithoutRefresh
 * @version 3.4.0
 */

(function($) {
    'use strict';

    $(document).on('click', '.rmwr-notice .notice-dismiss', function() {
        $.ajax({
            url: rmwrNotice.ajaxurl,
            type: 'POST',
            data: {
                action: 'dismiss_rmwr_notice',
                nonce: rmwrNotice.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Notice is already dismissed by WordPress, no additional action needed
                }
            }
        });
    });

})(jQuery);
