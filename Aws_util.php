<?php
/**
 * Aws_util Class
 *
 * @package     Readmoo
 * @subpackage  AWS
 * @category    Libraries
 * @author      Willy
 * @link        https://readmoo.com
 */
/*
{
	"input": [
		{
			"path: "task",
			"class": "import",
			"method": "explore",
			"parameters": [$json['key']]
		},
		{
			cli: ""
		},
		{
			cmd: ""
		}
	],
	"output": {
		cli / cmd
	}
}
*/
class Aws_util {
	private static $_s3_protocol = 's3://';
	private $_CI = false;
	private $_config = false;

	function __construct($config = array())
	{
		$this->_CI =& get_instance();
		$this->_config = array_merge(
			[
				'eb_cli' => BASEPATH . 'cli.php'
			],
			$this->_load_config('aws'),
			$config
		);
	}

	public function add_task($task)
	{
		if (is_string($task)) {
			$this->add_cmd($task);
		}
		elseif (isset($task['cmd'])) {
			$this->add_cmd($task['cmd']);
		}
		elseif (isset($task['cli'])) {
			$this->add_cli($task['cli']);
		}
		elseif (is_array($task)) {
			foreach ($task as $key => $value) {
				is_numeric($key) && $this->add_task($value);
			}
		}
		return $this;
	}

	public function add_cmd($cmd)
	{
		if (is_string($cmd)) {
			$this->_tasks[] = [
				'cmd' => $cmd
			];
		}
		elseif (is_array($cmd)) {
			foreach ($cmd as $key => $value) {
				if (is_numeric($key) && is_string($value)) {
					$this->_tasks[] = [
						'cmd' => $value
					];
				}
			}
		}
		return $this;
	}

	public function add_cli($cli)
	{
		if (is_array($cli) && empty($cli['class'])) {
			return;
		}
		$this->_tasks[] = [
			'cli' => $cli
		];
		return $this;
	}

	private function _cli_to_cmd($cli)
	{
		$command = $this->_config['eb_cli'];
		if (is_string($cli)) {
			$command .= ' ' . $cli;
		}
		elseif (isset($cli['class'])) {
			if ( ! empty($cli['path'])) {
				$command .= ' ' . implode(' ', (array)$cli['path']);
			}
			$command .= ' ' . $cli['class'];
			$command .= ' ' . ((empty($cli['method']) OR ! is_string($cli['method'])) ? 'index' : $cli['method']);
			if (isset($cli['parameters'])) {
				$command .= ' ' . (is_array($cli['parameters']) ? implode(' ', $cli['parameters']) : $cli['parameters']);
			}
		}
		else {
			return FALSE;
		}
		return $command;
	}

	public function get_combined_cmd($tasks = array())
	{
		foreach ($tasks as $mode => $task) {
			$this->add_task($task);
		}
		$cmds = array();
		foreach ($this->_tasks as $task) {
			if (isset($task['cmd'])) {
				$cmd = $task['cmd'];
			}
			elseif (isset($task['cli'])) {
				$cmd = $this->_cli_to_cmd($task['cli']);
			}
			else {
				unset($cmd);
			}
			if ( ! empty($cmd)) {
				$cmds[] = $cmd;
			}
		}
		return implode(' && ', $cmds);
	}

	public function clear_task()
	{
		$this->_tasks = [];
		return $this;
	}

