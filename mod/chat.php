<?php /** @file */

require_once('include/chat.php');
require_once('include/bookmarks.php');

function chat_init(&$a) {

	$which = null;
	if(argc() > 1)
		$which = argv(1);
	if(! $which) {
		if(local_channel()) {
			$channel = $a->get_channel();
			if($channel && $channel['channel_address'])
			$which = $channel['channel_address'];
		}
	}
	if(! $which) {
		notice( t('You must be logged in to see this page.') . EOL );
		return;
	}

	$profile = 0;
	$channel = $a->get_channel();

	if((local_channel()) && (argc() > 2) && (argv(2) === 'view')) {
		$which = $channel['channel_address'];
		$profile = argv(1);		
	}

	$a->page['htmlhead'] .= '<link rel="alternate" type="application/atom+xml" href="' . $a->get_baseurl() . '/feed/' . $which .'" />' . "\r\n" ;

	// Run profile_load() here to make sure the theme is set before
	// we start loading content

	profile_load($a,$which,$profile);

}

function chat_post(&$a) {

	if($_POST['room_name'])
		$room = strip_tags(trim($_POST['room_name']));	

	if((! $room) || (! local_channel()))
		return;

	$channel = $a->get_channel();


	if($_POST['action'] === 'drop') {
		logger('delete chatroom');
		chatroom_destroy($channel,array('cr_name' => $room));
		goaway(z_root() . '/chat/' . $channel['channel_address']);
	}

	$acl = new AccessList($channel);
	$acl->set_from_array($_REQUEST);

	$arr = $acl->get();
	$arr['name'] = $room;
	$arr['expire'] = intval($_POST['chat_expire']);
	if(intval($arr['expire']) < 0)
		$arr['expire'] = 0;

	chatroom_create($channel,$arr);

	$x = q("select * from chatroom where cr_name = '%s' and cr_uid = %d limit 1",
		dbesc($room),
		intval(local_channel())
	);

	build_sync_packet(0, array('chatroom' => $x));

	if($x)
		goaway(z_root() . '/chat/' . $channel['channel_address'] . '/' . $x[0]['cr_id']);

	// that failed. Try again perhaps?

	goaway(z_root() . '/chat/' . $channel['channel_address'] . '/new');


}


function chat_content(&$a) {

	if(local_channel())
		$channel = $a->get_channel();

	$ob = $a->get_observer();
	$observer = get_observer_hash();
	if(! $observer) {
		notice( t('Permission denied.') . EOL);
		return;
	}

	if(! perm_is_allowed($a->profile['profile_uid'],$observer,'chat')) {
		notice( t('Permission denied.') . EOL);
		return;
	}
	
	if((argc() > 3) && intval(argv(2)) && (argv(3) === 'leave')) {
		chatroom_leave($observer,argv(2),$_SERVER['REMOTE_ADDR']);
		goaway(z_root() . '/channel/' . argv(1));
	}


	if((argc() > 3) && intval(argv(2)) && (argv(3) === 'status')) {
		$ret = array('success' => false);
		$room_id = intval(argv(2));
		if(! $room_id || ! $observer)
			return;

		$r = q("select * from chatroom where cr_id = %d limit 1",
			intval($room_id)
		);
		if(! $r) {
			json_return_and_die($ret);
		}
		require_once('include/security.php');
		$sql_extra = permissions_sql($r[0]['cr_uid']);

		$x = q("select * from chatroom where cr_id = %d and cr_uid = %d $sql_extra limit 1",
			intval($room_id),
			intval($r[0]['cr_uid'])
		);
		if(! $x) {
			json_return_and_die($ret);
		}
		$y = q("select count(*) as total from chatpresence where cp_room = %d",
			intval($room_id)
		);
		if($y) {
			$ret['success'] = true;
			$ret['chatroom'] = $r[0]['cr_name'];
			$ret['inroom'] = $y[0]['total'];
		}

		// figure out how to present a timestamp of the last activity, since we don't know the observer's timezone.

		$z = q("select created from chat where chat_room = %d order by created desc limit 1",
			intval($room_id)
		);
		if($z) {
			$ret['last'] = $z[0]['created'];
		}
		json_return_and_die($ret);
	}


	if(argc() > 2 && intval(argv(2))) {

		$room_id = intval(argv(2));
		$bookmark_link = get_bookmark_link($ob);

		$x = chatroom_enter($observer,$room_id,'online',$_SERVER['REMOTE_ADDR']);
		if(! $x)
			return;
		$x = q("select * from chatroom where cr_id = %d and cr_uid = %d $sql_extra limit 1",
			intval($room_id),
			intval($a->profile['profile_uid'])
		);
		if($x) {
			$acl = new AccessList(false);
			$acl->set($x[0]);

			$private = $acl->is_private();
			$room_name = $x[0]['cr_name'];
			if($bookmark_link)
				$bookmark_link .= '&url=' . z_root() . '/chat/' . argv(1) . '/' . argv(2) . '&title=' . urlencode($x[0]['cr_name']) . (($private) ? '&private=1' : '') . '&ischat=1'; 
		}
		else {
			notice( t('Room not found') . EOL);
			return;
		}

		$o = replace_macros(get_markup_template('chat.tpl'),array(
			'$is_owner' => ((local_channel() && local_channel() == $x[0]['cr_uid']) ? true : false),
			'$room_name' => $room_name,
			'$room_id' => $room_id,
			'$baseurl' => z_root(),
			'$nickname' => argv(1),
			'$submit' => t('Submit'),
			'$leave' => t('Leave Room'),
			'$drop' => t('Delete This Room'),
			'$away' => t('I am away right now'),
			'$online' => t('I am online'),
			'$bookmark_link' => $bookmark_link,
			'$bookmark' => t('Bookmark this room')

		));
		return $o;
	}





	if(local_channel() && argc() > 2 && argv(2) === 'new') {

		$acl = new AccessList($channel);
		$channel_acl = $acl->get();

		require_once('include/acl_selectors.php');

		$o = replace_macros(get_markup_template('chatroom_new.tpl'),array(
			'$header' => t('New Chatroom'),
			'$name' => array('room_name',t('Chatroom Name'),'', ''),
			'$chat_expire' => array('chat_expire',t('Expiration of chats (minutes)'),120,''),
			'$permissions' =>  t('Permissions'),
			'$acl' => populate_acl($channel_acl,false),
			'$submit' => t('Submit')
		));
		return $o;
	}



	require_once('include/conversation.php');

	$o = profile_tabs($a,((local_channel() && local_channel() == $a->profile['profile_uid']) ? true : false),$a->profile['channel_address']);

	require_once('include/widgets.php');

	$o .= replace_macros(get_markup_template('chatrooms.tpl'), array(
		'$header' => sprintf( t('%1$s\'s Chatrooms'), $a->profile['name']),
		'$baseurl' => z_root(),
		'$nickname' => $channel['channel_address'],
		'$rooms' => widget_chatroom_list(array()),
		'$newroom' => t('New Chatroom'),
		'$is_owner' => ((local_channel() && local_channel() == $a->profile['profile_uid']) ? 1 : 0)
	));
 
	return $o;

}
