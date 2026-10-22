  <!-- / content -->

  <!-- footer -->
  <footer id="footer" class="app-footer" role="footer">
        <div class="wrapper b-t bg-light">
      <span class="pull-right">Powered by <a href="/" target="_blank"><?php echo $conf['sitename']?></a></span>
    	&copy; 2016-<?php echo date("Y")?> Copyright.
    </div>
  </footer>
  <!-- / footer -->

</div>

<script src="/assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script>
if (window.jQuery && window.EPAY_CSRF_TOKEN) {
  $.ajaxSetup({headers: {'X-CSRF-Token': window.EPAY_CSRF_TOKEN}});
  $(function(){
    $('form[method="post" i]').each(function(){
      if (!this.querySelector('input[name="csrf_token"]')) $('<input>').attr({type:'hidden', name:'csrf_token', value:window.EPAY_CSRF_TOKEN}).appendTo(this);
    });
  });
}
</script>
<script src="/assets/vendor/twitter-bootstrap/3.4.1-epay.1/js/bootstrap.js"></script>
<script src="./assets/js/ui-load.js"></script>
<script src="./assets/js/ui-jp.config.js"></script>
<script src="./assets/js/ui-jp.js"></script>
<script src="./assets/js/ui-nav.js"></script>
<script src="./assets/js/ui-toggle.js"></script>
</body>
</html>