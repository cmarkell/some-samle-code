<?php 

/**
 * CRON Job Shell for sending out queued push notifications.
 */

// To run add the following to your crontab
// */1  *    *    *    *  cd /Users/chris/Repos/braman.appmatrixinc.com/app && Console/cake push

class PushShell extends AppShell {
	
	public $uses = array(
		'User',
		'Analytic',
		'Notification',
		'Device',
		'Setting',
	);
	
	public function main() {
		date_default_timezone_set('America/New_York');
		
		// Set MySQL Timezone to UTC
		$db = ConnectionManager::getDataSource('default');
		$db->rawQuery("SET SESSION time_zone = '-05:00';");
		
		$this->Setting->getConfig();
		
		// Check iOS feedback
		$this->Notification->iosCheckFeedback();
		
		// Find queued push messages to send out
		$notifications = $this->Notification->find('all', array(
			'conditions' => array(
				'Notification.status' => 'queued',
				'Notification.send_date <=' => date('Y-m-d'),
				'Notification.send_time <=' => date('H:i:s'),
			),
			'order' => 'Notification.created ASC',
		));
		
		if (!empty($notifications)) {
			
			foreach ($notifications as $notification) {
				
				$iosTokens = $androidTokens = array();
				
				$recipients = array();
				
				// Determine which recipients to include
				switch ($notification['Notification']['target']) {
					case 'all':
						$devices = $this->Device->find('all');
						$recipients = Set::extract('/Device/token', $devices);
						break;
					case 'geo':
						$recipients = $this->Notification->findGeoRecipients($notification['Notification']['latitude'], $notification['Notification']['longitude'], $notification['Notification']['radius'] / 3.28084);
						break;
					case 'registered':
						$recipients = $this->Notification->findRegisteredRecipients(explode(',', $notification['Notification']['user_id']));
						break;
					case 'individual':
						$device = $this->Device->findByUserId($notification['Notification']['user_id']);
						if (!empty($device)) {
							$recipients[] = $device['Device']['token'];
						}
						break;
					case 'paid':
						$users = $this->User->find('all', array(
							'fields' => array(
								'id',
							),
							'conditions' => array(
								'User.paid' => 1,
							)
						));
						$user_ids = Set::extract('/User/id', $users);
						if (!empty($user_ids)) {
							$devices = $this->Device->find('all', array(
								'conditions' => array(
									'Device.user_id' => $user_ids,
								)
							));
							if (!empty($devices)) {
								$recipients = Set::extract('/Device/token', $devices);
							}
						}
						break;
					case 'unpaid':
						$users = $this->User->find('all', array(
							'fields' => array(
								'id',
							),
							'conditions' => array(
								'User.paid' => 0,
							)
						));
						$user_ids = Set::extract('/User/id', $users);
						if (!empty($user_ids)) {
							$devices = $this->Device->find('all', array(
								'conditions' => array(
									'Device.user_id' => $user_ids,
								)
							));
							if (!empty($devices)) {
								$recipients = Set::extract('/Device/token', $devices);
							}
						}
						break;
					default:
						$recipients = $this->Notification->findAllRecipients();
				}
				
				// Ensure only installed tokens get included
				$recipients = Set::extract('/Device/token', $this->Device->find('all', array(
					'conditions' => array(
						'Device.token' => $recipients,
						'Device.status' => 'installed',
					)
				)));
				
				/*** TEST MODE: These settings are in the bootstrap file and can be used to avoid sending test push notifications to actual users ***/
				if (Configure::read('Push.whitelist_active')) {
					$recipients = array_intersect(explode("\n", Configure::read('Push.whitelist')), $recipients);
				}
				
				file_put_contents(LOGS . 'push.log', 'Sending to: '.implode(',', $recipients));
				
				// Sort out ios vs android tokens
				foreach ($recipients as $recipient) {
					if (strlen($recipient) > 100) {
						$androidTokens[] = $recipient;
					} else {
						$iosTokens[] = $recipient;
					}
				}
				
				$recipients = array_unique($recipients);
				
				$notification['Notification']['active'] = true;
				$notification['Notification']['recipients'] = implode(',', $recipients);
				$notification['Notification']['devices'] = count($recipients);
				$notification['Notification']['status'] = 'delivered';
				
				if ($this->Notification->save($notification)) {
					
					$pushParams = array(
						'type' => $notification['Notification']['type'],
					);
					
					if ($notification['Notification']['type'] == 'rich') {
						$pushParams['html'] = $notification['Notification']['html'];
					}
					
					$this->Notification->iosPush($notification['Notification']['description'], $iosTokens, $pushParams);
					$this->Notification->androidPush($notification['Notification']['description'], $androidTokens, $pushParams);
					
				}
			}
		}
		
	}
	
}