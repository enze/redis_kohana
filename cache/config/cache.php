<?php defined('SYSPATH') or die('No direct script access.');
return array (
	'default' => array(
		'driver'             => 'redis',
		'default_expire'     => 3600,
		'servers'            => array(
			array(
				'host'		=> 'localhost',  // Redis Server
				'port'		=> 6379,        // Redis port number
				'protocol'	=> 'TCP',        // Protocol connection  TCP or UDP default TCP
				'timeout'	=> 1,
				'auth'	=> false,
				'database'	=>	15,				//database number
				'retry'		=>	5,				//retry times  suggest max 5 times, 0 stand for no-retry
			),
		),
	),
);