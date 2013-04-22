<?php defined('SYSPATH') or die('No direct script access.');


class Kohana_Cache_Redis extends Cache implements Cache_Arithmetic {
	
	protected $_redis = null;
	
	protected $_errno = 0;
	
	protected $_errstr = '';
	
	protected $_server = array();
	
	protected $_retry = 0;
	
	public function __construct($config) {

		parent::__construct($config);

		// Load servers from configuration
		$this->_server = Arr::get($this->_config, 'servers', NULL);

		if ( ! $this->_server)
		{
			// Throw an exception if no server found
			throw new Cache_Exception('No redis servers defined in configuration');
		}
		
		$key = $this->_setConn();
		
		if (false !== $this->_server[$key]['retry']) {
			$this->_retry = $this->_server[$key]['retry'] > 5 ? 5 : $this->_server[$key]['retry'];
		}

		$this->connect($this->_server[$key]['host'], $this->_server[$key]['port'], $this->_server[$key]['protocol'], $this->_server[$key]['timeout']);
		
		if (false !== $this->_server[$key]['auth']) {
			$this->_authcation($this->_server[$key]['auth']);
		}
		
		$this->_changeDb($this->_server[$key]['database']);
		
	}
	
	/**
	 * 设置连接参数
	 *
	 * @return int
	 */
	private function _setConn() {
		shuffle($this->_server);
		$randomKey = array_rand($this->_server, 1);
		return $randomKey;
	}
	
	/**
	 * socket连接redis服务器
	 *
	 * @param string $host
	 * @param int $port
	 * @param string $protocol
	 * @param int $timeout
	 */
	private function _connect($host, $port, $protocol, $timeout) {
		$protocol = ('TCP' == strtoupper($protocol) ? 'tcp' : 'udp');
		$times = 1;
		do {
			if ($times > $this->_retry) {
				throw new Cache_Exception('Redis could not connect to host \':host\' using port \':port\' base on protocol \':protocol\'. Error code \':errno\', error \:error\'', array(':host' => $host, ':port' => $port, ':protocol' => $protocol, ':errno' => $this->_errno, ':error' => $this->_errstr));
				break;	//maybe ignore
			}
			
			@ $this->_redis = fsockopen($protocol . '://' . $host, $port, $this->_errno, $this->_errstr, $timeout);
			if ($this->_redis) {
				break;
			}
			$times++;
		} while (true);
	}
	
	/**
	 * 发送请求
	 *
	 * @param mix $args
	 * @return mix
	 */
	private function _request($args) {
		$command = '*' . count($args) . "\r\n";
		foreach ($args as $arg) {
			$command .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
		}
		$times = 1;
		do {
			if ($times > $this->_retry) {
				return false;
				break;
			}
			@ $send = fwrite($this->_redis, $command, strlen($command));
			if ($send) {
				break;
			}
		} while (true);
		$response = $this->_response();
		return $response;
	}
	
	/**
	 * 应答
	 *
	 * @return mix
	 */
	private function _response() {
		$times = 1;
		do {
			if ($times > $this->_retry) {
				return false;
				break;
			}
			@ $serverReply = fgets($this->_redis);
			if ($serverReply) {
				break;
			}
		} while (true);

		$serverReply = trim($serverReply);
		$response = '';
		$replyTips = (int)substr($serverReply, 1);
		switch ($serverReply[0]) {
			case '-':
				$this->_outPutMessage($serverReply);
				$response = false;
				break;
			case '+':
				$response = $replyTips;
				break;
			case '*':
				$response = array();
				$total = $replyTips;
				for ($i = 0; $i < $total; $i++) {
					$response[] = $this->_response();
				}
				break;
			case '$':
				$total = $replyTips;
				if ('-1' == $total) {
					$response = null;
				} else {
					if ($total > 0) {
						$response = stream_get_contents($this->_redis, $total);
					}
					fread($this->_redis, 2);
				}
				break;
			case ':':
				$response = $replyTips;
				break;
			default:
				$this->_outPutMessage($replyTips);
				$response = false;
				break;
		}
		return $response;
	}
	
	/**
	 * 选择数据库
	 *
	 * @param int $db
	 * @return string
	 */
	private function _changeDb($db) {
		return $this->_request(array('select', $db));
	}
	
	/**
	 * 校验当前连接
	 *
	 * @param string $password
	 */
	private function _authcation($password) {
		$this->_request('auth', $password);
	}
	
	/**
	 * 错误信息
	 *
	 * @param string $message
	 */
	private function _outPutMessage($message) {
		trigger_error($message);
	}
	
	/**
	 * 魔术接口
	 *
	 * @param string $name
	 * @param array $args
	 * @return mix
	 */
	public function __call($name, $args) {
		/**
		 * 提供的方法接口
		 */
		if ('_' == $name[0]) {
			if (true === isset($args[1]) && true === is_array($args[1])) {
				array_unshift($args[1], $args[0]);
				$args = $args[1];
			}
			return $this->_request($args);
		} else {
			array_unshift($args, $name);
			return $this->_request($args);
		}
	}
	
	/**
	 * 连接redis服务器
	 *
	 * @param string $host
	 * @param int $port
	 * @param string $protocol
	 * @param int $timeout
	 * @return boolean
	 */
	public function connect($host, $port, $protocol, $timeout) {
		return $this->_connect($host, $port, $protocol, $timeout);
	}

	/**
	 * 获取string类型的key对应的value
	 * 真心不想封装，可惜是抽象方法
	 *
	 * @param string $key
	 * @param null $default
	 * @return string
	 */
	public function get($key, $default = null) {
		return json_decode($this->_request(array('get', $key)), true);
	}
	
	/**
	 * 设置string类型的key对应的value
	 * 同上
	 *
	 * @param string $key
	 * @param mix $data
	 * @param int $lifeTime
	 */
	public function set($key, $data, $lifeTime = 3600) {
		$this->_set('set', $key, json_encode($data));
		$this->_setExpire('expire', $key, $lifeTime);
	}
	
	/**
	 * 删除列表，集合，有序集合，哈希表，字符串的key
	 *
	 * @param mix $key
	 *
	 */
	public function delete($key) {
		array_unshift($key, 'del');
		return $this->_request($key);
	}
	
	/**
	 * 清空当前数据库
	 *
	 * @return string
	 */
	public function delete_all() {
		return $this->_flushDb('flushdb');
	}
	
	/**
	 * 返回key所对应的整型value+1的值，无符号64位正整型
	 *
	 * @param string $key
	 * @param int $step
	 * @return int
	 */
	public function increment($key, $step = 1) {
		for ($i = 0; $i < $step; $i++) {
			$increment = $this->_increment('incr', $key);
		}
		return $increment;
	}
	
	
	/**
	 * 返回key所对应的整型value-1的值，无符号64位正整型
	 *
	 * @param string $key
	 * @param int $step
	 * @return int
	 */
	public function decrement($key, $step = 1) {
		for ($i = 0; $i < $step; $i++) {
			$decrement = $this->_decrement('decr', $key);
		}
		return $decrement;
	}
}