	public function s3_sync($source, $target, $options = false)
	{
		$args = [];
		$mode = 'sync';
		$dry_run = false;
		$use_quote = true;
		$quite = true;
		$no_mime_magic = true;
		if (is_array($options)) {
			foreach ($options as $key => $value) {
				switch (strtolower($key)) {
					case 'mode':
						if (in_array($value, ['sync', 'get', 'put', 'cp', 'mv'])) {
							$mode = $value;
						}
						if ($mode == 'put' &&
							(strpos($source, self::$_s3_protocol) === 0 OR ! file_exists($source))
						) {
							return false;
						}
						break;

					case 'recursive':
						if ($value) {
							$args[] = '-r';
						}
						break;

					case 'reducedredundancy':
						if ($value) {
							$args[] = '--rr';
						}
						break;

					case 'public':
						if ($value) {
							$args[] = '-P';
						}
						break;

					case 'force':
						if ($value) {
							$args[] = '-f';
						}
						break;

					case 'delete':
						if ($value) {
							$args[] = '--delete-removed';
						}
						break;

					case 'dry_run':
						$dry_run = $value;
						break;

					case 'quote':
						$use_quote = $value;
						break;

					case 'quite':
						$quite = $value;
						break;

					case 'no-mime-magic':
						$no_mime_magic = $value;
						break;

					default:
						break;
				}
			}
		}

		if ($quite) {
			$args[] = '-q';
		}
		if ($no_mime_magic) {
			$args[] = '--no-mime-magic';
		}
		$cmd = sprintf(
			empty($use_quote) ? '%s %s %s %s %s' : '%s %s %s "%s" "%s"',
			$this->_config['cmd_s3cmd'],
			$mode,
			implode(' ', $args),
			$source,
			$target
		);
		if ($dry_run) {
			return $cmd;
		}
		else {
			$this->_CI->load->add_package_path(config_item('common_package'));
			$this->_CI->load->library('process_lib');
			$this->_CI->load->remove_package_path(config_item('common_package'));
			return $this->_CI->process_lib->execute($cmd);
		}
	}

	public function s3_del($s3_key)
	{
		if (empty($s3_key) OR strpos($s3_key, self::$_s3_protocol) !== 0) {
			return false;
		}
		$this->_CI->load->add_package_path(config_item('common_package'));
		$this->_CI->load->library('process_lib');
		$this->_CI->load->remove_package_path(config_item('common_package'));
		return $this->_CI->process_lib->execute($this->_config['cmd_s3cmd'] . ' del -r ' . $s3_key);
	}

	public function s3_key(array $params, $mode = 'ebook', $use_cf = false)
	{
		$segments = [
			self::$_s3_protocol . (
				$use_cf ?
					$this->_config['cf_bucket'] :
					$this->_config['s3_bucket']
			),
			$mode
		];
		switch ($mode) {
			case 'ebook':
				if (isset($params['file'])) {
					$file = $params['file'];
					if (empty($file['manifestation_id']) OR
						empty($file['sn'])
					) {
						return false;
					}
					$segments[] = $file['manifestation_id'] % 1000;
					$segments[] = $file['manifestation_id'];
					$segments[] = $file['sn'];
					if (empty($file['version']) OR
						empty($file['setting'])
					) {
						break;
					}
					$setting = is_string($file['setting']) ?
						json_decode($file['setting'], true) :
						$file['setting'];
					if (isset($setting['revision'])) {
						$segments[] = $file['version'] . '_' . $setting['revision'];
					}
				}
				// manifestataion
				elseif (isset($params['manifestation'])) {
					if (empty($params['manifestation']['sn'])) {
						return false;
					}
					$segments[] = $params['manifestation']['sn'] % 1000;
					$segments[] = $params['manifestation']['sn'];
				}
				break;

			case 'cover':
				if ( ! empty($params['manifestation']['sn'])) {
					function_exists('id_encrypt') OR $this->_CI->load->helper('id_encrypt');
					$encoded_id = id_encode($params['manifestation']['sn']);
				}
				elseif (isset($params['encoded_id'])) {
					$encoded_id = $params['encoded_id'];
				}
				if (isset($encoded_id) && strlen($encoded_id) > 2) {
					$segments[] = substr($encoded_id, 0, 2);
					$segments[] = substr($encoded_id, 2);
				}
				break;

			case 'book':
				$segments[] = empty($params['mode']) ? 'preview' : $params['mode'];
				if ( ! empty($params['readmoo_id'])) {
					$segments[] = $params['readmoo_id'];
				}
				break;

			case 'avatar':
				if (isset($params['avatar_path'])) {
					$segments[] = $params['avatar_path'];
				}
				break;

			case 'full':
			case 'preview':
			case 'manual':
				if (empty($params['file'])) {
					return false;
				}
				array_pop($segments);
				$segments[] = 'ebook';
				$file = $params['file'];
				if (empty($file['manifestation_id']) OR
					empty($file['sn'])) {
					return false;
				}
				$segments[] = $file['manifestation_id'] % 1000;
				$segments[] = $file['manifestation_id'];
				$segments[] = $file['sn'];
				if (empty($file['version']) OR
					empty($file['setting'])) {
					return false;
				}
				$setting = is_string($file['setting']) ?
					json_decode($file['setting'], true) :
					$file['setting'];
				if (isset($setting['revision'])) {
					$segments[] = $file['version'] . '_' . $setting['revision'];
				}
				$segments[] = $mode;
				break;

			case 'social/cover':
				if ( ! empty($params['work_id'])) {
					function_exists('id_encrypt') OR $this->_CI->load->helper('id_encrypt');
					$encoded_id = id_encode($params['work_id']);
				}
				elseif (isset($params['encoded_id'])) {
					$encoded_id = $params['encoded_id'];
				}
				if (isset($encoded_id) && strlen($encoded_id) > 2) {
					$segments[] = substr($encoded_id, 0, 2);
					$segments[] = substr($encoded_id, 2);
				}
				break;

			default:
				return false;
				break;
		}
		return implode('/', $segments) . '/';
	}

