(function($){
  function updateCounts($wrap, data) {
    $wrap.find('.spr-count[data-type="like"]').text(parseInt(data.likes || 0, 10));
    var $dis = $wrap.find('.spr-count[data-type="dislike"]');
    if ($dis.length) $dis.text(parseInt(data.dislikes || 0, 10));
  }

  function lockButtons($wrap, msg) {
    $wrap.find('.spr-btn').prop('disabled', true).attr('aria-pressed', 'true');
    if (msg) $wrap.find('.spr-msg').text(msg);
  }

  $(document).on('click', '.spr-reactions .spr-btn', function(e){
    e.preventDefault();
    var $btn  = $(this);
    var $wrap = $btn.closest('.spr-reactions');
    var postId = parseInt($wrap.data('postid') || 0, 10);
    if (!postId || typeof sprData === 'undefined') return;

    // already voted?
    if (sprData.hasVoted) {
      $wrap.find('.spr-msg').text(sprData.strings.voted);
      return;
    }

    var reaction = $btn.hasClass('spr-like') ? 'like' : 'dislike';

    fetch(sprData.restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': sprData.nonce
      },
      body: JSON.stringify({ post_id: postId, reaction: reaction })
    })
    .then(async (r) => {
      var json = await r.json().catch(()=>({}));
      if (r.ok && json) {
        updateCounts($wrap, json);
        lockButtons($wrap, json.message || sprData.strings.thanks);
        sprData.hasVoted = true;
      } else {
        $wrap.find('.spr-msg').text((json && json.message) || sprData.strings.error);
      }
    })
    .catch(() => {
      $wrap.find('.spr-msg').text(sprData.strings.error);
    });
  });

  // lock immediately if server localized it
  $(function(){
    if (typeof sprData !== 'undefined' && sprData.hasVoted) {
      $('.spr-reactions').each(function(){
        lockButtons($(this), sprData.strings.voted);
      });
    }
  });
})(jQuery);
