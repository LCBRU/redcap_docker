<?php

/**
 * LDAP CONNECTION INFORMATION
 * Customize values below for your institution's LDAP connection. See examples below.
 */
$GLOBALS['ldapdsn'] = array(
	'url' 		 => 'ldap.myinstitution.edu',
	'port' 		 => '389',
	'version'  	 => '3',
	'basedn'     => 'ou=people,dc=myinstitution,dc=edu',
	'binddn'   	 => 'ou=people,dc=myinstitution,dc=edu',
	'bindpw'     => '',
	'attributes' => array('uid'),
	'userattr'   => 'uid',
	'userfilter' => ''
);


/*

TO SEE FULL DOCUMENTATION ON SETTING UP THE CONNECTION INFO, NAVIGATE TO
http://pear.php.net/manual/en/package.authentication.auth.storage.ldap.php


// EXAMPLE #1: LDAP that does not require username/password during binding
$GLOBALS['ldapdsn'] = array(
	'url' 		 => 'ldap.med.cornell.edu',
	'port' 		 => '636',
	'version'  	 => '3',
	'basedn'     => 'ou=people,o=nypcornell.org',
	'binddn'   	 => 'ou=people,o=nypcornell.org',
	'bindpw'     => '',
	'attributes' => array('uid'),
	'userattr'   => 'uid',
	'userfilter' => ''
);

// EXAMPLE #2: LDAPS (i.e. LDAP over SSL) that also requires username/password during binding
$GLOBALS['ldapdsn'] = array(
	'url'		=> 'ldaps://ldap.vunetid.vanderbilt.edu',
	'port'		=> '636',
	'version'  	=> '3',
	'binddn'   	=> 'uid='.$_POST['username'].',ou=people,dc=vanderbilt,dc=edu',
	'bindpw'	=> $_POST['password']
);

// EXAMPLE #3: Using multiple LDAP servers that are daisy-chained together (if one fails to authenticate, then tries next LDAP listed)
// NOTE: The array is set up a little differently here than for a single LDAP configuration, so make sure all formatting is 
// done correctly, or else PHP will crash when REDCap is loaded.
$GLOBALS['ldapdsn'] = array(
	array(
		'url'		=> 'ldaps://ldap.vunetid.mc.vanderbilt.edu',
		'port'		=> '636',
		'version'  	=> '3',
		'binddn'   	=> 'uid='.$_POST['username'].',ou=people,dc=vanderbilt,dc=edu',
		'basedn'   	=> 'ou=people,dc=vanderbilt,dc=edu',
		'bindpw'	=> $_POST['password']
	),
	array(
		'url'		=> 'ldap://major.mis.vanderbilt.edu',
		'version'  	=> '3',
		'binddn'   	=> 'dc=mis,dc=vanderbilt,dc=edu',
		'basedn'   	=> 'dc=mis,dc=vanderbilt,dc=edu'
	)
);

// There is no limit to how many LDAP configurations can be daisy-chained. Simply follow the format below when adding more LDAP configs.
$GLOBALS['ldapdsn'] = array(
	array(
		...
	),
	array(
		...
	),
	array(
		...
	),
	array(
		...
	),
	...
);

*/