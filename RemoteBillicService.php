<?php
class RemoteBillicService {
	public $settings = array(
		'orderform_vars' => array(
			'domain'
		) ,
		'description' => 'A Billic-to-Billic ordering API. Allows resellers to resell your services with zero coding and minimal configuration.',
	);
	public $ch; // curl handle
	function __construct() {
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT => "Curl/RemoteBillicService",
			CURLOPT_AUTOREFERER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 300,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_POST => true,
		));
	}
	function user_cp($array) {
		global $billic, $db;
		$import_data = json_decode(trim($array['service']['import_data']) , true);
		if ($import_data === null) {
			die('Import data for this service is corrupt');
		}
		curl_setopt($this->ch, CURLOPT_URL, 'https://' . $import_data['domain'] . '/API/');
		$max_redirects = 10;
		$num_redirects = 0;
		while (true) {
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
				'email' => $import_data['email'],
				'apikey' => $import_data['apikey'],
				'module' => 'Services',
				'request' => 'call_user_cp',
				'serviceid' => $import_data['serviceid'],
				'post' => json_encode($_POST) ,
				'get' => json_encode($_GET) ,
			));
			$data = curl_exec($this->ch);
			if (curl_errno($this->ch) > 0) {
				die('Curl error: ' . curl_error($this->ch));
			}
			$rawdata = trim($data);
			$data = json_decode($rawdata, true);
			if ($data === null) {
				die('Remote Billic returned corrupt data: ' . $rawdata);
			}
			if (isset($data['error'])) {
				die('Remote Billic Error: ' . $data['error']);
			}
			$data['html'] = base64_decode($data['html']);
			if (empty($data['html'])) {
				die('Remote Billic user_cp returned no content');
			}
			$data['html'] = trim($data['html']);
			$is_json = false;
			if (substr($data['html'], 0, 1) == '{') {
				$json = json_decode($data['html'], true);
				if ($json !== null) {
					$is_json = true;
					if ($json['redirect'][0] == '/') {
						$json['redirect'] = substr($json['redirect'], 1);
					}
					curl_setopt($this->ch, CURLOPT_URL, 'https://' . $import_data['domain'] . '/API/Redirect/' . $json['redirect']);
					$num_redirects++;
					if ($num_redirects >= $max_redirects) {
						die('Remote Billic Exceeded Maximum number of redirects');
					}
				}
			}
			if (is_array($data) && array_key_exists('DISABLE_FOOTER', $data)) {
				ob_end_clean();
				ob_start();
				define('DISABLE_FOOTER', true);
			}
			if (is_array($data) && array_key_exists('headers', $data) && is_array($data['headers'])) {
				foreach ($data['headers'] as $header) {
					header($header);
				}
			}
			if (!$is_json) {
				$html = $data['html'];
				$html = str_replace('/ID/' . $import_data['serviceid'], '/ID/' . $array['service']['id'], $html);
				$html = str_replace('/API/', '/' . ucwords($_SERVER['billic_mode']) . '/Services/ID/' . $array['service']['id'] . '/', $html);
				echo $html;
				break;
			}
		}
	}
	function suspend($array) {
		global $billic, $db;
		return true;
	}
	function unsuspend($array) {
		global $billic, $db;
		return true;
	}
	function terminate($array) {
		global $billic, $db;
		return true;
	}
	function create($array) {
		global $billic, $db;
		/*
		   $vars = $array['vars'];
		   $service = $array['service'];
		   $plan = $array['plan'];
		   $user_row = $array['user'];
		*/
		if (empty($array['service']['import_data']['hash'])) {
			$import_data = json_decode($array['plan']['import_data'], true);
			if (!is_array($import_data)) {
				return 'Import data for the plan is corrupt';
			}
			if (empty($array['plan']['import_hash'])) {
				return 'Import hash for the plan is missing';
			}
			$import_data['hash'] = $array['plan']['import_hash'];
			$array['service']['import_data'] = json_encode($import_data);
			$db->q('UPDATE `services` SET `import_data` = ? WHERE `id` = ?', $array['service']['import_data'], $array['service']['id']);
		}
		$import_data = json_decode(trim($array['service']['import_data']) , true);
		if (!is_array($import_data)) {
			return 'Import data for the service is corrupt';
		}
		curl_setopt($this->ch, CURLOPT_URL, 'https://' . $import_data['domain'] . '/API/');
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
			'email' => $import_data['email'],
			'apikey' => $import_data['apikey'],
			'module' => 'Order',
			'request' => 'call_placeorder',
			'import_hash' => $import_data['hash'],
			'order_vars' => json_encode($array['vars']) ,
			'billingcycle' => $array['service']['billingcycle'],
		));
		$data = curl_exec($this->ch);
		if (curl_errno($this->ch) > 0) {
			return 'Curl error: ' . curl_error($this->ch);
		}
		$rawdata = trim($data);
		$data = json_decode(trim($rawdata) , true);
		if ($data === null) {
			return 'Remote Billic returned corrupt data: ' . $rawdata;
		}
		if (isset($data['error'])) {
			return $data['error'];
		}
		if (!isset($data['remote_service_id']) || !is_numeric($data['remote_service_id'])) {
			return 'Remote billic returned unknown data: ' . $rawdata;
		}
		// Add remote serviceid to import_data for service
		$import_data['serviceid'] = $data['remote_service_id'];
		$db->q('UPDATE `services` SET `import_data` = ? WHERE `id` = ?', json_encode($import_data) , $array['service']['id']);
		return true;
	}
	function ordercheck($array) {
		global $billic, $db;
		if (empty($array['plan']['import_data'])) {
			$billic->error('The plan "' . safe($array['plan']['name']) . ' is not an imported plan');
			return;
		}
		$import_data = json_decode(trim($array['plan']['import_data']) , true);
		if ($import_data === null) {
			$billic->error('The plan "' . safe($array['plan']['name']) . ' import data is corrupt');
			return;
		}
		if (strlen($array['plan']['import_hash']) != 128) {
			$billic->error('The plan "' . safe($array['plan']['name']) . ' does not have a valid import_hash');
			return;
		}
		curl_setopt($this->ch, CURLOPT_URL, 'https://' . $import_data['domain'] . '/API/');
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
			'email' => $import_data['email'],
			'apikey' => $import_data['apikey'],
			'module' => 'Order',
			'request' => 'call_ordercheck',
			'import_hash' => $array['plan']['import_hash'],
			'order_vars' => json_encode($array['vars']) ,
		));
		$data = curl_exec($this->ch);
		if (curl_errno($this->ch) > 0) {
			$billic->error('Curl error: ' . curl_error($this->ch));
			return;
		}
		$rawdata = trim($data);
		$data = json_decode(trim($rawdata) , true);
		if ($data === null) {
			$billic->error('Remote Billic returned corrupt data: ' . $rawdata);
			return;
		}
		if (isset($data['error'])) {
			$billic->error($data['error']);
			return;
		}
		if (empty($data['domain'])) {
			$billic->error('The remote Billic failed to return a domain name from ordercheck()');
			return;
		}
		return $data['domain'];
	}
	function invoices_hook_paid($array) {
		global $billic, $db;
		if (empty($array['service']['import_data'])) {
			// the service is not imported so we don't need to renew anywhere else
			return true;
		}
		if ($array['service']['domainstatus'] == 'Pending') {
			// the service has been paid for the first time and hence does not need to be renewed
			return true;
		}
		$import_data = json_decode($array['service']['import_data'], true);
		if ($import_data === null || empty($import_data['domain'])) {
			return 'Import data for this service is corrupt';
		}
		curl_setopt($this->ch, CURLOPT_URL, 'https://' . $import_data['domain'] . '/API/');
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
			'email' => $import_data['email'],
			'apikey' => $import_data['apikey'],
			'module' => 'Services',
			'request' => 'renew_service',
			'serviceid' => $import_data['serviceid'],
		));
		$data = curl_exec($this->ch);
		if (curl_errno($this->ch) > 0) {
			return 'Curl error: ' . curl_error($this->ch);
		}
		$rawdata = trim($data);
		$data = json_decode($rawdata, true);
		if ($data === null) {
			return 'Remote Billic returned corrupt data: ' . $rawdata;
		}
		if (isset($data['error']) || $data['result'] != 'ok') {
			return 'Remote Billic Error: ' . $data['error'];
		}
		return true;
	}
	function cron() {
		global $billic, $db;
		$services = $db->q('SELECT * FROM `services` WHERE `domainstatus` = ? AND `module` = ? AND `import_data` != \'\' AND `info_last_sync` < ? ORDER BY `info_last_sync` ASC', 'Active', 'RemoteBillicService', (time() - 3600));
		foreach ($services as $service) {
			$import_data = json_decode($service['import_data'], true);
			if ($import_data === null) {
				//return 'Import data for this service is corrupt';
				continue;
			}
			curl_setopt($this->ch, CURLOPT_URL, 'https://' . $import_data['domain'] . '/API/');
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, array(
				'email' => $import_data['email'],
				'apikey' => $import_data['apikey'],
				'module' => 'Services',
				'request' => 'sync_remote',
				'serviceid' => $import_data['serviceid'],
				'desc' => $service['domain'],
			));
			$data = curl_exec($this->ch);
			if (curl_errno($this->ch) > 0) {
				//return 'Curl error: '.curl_error($this->ch);
				break;
			}
			$rawdata = trim($data);
			$data = json_decode($rawdata, true);
			$db->q('UPDATE `services` SET `info_last_sync` = ?, `info_cache` = ? WHERE `id` = ?', time() , $data['info'], $service['id']);
			if ($data === null) {
				//return 'Remote Billic returned corrupt data: '.$rawdata;
				continue;
			}
			if (isset($data['error']) || $data['result'] != 'ok') {
				//return 'Remote Billic Error: '.$data['error'];
				continue;
			}
		}
	}
}
