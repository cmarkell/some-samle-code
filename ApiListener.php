<?php

App::uses('CakeEventListener', 'Event');
App::uses('CakeEmail', 'Network/Email');
App::uses('Security', 'Utility');

/**
 * Message Types:
 * 
 * These are the types of messages that get saved into the messages table for different events
 *//*
define('MESSAGE_TYPE_TAGGED',  'tagged');       // You were tagged in a bomb
define('MESSAGE_TYPE_BOMBER',  'bomber');       // You were set as the bomber in a bomb
define('MESSAGE_TYPE_COMMENT', 'comment');      // Someone you follow commented on a bomb
define('MESSAGE_TYPE_NEWBOMB', 'newbomb');      // Someone you follow created a new bomb
define('MESSAGE_TYPE_FOLLOWED', 'followed');    // Someone followed you
define('MESSAGE_TYPE_FAVORITED', 'favorited');  // Someone you follow favorited a bomb
*/

class ApiListener implements CakeEventListener {
	
	public function implementedEvents() {
		return array(
			'Controller.Api.newBomb' => 'newBomb',
			'Controller.Api.newComment' => 'newComment',
			'Controller.Api.bombFavorited' => 'bombFavorited',
			'Controller.Api.userTagged' => 'userTagged',
			'Controller.Api.bomberTagged' => 'bomberTagged',
			'Controller.Api.userFollowed' => 'userFollowed',
			'Controller.Api.bombShared' => 'bombShared',
		);
	}
	
	/**
	 * When a new bomb is created we need to:
	 * 1. Add a message the OP's feed.
	 * 2. Add a message to the OP's followers feeds
	 * 3. For each of the OP's followers, check if they want to be notified and queue up the push if so.
	 */
	public function newBomb(CakeEvent $event) {
		
		// Instantiate the Bomb model
		$Bomb = ClassRegistry::init('Bomb');
		//$this->Bomb->id = $event->id;
		
		$data = $Bomb->read(null, $event->id);
		
		/* 1. Add message to OP's feed */
		/* Taken out per Tim
		$Bomb->User->Message->save(array(
			'Message' => array(
				'id' => null,
				'bomb_id' => $data['Bomb']['id'],
				'user_id' => $data['User']['id'],
				// Related user id is the same as user id since we're just recording a new bomb
				// onto the users own feed
				'related_user_id' => $data['User']['id'],
				'title' => 'New Phobo',
				'type' => MESSAGE_TYPE_NEWBOMB,
			)
		));
		*/
		
		// 2. Add message to OP's followers feeds
		$followers = $Bomb->User->UserFollower->find('all', array(
			'conditions' => array(
				'UserFollower.user_id' => $data['Bomb']['user_id'],
			)
		));
		$follower_ids = Set::extract('/UserFollower/following_user_id', $followers);
		if (!empty($follower_ids)) {
			foreach ($follower_ids as $follower_id) {
				
				// Add message
				$Bomb->User->Message->create();
				$Bomb->User->Message->save(array(
					'Message' => array(
						'id' => null,
						'bomb_id' => $data['Bomb']['id'],
						'user_id' => $follower_id,
						'related_user_id' => $data['User']['id'],
						'title' => 'New Phobo',
						'type' => MESSAGE_TYPE_NEWBOMB,
					)
				));
				
				// TODO: Queue Notification...
				
			}
		}
		
		
		
	}
	
