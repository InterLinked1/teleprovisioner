<?php
header("Content-Type: application/xhtml+xml"); /* This is the response type a Polycom wants */
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html><head></head><body><hr/>
<?php
echo $today->format('g:i A');
?>
</body></html>