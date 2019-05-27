<?php
$configs = array();
$configs[] = array(
	'process_uid' => 1113,
	'portal_name' => 'Amazon.de',
	'username' => 'amazon@salespier.com',
	'password' => 'k4k43mIII83ujj22',
	'base_path' => '/home/ubuntu/selenium/',
	'port' => 4444,
	'debug_port' => 5900
);

$base_script = '/home/ubuntu/selenium/base_script.php';
foreach($configs as $idx=>$config) {
	// Clean downloads folder of previous test and recreate if missing
	$downloads_folder = $config['base_path'].'downloads/';
	exec("rm -rf ".$downloads_folder);
	exec("mkdir -p ".$downloads_folder);
	exec("chmod 777 ".$downloads_folder);

	// Prepare script for each
	$script_file = $config['base_path'].'script.php';

	// Portal Script file
	$portal_script_file = $config['base_path'].'portal_script.php';

	// Merge portal script
	$script_content = str_replace(array('<?php', '<?', '?>'), '', file_get_contents($portal_script_file));
	file_put_contents($script_file, str_replace('%PHP_SELENIUM_PORTAL_SCRIPT%', $script_content, file_get_contents($base_script)));

	$script_content = str_replace(
		array('%SCREEN_CAPTURE_LOCATION%', '%portal_name%', '%process_uid%', '%username%', '%password%'),
		array($config['base_path'].'screens/', $config['portal_name'], $config['process_uid'], addslashes($config['username']), addslashes($config['password'])),
		file_get_contents($script_file)
	);
	file_put_contents($script_file, $script_content);
	print "Script created - ".$script_file."\n";

	// Stop/Start selenium
	echo "Starting selenium node\n";

	exec("docker rm -f selenium-hub-" . $config['process_uid']);
	exec("docker rm -f selenium-node-" . $config['process_uid']);
	exec("docker run -d --shm-size 2g -p " . $config['port'] . ":4444 -e GRID_TIMEOUT=0 -e GRID_BROWSER_TIMEOUT=0 --name selenium-hub-" . $config['process_uid'] . " selenium/hub:latest");
	exec("docker run -d --shm-size 2g -P -p " . $config['debug_port'] . ":5900 --log-driver=none -e LANG=de -e GRID_TIMEOUT=0 -e GRID_BROWSER_TIMEOUT=0 -e SCREEN_WIDTH=1920 -e SCREEN_HEIGHT=1080 --name selenium-node-" . $config['process_uid'] . " --link selenium-hub-" . $config['process_uid'] . ":hub -v " . $config['base_path'] . "downloads:/home/seluser/Downloads/" . $config['process_uid'] . " selenium/node-firefox-debug:2.53.1");

	sleep(20);

	// Execute
	$cmd = "php ".$script_file." > ".$config['base_path']."sel.log 2>&1 &";
	exec($cmd);

	print "Command to view log - \ntail -f sel.log\n\n";
}
?>