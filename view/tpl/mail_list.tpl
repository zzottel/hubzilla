<div class="mail-list-wrapper">
	<a href="$from_url" class="mail-list-sender-url" ><img class="mail-list-sender-photo" src="$from_photo" alt="$from_name" /></a>
	<span class="mail-from">$from_name</span>
	<span class="mail-subject {{if $seen}}seen{{else}}unseen{{endif}}"><a href="message/$id" class="mail-link">$subject</a></span>
	<span class="mail-date" title="$date">$date</span>
	<span class="mail-delete"><a href="message/dropconv/$id" onclick="return confirmDelete();"  title="$delete" class="icon drophide mail-list-delete	delete-icon" onmouseover="imgbright(this);" onmouseout="imgdull(this);" ></a></span>
</div>