	/**
	 * When a new comment is created we need to:
	 * 1. Add a message the bomb owners feed.
	 * 2. ---REMOVED PER TIM--- Add a message to the feed of the person creating the comment.
	 * 3. Add a message to the feed of the bomber if he was tagged.
	 * 4. Add a message to the feed of anyone tagged in the bomb.
	 * 5. Add a message the feed of anyone who has ever posted a comment on this bomb
	 * 6. If it's a private bomb the we have to send an email to:
	 *    - the bomb owner
	 *    - the bomber
	 *    - anyone tagged in the bomb
	 *    - anyone who has commented in the past
	 *    (except the comment submitter).
	 * 
	 */
	public function newComment(CakeEvent $event) {
		
		// Keep track of who has had a message created about this comment already.
		$messaged = array();
		$emailed = array();
		
		// Instantiate the Comment model
		$Comment = ClassRegistry::init('Comment');
		$data = $Comment->find('first', array(
			'conditions' => array(
				'Comment.id' => $event->id,
			),
			'contain' => array(
				'User',
				'Bomb' => array(
					'User',
				)
			)
		));
//		die(print_r($data));
		
		// Add the comment submitters email address to the already emailed array in order to avoid sending him an email
		$emailed[] = $data['User']['email'];
		
		// Add comment submitters user id to the already messaged array to avoid creating a message for him
		$messaged[] = $data['User']['id'];
		
		/* 1. Add message to bomb owners feed (unless the commenter owns the bomb) */
		if (!in_array($data['Bomb']['user_id'], $messaged) && $data['Bomb']['user_id'] != $data['Comment']['user_id']) {
			$Comment->User->Message->create();
			$Comment->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => $data['Bomb']['id'],
					'user_id' => $data['Bomb']['user_id'],
					// Related user id is the id of the user posting the comment
					'related_user_id' => $data['Comment']['user_id'],
					'title' => 'commented on your phobo.',
					'type' => MESSAGE_TYPE_COMMENT,
				)
			));
			$messaged[] = $data['Bomb']['user_id'];
			
			// 6.
			if (!in_array($data['User']['email'], $emailed) && $data['Bomb']['private'] && $data['User']['notification_email']) {
				
				$to = $data['User']['email'];
				$subject = 'New Phobo Message';
				
				$Email = new CakeEmail('default');
				$Email->emailFormat('html');
				//$Email->domain(Configure::read('App.domain'));
				$Email->helpers(array('Html', 'Text'));
				$Email->to($to);
				$Email->subject($subject);
				$Email->template('private_comment', 'default');
				$Email->viewVars(array('comment' => $data));
				if ($Email->send()) {
					CakeLog::write('mailer', 'Sent email to '.$to);
				} else {
					CakeLog::write('mailer', 'Failed to send email to '.$to);
				}
				
				$emailed[] = $to;
				
			}
			
		}
		
		// 2. Add a message to the feed of the person creating the comment.
		/* Taken out per Tim
		if (!in_array($data['Comment']['user_id'], $messaged)) {
			$Comment->User->Message->create();
			$Comment->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => $data['Bomb']['id'],
					'user_id' => $data['Comment']['user_id'],
					// Related user id is the same as user id since we're just recording a new comment
					// onto the users own feed
					'related_user_id' => $data['Comment']['user_id'],
					'title' => 'new comment',
					'type' => MESSAGE_TYPE_COMMENT,
				)
			));
			$messaged[] = $data['Comment']['user_id'];
		}
		*/
		
		$bomb = $Comment->Bomb->find('first', array(
			'conditions' => array(
				'Bomb.id' => $data['Bomb']['id'],
			),
			'contain' => array(
				'User',
				'Bomber',
				'Comment' => array(
					'User',
				),
				'BombUser' => array(
					'User',
				),
			),
		));
		
		// 3. Bomber?
		if (!empty($bomb['Bomb']['bomber_id']) && !in_array($bomb['Bomb']['bomber_id'], $messaged)) {
			$Comment->User->Message->create();
			$Comment->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => $bomb['Bomb']['id'],
					'user_id' => $bomb['Bomb']['bomber_id'],
					// Related user id is the same as user id since we're just recording a new comment
					// onto the users own feed
					'related_user_id' => $data['Comment']['user_id'],
					'title' => 'new comment',
					'type' => MESSAGE_TYPE_COMMENT,
				)
			));
			$messaged[] = $bomb['Bomb']['bomber_id'];
		}
		
		// 6.
		if (!in_array($bomb['Bomber']['email'], $emailed) && $bomb['Bomb']['private'] && $bomb['Bomber']['notification_email']) {
			
			$to = $bomb['Bomber']['email'];
			$subject = 'New Phobo Message';
			
			$Email = new CakeEmail('default');
			$Email->emailFormat('html');
			//$Email->domain(Configure::read('App.domain'));
			$Email->helpers(array('Html', 'Text'));
			$Email->to($to);
			$Email->subject($subject);
			$Email->template('private_comment', 'default');
			$Email->viewVars(array('comment' => $data));
			if ($Email->send()) {
				CakeLog::write('mailer', 'Sent email to '.$to);
			} else {
				CakeLog::write('mailer', 'Failed to send email to '.$to);
			}
			
			$emailed[] = $to;
		}
		
		// 4. Tagged people?
		if (!empty($bomb['BombUser'])) {
			foreach ($bomb['BombUser'] as $k => $bombUser) {
				if (!in_array($bombUser['user_id'], $messaged)) {
					
					$Comment->User->Message->create();
					$Comment->User->Message->save(array(
						'Message' => array(
							'id' => null,
							'bomb_id' => $bomb['Bomb']['id'],
							'user_id' => $bombUser['user_id'],
							// Related user id is the same as user id since we're just recording a new comment
							// onto the users own feed
							'related_user_id' => $data['Comment']['user_id'],
							'title' => 'new comment',
							'type' => MESSAGE_TYPE_COMMENT,
						)
					));
					
				}
				
				// 6.
				if (!in_array($bombUser['User']['email'], $emailed) && $bomb['Bomb']['private'] && $bombUser['User']['notification_email']) {

					$to = $bombUser['User']['email'];
					$subject = 'New Phobo Message';

					$Email = new CakeEmail('default');
					$Email->emailFormat('html');
					//$Email->domain(Configure::read('App.domain'));
					$Email->helpers(array('Html', 'Text'));
					$Email->to($to);
					$Email->subject($subject);
					$Email->template('private_comment', 'default');
					$Email->viewVars(array('comment' => $data));
					if ($Email->send()) {
						CakeLog::write('mailer', 'Sent email to '.$to);
					} else {
						CakeLog::write('mailer', 'Failed to send email to '.$to);
					}
					
					$emailed[] = $to;
				}
			}
		}
		
		// 5. Other people who have commented on this bomb but aren't the owner, bomber or tagged in it.
		$comment_user_ids = Set::extract('/Comment/user_id', $bomb);
		if (!empty($comment_user_ids)) {
			foreach ($comment_user_ids as $k => $comment_user_id) {
				if (!in_array($comment_user_id, $messaged) && $data['Bomb']['user_id'] != $comment_user_id) {
					
					$Comment->User->Message->create();
					$Comment->User->Message->save(array(
						'Message' => array(
							'id' => null,
							'bomb_id' => $bomb['Bomb']['id'],
							'user_id' => $comment_user_id,
							// Related user id is the same as user id since we're just recording a new comment
							// onto the users own feed
							'related_user_id' => $data['Comment']['user_id'],
							'title' => 'new comment',
							'type' => MESSAGE_TYPE_COMMENT,
						)
					));
					
				}
				
				// 6.
				$commentUser = $Comment->User->findById($comment_user_id);
				if (!in_array($commentUser['User']['email'], $emailed) && $bomb['Bomb']['private'] && $commentUser['User']['notification_email']) {

					$to = $commentUser['User']['email'];
					$subject = 'New Phobo Message';

					$Email = new CakeEmail('default');
					$Email->emailFormat('html');
					//$Email->domain(Configure::read('App.domain'));
					$Email->helpers(array('Html', 'Text'));
					$Email->to($to);
					$Email->subject($subject);
					$Email->template('private_comment', 'default');
					$Email->viewVars(array('comment' => $data));
					if ($Email->send()) {
						CakeLog::write('mailer', 'Sent email to '.$to);
					} else {
						CakeLog::write('mailer', 'Failed to send email to '.$to);
					}
					
					$emailed[] = $to;
				}
			}
		}
		
		//die(print_r($emailed, true));
		
		// Now we find the owner of the bomb and see if they want to recieve a push when someone comments on their bomb.
		// This can be limited to people the bomb owner is following (notification_likes = 1) or can be everyone (notification_likes = 2).
		if (!empty($bomb) && !empty($bomb['User']) && $bomb['User']['notification_comments']) {
			
			$doit = true;
			
			// Only people I follow
			if ($bomb['User']['notification_comments'] == '1') {
				$res = $Comment->query("SELECT user_id FROM user_followers WHERE following_user_id = ".$bomb['User']['id']);
				$follower_user_ids = Set::extract('/user_followers/user_id', $res);
				if (!in_array($data['Comment']['user_id'], $follower_user_ids)) {
					$doit = false;
				}
			}
			
			if ($doit) {
				
				// Queue up the push notification
				$msg = 'You have a new Comment';
				
				$Notification = ClassRegistry::init('Notification');
				$Notification->create();
				$Notification->save(array(
					'Notification' => array(
						'id' => null,
						'type' => 'basic',
						'description' => $msg,
						'html' => '',
						'recipients' => '',
						'published' => 1,
						'user_id' => $bomb['User']['id'],
						'send_date' => date('Y-m-d'),
						'send_time' => date('H:i:s'),
						'status' => 'queued',
						'target' => 'individual',
						'whitelisted' => Configure::read('Push.whitelist_active'),
					)
				));
				
			}
			//die(print_r($bomb, true));
			
		}
	}
	
	/**
	 * When a bomb is favorited we need to:
	 * 1. Add a message the bomb owners feed.
	 * -- SKIP -- 2. Add a message to the feed of the person favoriting the bomb.
	 */
	public function bombFavorited(CakeEvent $event) {
		$Favorite = ClassRegistry::init('Favorite');
		$data = $Favorite->read(null, $event->id);
		
		// Keep track of who has had a message created already.
		$messaged = array();
		
		// Avoid adding a message to the user who favorited a bombs message feed
		$messaged[] = $data['Favorite']['user_id'];
		
		/* 1. Add message to bomb owners feed (and the person favoriting isn't the person who owns the bomb) */
		if (!in_array($data['Bomb']['user_id'], $messaged) && $data['Bomb']['user_id'] != $data['Favorite']['user_id']) {
			$Favorite->User->Message->create();
			$Favorite->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => $data['Bomb']['id'],
					'user_id' => $data['Bomb']['user_id'],
					// Related user id is the id of the user faoriting the bomb
					'related_user_id' => $data['Favorite']['user_id'],
					'title' => 'liked your phobo.',
					'type' => MESSAGE_TYPE_FAVORITED,
				)
			));
			$messaged[] = $data['Bomb']['user_id'];
		}
		
		/* 2. Add message to bomb owners feed *//*
		if (!in_array($data['Bomb']['user_id'], $messaged)) {
			$Favorite->User->Message->create();
			$Favorite->User->Message->save(array(
				'Favorite' => array(
					'id' => null,
					'bomb_id' => $data['Bomb']['id'],
					'user_id' => $data['Bomb']['user_id'],
					// Related user id is the same user creating the favorite
					'related_user_id' => $data['Bomb']['user_id'],
					'title' => 'you liked a phobo.',
					'type' => MESSAGE_TYPE_FAVORITED,
				)
			));
			$messaged[] = $data['Bomb']['user_id'];
		}
		/**/
		
		
		// Now we find the owner of the bomb and see if they want to recieve a push when someone likes their bomb.
		// This can be limited to people the bomb owner is following (notification_likes = 1) or can be everyone (notification_likes = 2).
		$bomb = $Favorite->Bomb->findById($data['Bomb']['id']);
		
		//die(print_r($bomb['User'], true));
		
		if (!empty($bomb) && !empty($bomb['User']) && $bomb['User']['notification_likes']) {
			
			$doit = true;
			
			// Only people I follow
			if ($bomb['User']['notification_likes'] == '1') {
				$res = $Favorite->query("SELECT user_id FROM user_followers WHERE following_user_id = ".$bomb['User']['id']);
				$follower_user_ids = Set::extract('/user_followers/user_id', $res);
				if (!in_array($data['Favorite']['user_id'], $follower_user_ids)) {
					$doit = false;
				}
			}
			
			if ($doit) {
				
				// Queue up the push notification
				$msg = 'You have a new Like';
				
				$Notification = ClassRegistry::init('Notification');
				$Notification->create();
				$Notification->save(array(
					'Notification' => array(
						'id' => null,
						'type' => 'basic',
						'description' => $msg,
						'html' => '',
						'recipients' => '',
						'published' => 1,
						'user_id' => $bomb['User']['id'],
						'send_date' => date('Y-m-d'),
						'send_time' => date('H:i:s'),
						'status' => 'queued',
						'target' => 'individual',
						'whitelisted' => Configure::read('Push.whitelist_active'),
					)
				));
				
			}
			//die(print_r($bomb, true));
			
		}
		
	}
	
	/**
	 * When a user is tagged in a bomb we need to:
	 * 1. Add a message the tagged users feed.
	 */
	public function userTagged(CakeEvent $event) {
		$Bomb = ClassRegistry::init('Bomb');
		$data = $Bomb->BombUser->read(null, $event->id);
		
		// Keep track of who has had a message created about this comment already.
		$messaged = array();
		
		// Avoid adding a message to the message feed of the user who submitted the tag
		$messaged[] = $data['BombUser']['submitter_id'];
		
		/* 1. Add a message the tagged users feed */
		if (!in_array($data['BombUser']['user_id'], $messaged) && $data['BombUser']['user_id'] != $data['BombUser']['submitter_id']) {
			$Bomb->User->Message->create();
			$Bomb->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => $data['BombUser']['bomb_id'],
					// The user_id in this case in the user who was tagged in the bomb
					'user_id' => $data['BombUser']['user_id'],
					// The related user id is the id of the user who tagged someone in the bomb
					'related_user_id' => $data['BombUser']['submitter_id'],
					'title' => 'tagged you in a phobo.',
					'type' => MESSAGE_TYPE_TAGGED,
				)
			));
			$messaged[] = $data['BombUser']['user_id'];
		}
		
		// Now we need to find out if the user being tagged wants to be notified of the fact
		// ** Unless you tagged yourself **
		if ($data['BombUser']['user_id'] != $data['BombUser']['submitter_id']) {
			$User = ClassRegistry::init('User');
			$User->recursive = -1;
			$user = $User->findById($data['BombUser']['user_id']);
			if ($user['User']['notification_tags']) {
			
				// Queue up the push notification
				$msg = 'You have been tagged in a Phobo';
			
				$Notification = ClassRegistry::init('Notification');
				$Notification->create();
				$Notification->save(array(
					'Notification' => array(
						'id' => null,
						'type' => 'basic',
						'description' => $msg,
						'html' => '',
						'recipients' => '',
						'published' => 1,
						'user_id' => $user['User']['id'],
						'send_date' => date('Y-m-d'),
						'send_time' => date('H:i:s'),
						'status' => 'queued',
						'target' => 'individual',
						'whitelisted' => Configure::read('Push.whitelist_active'),
					)
				));
			
			}
		}
	}
	
	/**
	 * When a user is tagged as the bomber we need to:
	 * 1. Add a message the bombers feed.
	 */
	public function bomberTagged(CakeEvent $event) {
		$Bomb = ClassRegistry::init('Bomb');
		$data = $Bomb->read(null, $event->id);
		
		// Keep track of who has had a message created about this already.
		$messaged = array();
		
		/* 1. Add a message the bombers feed */
		if (!in_array($data['Bomb']['user_id'], $messaged) && $data['Bomb']['bomber_id'] != $data['Bomb']['bomber_submitter_id']) {
			$Bomb->User->Message->create();
			$Bomb->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => $data['Bomb']['id'],
					// The user_id in this case in the user who was tagged as the bomber
					'user_id' => $data['Bomb']['bomber_id'],
					// The related user id is the id of the user who tagged the bomber
					'related_user_id' => $data['Bomb']['bomber_submitter_id'],
					'title' => 'tagged you as the bomber in a phobo.',
					'type' => MESSAGE_TYPE_BOMBER,
				)
			));
			$messaged[] = $data['Bomb']['bomber_id'];
		}
		
		
		// Now we need to find out if the user being tagged wants to be notified of the fact
		// ** And you didn't flag yourself as the bomber **
		if ($data['Bomb']['bomber_id'] != $data['Bomb']['bomber_submitter_id']) {
			$User = ClassRegistry::init('User');
			$User->recursive = -1;
			$user = $User->findById($data['Bomb']['bomber_id']);
			if ($user['User']['notification_bombers']) {
			
				// Queue up the push notification
				$msg = 'You\'ve been tagged as the bomber in a Phobo';
			
				$Notification = ClassRegistry::init('Notification');
				$Notification->create();
				$Notification->save(array(
					'Notification' => array(
						'id' => null,
						'type' => 'basic',
						'description' => $msg,
						'html' => '',
						'recipients' => '',
						'published' => 1,
						'user_id' => $data['Bomb']['bomber_id'],
						'send_date' => date('Y-m-d'),
						'send_time' => date('H:i:s'),
						'status' => 'queued',
						'target' => 'individual',
						'whitelisted' => Configure::read('Push.whitelist_active'),
					)
				));
			
			}
		}
	}
	
	/**
	 * When a user is followed we need to:
	 * 1. Add a message the followed users feed.
	 */
	public function userFollowed(CakeEvent $event) {
		$UserFollower = ClassRegistry::init('UserFollower');
		$data = $UserFollower->read(null, $event->id);
		
		// Keep track of who has had a message created about this already.
		$messaged = array();
		
		/* 1. Add a message the followed users feed */
		if (!in_array($data['UserFollower']['following_user_id'], $messaged)) {
			
			$UserFollower->User->Message->create();
			$UserFollower->User->Message->save(array(
				'Message' => array(
					'id' => null,
					'bomb_id' => null,
					// The user_id in this case in the user being followed
					'user_id' => $data['UserFollower']['following_user_id'],
					// The related user id is the id of the user who clicked follow
					'related_user_id' => $data['UserFollower']['user_id'],
					'title' => 'followed you.',
					'type' => MESSAGE_TYPE_FOLLOWED,
				)
			));
			$messaged[] = $data['UserFollower']['following_user_id'];
			
			
			// Check if the followed user wants to get push notifications about this
			$user = $UserFollower->User->findById($data['UserFollower']['following_user_id']);
			if (!empty($user) && $user['User']['notification_followers']) {
				
				
				// The notification_followers option can be 0 = OFF, 1 = Everyone, 2 = Only people the user is following
				$following_user_ids = $UserFollower->User->findFollowerIds($data['UserFollower']['user_id']);
				if ($user['User']['notification_followers'] != 1 || in_array($data['UserFollower']['following_user_id'], $following_user_ids)) {
					
					// Queue up the push notification
					$msg = 'You have a new follower';
					
					$Notification = ClassRegistry::init('Notification');
					$Notification->create();
					$Notification->save(array(
						'Notification' => array(
							'id' => null,
							'type' => 'basic',
							'description' => $msg,
							'html' => '',
							'recipients' => '',
							'published' => 1,
							'user_id' => $user['User']['id'],
							'send_date' => date('Y-m-d'),
							'send_time' => date('H:i:s'),
							'status' => 'queued',
							'target' => 'individual',
							'whitelisted' => Configure::read('Push.whitelist_active'),
						)
					));
				
				}
			}
			
		}
	}
	
	/**
	 * When a user shares a bomb we need to:
	 * 1. Add a message the all of the users followers feeds.
	 */
	public function bombShared(CakeEvent $event) {
		$UserShare = ClassRegistry::init('UserShare');
		$data = $UserShare->read(null, $event->id);
		
		// Keep track of who has had a message created about this already.
		$messaged = array();
		
		$followers = $UserShare->User->UserFollower->find('all', array(
			'conditions' => array(
				'UserFollower.user_id' => $data['UserShare']['user_id'],
			)
		));
		$follower_user_ids = Set::extract('/UserFollower/following_user_id', $followers);
		
		//die(print_r($follower_user_ids));
		
		if (!empty($follower_user_ids)) {
			foreach ($follower_user_ids as $follower_user_id) {
				if (!in_array($follower_user_id, $messaged)) {
					$UserShare->User->Message->create();
					$UserShare->User->Message->save(array(
						'Message' => array(
							'id' => null,
							'bomb_id' => $data['UserShare']['bomb_id'],
							// The user_id in this case is the follower to be told about the bomb
							'user_id' => $follower_user_id,
							// The related user id is the id of the user who clicked share
							'related_user_id' => $data['UserShare']['user_id'],
							'title' => 'shared a phobo.',
							'type' => MESSAGE_TYPE_SHARED,
						)
					));
					$messaged[] = $follower_user_id;
				}
			}
		}
		
	}
	
}