	private function _load_config($file)
	{
		$config = $this->_CI->config->item($file);
		if (empty($config)) {
			$this->_CI->config->load($file, true);
		}
		$config = $this->_CI->config->item($file);
		return empty($config) ? [] : $config;
	}

	public function get_config($key)
	{
		return $this->_config[$key];
	}

	public function set_config($key, $value)
	{
		$this->_config[$key] = $value;
	}

	public function set_signed_cookies(array $params)
	{
		if (empty($params['url']) OR
			! preg_match('|(http[s\*]?):\/\/([^\/]+)(\/.*)$|', $params['url'], $match)
		) {
			throw new Exception('Invalid parameters, no url found.', 400);
		}
		$key_id = $this->get_config('cf_keypair_id');
		$use_custom_policy = strpos($match[3], '*') > 0;
		$path = $use_custom_policy ?
			substr($match[3], 0, strpos($match[3], '*')) :
			$match[3];
		$secure = $match[1] != 'http';
		if (empty($params['less_than'])) {
			$params['less_than'] = time() + config_item('sess_expiration');
		}
							
		if ($use_custom_policy) {
			$policy = $this->_get_custom_policy($params);

			$this->_set_cookie(
				'Policy',
				$this->_safe_base64_encode($policy),
				$path,
				$secure
			);
		}
		else {
			$policy = $this->_get_canned_policy($params);

			$this->_set_cookie(
				'Expires',
				$params['less_than'],
				$path,
				$secure
			);
		}

		$this->_set_cookie(
			'Signature',
			$this->_safe_base64_encode($this->_sign($policy)),
			$path,
			$secure
		);

		$this->_set_cookie(
			'Key-Pair-Id',
			$key_id,
			$path,
			$secure
		);
echo $policy;
		return true;
	}

	private function _get_custom_policy(array $params)
	{
		$policy = $this->_get_policy_statement($params);
		if (isset($params['greater_than'])) {
			$policy['Statement']['Condition']['DateGreaterThan'] = ['AWS:EpochTime' => $params['greater_than']];
		}
		if (isset($params['ip'])) {
			$policy['Statement']['Condition']['IpAddress'] = $params['ip'];
		}
		return json_encode($policy);
	}

	private function _get_canned_policy(array $params)
	{
		return json_encode($this->_get_policy_statement($params));
	}

	private function _get_policy_statement(array $params)
	{
		return [
			'Statement' => [
				'Resource' => $params['url'],
				'Condition' => [
					'DateLessThan' => [
						'AWS:EpochTime' => $params['less_than']
					]
				]
			]
		];
	}

	private function _safe_base64_encode($content)
	{
		return str_replace(['+', '=', '/'], ['-', '_', '~'], base64_encode($content));
	}

	private function _sign($data)
	{
		$priv_key_id = openssl_get_privatekey('file://' . $this->get_config('cf_pk_pathname'));
		openssl_sign($data, $signature, $priv_key_id);
		openssl_free_key($priv_key_id);

		return $signature;
	}

	private function _set_cookie($name, $value, $path, $secure = true)
	{
		setcookie(
			'CloudFront-' . $name,
			$value,
			0,
			$path,
			$_SERVER['DOMAIN'],
			$secure,
			true
		);
	}
}
// END Aws_util Class

/* End of file Aws_util.php */
