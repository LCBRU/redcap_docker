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
## NOTE: To be backward compatible with older REDCap versions, this script will perform a db connection using 
## the MySQL extension. And to be forward compatible with PHP 5.5 (which deprecates the MySQL extension),
## it will additionally perform a db connection using the MySQLi extension, which is solely used in REDCap
## in version 5.1.0 and later. This allows plugin developers to utilize either MySQL or MySQLi db functions
## in their plugins, although MySQLi is preferred.
$redcap_version = '';
$sql_get_version = "select value from redcap_config where field_name = 'redcap_version'";
// If on a PHP version below 5.5, then still call mysql_connect in addition to mysqli_connect (for compatibility with older plugins)
if (version_compare(PHP_VERSION, '5.5.0', '<') && function_exists('mysql_connect')) {
	// MySQL extension db connect
	$conn = mysql_connect($hostname,$username,$password);
	if (!$conn) {
		exit("The hostname ($hostname) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file] could not connect to the server. Please check their values.<br><br>You may need to complete the <a href='install.php'>installation</a>."); 
	}
	if (!mysql_select_db($db, $conn))  {
		exit("The hostname ($hostname) / database ($db) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file] could not connect to the server. Please check their values.<br><br>You may need to complete the <a href='install.php'>installation</a>."); 
	}
	// Find the current system version of REDCap
	$redcap_version = mysql_result(mysql_query($sql_get_version), 0);
}
// // MySQLi extension db connect
if (function_exists('mysqli_connect')) {
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
		$conn = mysqli_init();
		mysqli_options($conn, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
		mysqli_ssl_set($conn, $db_ssl_key, $db_ssl_cert, $db_ssl_ca, $db_ssl_capath, $db_ssl_cipher);
		mysqli_real_connect($conn, $hostname, $username, $password, $db, $port, $db_socket, MYSQLI_CLIENT_SSL);	
	} else {
		// Connect to MySQL normally
		$conn = mysqli_connect($hostname, $username, $password, $db, $port, $db_socket);
	}
	if (!$conn) {
		exit("The hostname ($hostname) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file] could not connect to the server. Please check their values.<br><br>You may need to complete the <a href='install.php'>installation</a>."); 
	}
	// Find the current system version of REDCap (if did not obtain from MySQL extension above)
	if ($redcap_version == '') {
		$qrow = mysqli_fetch_assoc(mysqli_query($conn, $sql_get_version));
		$redcap_version = $qrow['value'];
	}
}

// Include the config file from the proper REDCap version folder
if ($redcap_version != '') 
{
	// Make note that this is a plugin using a PHP constant
	define("PLUGIN", true);
	// Determine if a project page or not (will have 'pid' in URL)
	$configFile = (isset($_GET['pid']) && is_numeric($_GET['pid'])) ? "init_project.php" : "init_global.php";
	// Set the full path to the config file
	$configPath = dirname(__FILE__) . "/redcap_v" . $redcap_version . "/Config/$configFile";
	// Try to call the config file to connect to REDCap
	if (!include_once $configPath) 
	{
		print "ERROR: Could not find the correct file ($configPath)!.";
	}	
} 
else 
{
	print "ERROR: Could not find the correct version of REDCap in the \"redcap_config\" table!";
}
