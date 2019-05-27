<?php
function network_http_request($url, $additional_headers=array(), $post_vars=array(), $timeout=120) {
	$content = '';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_VERBOSE, false);
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $additional_headers);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	if(isset($additional_headers['User-Agent'])) curl_setopt($ch, CURLOPT_USERAGENT, $additional_headers['User-Agent']);

	if(!empty($post_vars)) {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vars);
	}

	$content = curl_exec($ch);

	$content = str_replace("HTTP/1.0 200 Connection established\r\n\r\n", '', $content);
	curl_close($ch);
	return $content;
}

$output = "PROCESS_EXPIRED:1"."\n";
$api_key = 'b22ffc8f5179b01f449389f84885ae03';

// Process all arguments received from CasperJS
foreach($argv as $argument) {
	$arg_parts = explode("::", $argument);
	if(@$arg_parts[0] == '--PROCESS_UID') {
		$process_uid = (int)urldecode(@$arg_parts[1]);
	} elseif(@$arg_parts[0] == '--GOOGLE_KEY') {
		$google_key = trim(urldecode(@$arg_parts[1]));
	} elseif(@$arg_parts[0] == '--BASE_URL') {
		$base_url = trim(urldecode(@$arg_parts[1]));
	}
}

if($process_uid > 0 && !empty($google_key) && !empty($api_key) && !empty($base_url)) {
	// Send request to fetch captcha id
	$service_url = 'http://2captcha.com/in.php?key='.$api_key;
	$service_url .= "&method=userrecaptcha";
	$service_url .= "&pageurl=".$base_url;
	$service_url .= "&googlekey=".$google_key;

	$response = network_http_request($service_url);
	$response = explode("|", end(explode("\n", $response)));
	if(!empty($response[0]) && $response[0] == 'OK' && !empty($response[1])) {
		$poll_id = $response[1];

		// Now check for answer in interval of 5 seconds, till 1 min
		$lookup_url = 'http://2captcha.com/res.php?key='.$api_key.'&action=get&id='.$poll_id;
		$loop_counter = 0;
		while($loop_counter < 20) {
			$response = network_http_request($lookup_url);
			$response = end(explode("\n", $response));

			if($response == 'ERROR_CAPTCHA_UNSOLVABLE') {
				break;
			} elseif($response != 'CAPCHA_NOT_READY') {
				// Could be error or could answer. Explode it on "|" and then second part will be answer
				$response = explode("|", end(explode("\n", $response)));
				if(!empty($response[0]) && $response[0] == 'OK' && !empty($response[1])) {
					$output = "RECAPTCHA_ANSWER:".$response[1]."\n";
				}

				break;
			} elseif($loop_counter >= 20) {
				break;
			}

			sleep(10);
			$loop_counter++;
		}
	}
}

echo $output;
?>