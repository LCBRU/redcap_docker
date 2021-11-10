<?php 
/*****************************************************************************************
**  REDCap is only available through ACADMEMIC USER LICENSE with Vanderbilt University
******************************************************************************************/

// Turn off error reporting
error_reporting(0);

// Prevent caching
header("Expires: 0");
header("cache-control: no-store, no-cache, must-revalidate"); 
header("Pragma: no-cache");

// Include the db connection file
$db_conn_file = dirname(__FILE__) . '/database.php';	
include ($db_conn_file);
if (!isset($username) || !isset($password) || !isset($db) || (!isset($hostname) && !isset($db_socket)))
{
	exit("There is not a valid hostname ($hostname) / database ($db) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file].");
}

## CONNECT TO DB and OBTAIN REDCAP VERSION NUMBER
$redcap_version = '';
// // MySQLi extension db connect
if (!isset($db_socket)) $db_socket = null;	
if ($db_socket !== null) {
	if ($password == '') $password = null;
}
$port = '';
if (strpos($hostname, ':') !== false) {
	list ($hostname_wo_port, $port) = explode(':', $hostname, 2);
}
$hostname = preg_replace("/\:.*/", '', $hostname);
if (!is_numeric($port)) $port = '3306'; // Default MySQL port
if ($hostname === null && $db_socket === null) $port = null;
if (isset($db_ssl_ca) && $db_ssl_ca != '') {
	// Connect to MySQL via SSL
	defined("MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT") or define("MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT", 64);
	$conn = mysqli_init();
	mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
	mysqli_ssl_set($conn, $db_ssl_key, $db_ssl_cert, $db_ssl_ca, $db_ssl_capath, $db_ssl_cipher);
	$conn_ssl = mysqli_real_connect($conn, $hostname, $username, $password, $db, $port, $db_socket, ((isset($db_ssl_verify_server_cert) && $db_ssl_verify_server_cert) ? MYSQLI_CLIENT_SSL : MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT));	
} else {
	// Connect to MySQL normally
	$conn = mysqli_connect($hostname, $username, $password, $db, $port, $db_socket);
}
if (!$conn || (isset($conn_ssl) && !$conn_ssl)) {	
	$db_error_msg = "Your REDCap database connection file [$db_conn_file] could not connect to the database server.
					 Please check the connection values in that file (\$hostname, \$db, \$username, \$password)
					 because they may be incorrect.";
	?>
	<div style="font: normal 12px Verdana, Arial;padding:20px;border: 1px solid red;color: #800000;max-width: 600px;background: #FFE1E1;">
		<div style="font-weight:bold;font-size:15px;padding-bottom:5px;">
			CRITICAL ERROR: REDCap server is offline!
		</div>
		<div>
			For unknown reasons, REDCap cannot communicate with its database server, which may be offline. Please contact your
			local REDCap administrator to inform them of this issue immediately. If you are a REDCap administrator, then please see this
			<a href="javascript:;" style="color:#000066;" onclick="document.getElementById('db_error_msg').style.display='block';">additional information</a>.
			We are sorry for any inconvenience.
		</div>
		<div id="db_error_msg" style="display:none;color:#333;background:#fff;padding:5px 10px 10px;margin:20px 0;border:1px solid #bbb;">
			<b>Message for REDCap administrators:</b><br/><?php echo $db_error_msg ?>
		</div>
	</div>
	<?php
	exit;
}
// Find the current system version of REDCap
$sql_get_version = "select value from redcap_config where field_name = 'redcap_version'";
$qrow = mysqli_fetch_assoc(mysqli_query($conn, $sql_get_version));
$redcap_version = $qrow['value'];
$rc_connection = $conn;
if ($redcap_version == '') exit("ERROR: Could not find the correct version of REDCap in the \"redcap_config\" table!");

// Include the config file from the proper REDCap version folder
if (!defined("REDCAP_CONNECT_NONVERSIONED")) 
{
	// Make note is this is a plugin, a hook, or an External Modules script
	if (!defined("PLUGIN")) define("PLUGIN", true);
	// Determine if a project page or not (will have 'pid' in URL)
	$configFile = (isset($_GET['pid']) && is_numeric($_GET['pid'])) ? "init_project.php" : "init_global.php";
	// Set the full path to the config file
	$configPath = dirname(__FILE__) . "/redcap_v" . $redcap_version . "/Config/$configFile";
	// Try to call the config file to connect to REDCap
	if (!include_once $configPath) exit("ERROR: Could not find the correct file ($configPath)!");
}