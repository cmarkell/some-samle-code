<?php 

class Notification extends AppModel {
	public $name = 'Notification';
	
	public $order = 'Notification.created DESC';
	
	public $hasMany = array(

	);
	
	public $validate = array(
		'description' => array(
			'notEmpty' => array(
				'rule' => 'notEmpty',
				'message' => 'Please enter a message',
			),
		),
	);
	
	public function afterFind($results, $primary = false) {
		foreach ($results as $key => $val) {
			
			// Convert send date and send time
			if (isset($val[$this->alias]['send_date'])) $results[$key][$this->alias]['send_date'] = date('m/d/y', strtotime($val[$this->alias]['send_date']));
			if (isset($val[$this->alias]['send_time'])) $results[$key][$this->alias]['send_time'] =date('g:i A', strtotime($val[$this->alias]['send_time']));
			
		}
	    return $results;
	}
	
	public function beforeSave($options = array()) {
		
		if (!empty($this->data[$this->alias]['send_date'])) $this->data[$this->alias]['send_date'] = date('Y-m-d', strtotime($this->data[$this->alias]['send_date']));
		if (!empty($this->data[$this->alias]['send_time'])) $this->data[$this->alias]['send_time'] = date('H:i:s', strtotime($this->data[$this->alias]['send_time']));
		if (Configure::read('Push.whitelist_active')) $this->data[$this->alias]['whitelisted'] = 1;
		
		return true;
	}
	
	
	
	
	
	public function iosPush($message, $tokens = array(), $pushParams= array()) {
		set_time_limit(60*60*10);
		
		$return = 0;
		$passphrase = 'appmatrix9';
		
		if (Configure::read('Apple.use_dev_cert')) {
			$host = 'gateway.sandbox.push.apple.com:2195';
			$pemfile = APP.'Certificates/dev.pem';
		} else {
			$host = 'gateway.push.apple.com:2195';
			$pemfile = APP.'Certificates/prod.pem';
		}
		
		////////////////////////////////////////////////////////////////////////////////
		
		foreach ($tokens as $token) {
			
			$ctx = stream_context_create();
			stream_context_set_option($ctx, 'ssl', 'local_cert', $pemfile);
			stream_context_set_option($ctx, 'ssl', 'passphrase', $passphrase);
			//stream_context_set_option($ctx, 'ssl', 'cafile', 'entrust_2048_ca.cer');
			
			// Open a connection to the APNS server
			$fp = stream_socket_client('ssl://'.$host, $err, $errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx);
			
			if (!$fp) exit("Failed to connect: $err $errstr" . PHP_EOL);
			
			//echo 'Connected to APNS' . PHP_EOL;
			
			// Create the payload body
			$body['aps'] = array(
				'alert' => $message,
				'sound' => 'default'
			);
			
			if (!empty($pushParams)) {
				$body = array_merge($body, $pushParams);
			}
			
			//die(debug($body));
			
			// Encode the payload as JSON
			$payload = json_encode($body);
			
			// Build the binary notification
			$msg = chr(0) . pack('n', 32) . pack('H*', $token) . pack('n', strlen($payload)) . $payload;
			
			// Send it to the server
			$result = fwrite($fp, $msg, strlen($msg));
			if (!$result) {
				$return++;
			}
			fclose($fp);
			
		}
		
		return $return;
	}
	
	public function androidPush($message = '', $tokens = array(), $pushParams = array()) {
		
		if (!empty($tokens)) {
			
			$key = Configure::read('Google.api_key');
			
			$url = 'https://android.googleapis.com/gcm/send';
			$fields = array(
				'registration_ids'  => $tokens,
				'data'              => array("message"=>$message),
			);
			
			if (!empty($pushParams)) {
				foreach ($pushParams as $k => $v) {
					$fields['data'][$k] = $v;
				}
			}
			
			$headers = array( 
				'Authorization: key=' . $key,
				'Content-Type: application/json'
			);
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url );
			curl_setopt($ch, CURLOPT_POST, true );
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); 
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields) );
		
			$result = curl_exec($ch);
			if(curl_errno($ch)){ echo 'Curl error: ' . curl_error($ch); }
			curl_close($ch);
			
			//debug(compact('result'));
		}
		
		return count($tokens);
	}
	
	public function findAllRecipients() {
		$devices = $this->query("SELECT token FROM devices WHERE token IS NOT NULL AND token <> '' AND status = 'installed'");
		return array_unique(Set::extract('/devices/token', $devices));
	}
	
	public function findRegisteredRecipients($user_ids = array()) {
		if (empty($user_ids)) {
			return array();
		} else {
			$res = $this->query("SELECT token FROM devices WHERE user_id IN (".implode(',', $user_ids).")");
			return Set::extract('/devices/token', $res);
		}
	}
	
	public function findGeoRecipients($latitude, $longitude, $radius) {
		$radius = $radius * 3.28084;
		// 20887680 is earths radius in feet
		$datetime = date('Y-m-d H:i:s', strtotime("now -2 hour"));
		$sql = "SELECT id, token, lat, lng, created, modified, 
					(20887680 * acos(cos(radians($latitude)) 
						* cos(radians(`lat`)) * cos(radians(`lng`) 
						- radians($longitude)) + sin(radians($latitude)) 
						* sin(radians(`lat`)))
					) AS distance 
				FROM analytics 
				HAVING distance < $radius
				AND created >= '$datetime'
				AND token IS NOT NULL
				AND token <> ''
		";
		$analytics = $this->query($sql);
		return array_unique(Set::extract('/analytics/token', $analytics));
	}
	
	public function iosCheckFeedback() {
		$ctx = stream_context_create();
		
		if (Configure::read('Apple.use_dev_cert')) {
			$host = 'gateway.sandbox.push.apple.com:2195';
			$pemfile = APP.'Certificates/dev.pem';
		} else {
			$host = 'gateway.push.apple.com:2195';
			$pemfile = APP.'Certificates/prod.pem';
		}
		stream_context_set_option($ctx, 'ssl', 'local_cert', $pemfile);
		stream_context_set_option($ctx, 'ssl', 'passphrase', 'appmatrix9');
		stream_context_set_option($ctx, 'ssl', 'verify_peer', false);
		$fp = stream_socket_client($host, $error,$errorString, 100, (STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT), $ctx);
		
		//if(!$fp) $this->_triggerError("Failed to connect to device: {$error} {$errorString}.");
		$unregistered = array();
		while ($devcon = fread($fp, 38)){
			$arr = unpack("H*", $devcon);
			$rawhex = trim(implode("", $arr));
			$token = substr($rawhex, 12, 64);
			if(!empty($token)){
				
				//
				$unregistered[] = $token;
				
				$sql = "UPDATE `devices`
						SET `status` = 'uninstalled'
						WHERE `token`='{$token}'
						LIMIT 1;";
				$this->query($sql);
			}
		}
		fclose($fp);
		
		if (!empty($unregistered)) {
			foreach ($unregistered as $k => $v) {
//				echo "Unregistering Token: $v\n";
			}
		}
	}
}