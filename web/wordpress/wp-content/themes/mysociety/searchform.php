<form method="get" id="searchform" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<div><strong></strong> <input type="text" value="<?php echo wp_specialchars($s, 1); ?>" name="s" id="s" size="12"/>
<input type="submit" id="searchsubmit" value="Search" />
</div>
</form>
