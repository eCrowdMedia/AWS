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
			"parameters": array($json['key'])
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
			array(
				'eb_cli' => BASEPATH . 'cli.php'
			),
			$this->_load_config('aws'),
			$config
		);
	}

	public function add_task($task)
	{
		if (is_string($task))
			$this->add_cmd($task);
		elseif (isset($task['cmd']))
			$this->add_cmd($task['cmd']);
		elseif (isset($task['cli']))
			$this->add_cli($task['cli']);
		elseif (is_array($task))
			foreach ($task as $key => $value)
				if (is_numeric($key)) $this->add_task($value);
		return $this;
	}

	public function add_cmd($cmd)
	{
		if (is_string($cmd))
			$this->_tasks[] = array(
				'cmd' => $cmd
			);
		elseif (is_array($cmd))
			foreach ($cmd as $key => $value)
				if (is_numeric($key) && is_string($value))
					$this->_tasks[] = array(
						'cmd' => $value
					);
		return $this;
	}

	public function add_cli($cli)
	{
		if (is_array($cli) && empty($cli['class'])) return;
		$this->_tasks[] = array(
			'cli' => $cli
		);
		return $this;
	}

	private function _cli_to_cmd($cli)
	{
		$command = $this->_config['eb_cli'];
		if (is_string($cli))
			$command .= ' ' . $cli;
		elseif (isset($cli['class']))
		{
			empty($cli['path']) OR $command .= ' ' . implode(' ', (array)$cli['path']);
			$command .= ' ' . $cli['class'];
			$command .= ' ' . ((empty($cli['method']) OR ! is_string($cli['method'])) ? 'index' : $cli['method']);
			if (isset($cli['parameters'])) $command .= ' ' . (is_array($cli['parameters']) ? implode(' ', $cli['parameters']) : $cli['parameters']);
		}
		else
			return FALSE;
		return $command;
	}

	public function get_combined_cmd($tasks = array())
	{
		foreach ($tasks as $mode => $task)
			$this->add_task($task);
		$cmds = array();
		foreach ($this->_tasks as $task)
		{
			if (isset($task['cmd']))
				$cmd = $task['cmd'];
			elseif (isset($task['cli']))
				$cmd = $this->_cli_to_cmd($task['cli']);
			else
				unset($cmd);
			if ( ! empty($cmd)) $cmds[] = $cmd;
		}
		return implode(' && ', $cmds);
	}

	public function clear_task()
	{
		$this->_tasks = array();
		return $this;
	}

	public function s3_sync($source, $target, $options = false)
	{
		$args = array();
		$mode = 'sync';
		$dry_run = false;
		$use_quote = true;
		if (is_array($options)) {
			foreach ($options as $key => $value) {
				switch (strtolower($key)) {
					case 'mode':
						if (in_array($value, array('sync', 'get', 'put'))) {
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

					default:
						break;
				}
			}
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
		$segments = array(
			self::$_s3_protocol . (
				$use_cf ?
					$this->_config['cf_bucket'] :
					$this->_config['s3_bucket']
			),
			$mode
		);
		switch ($mode) {
			case 'ebook':
				if (isset($params['file'])) {
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
				if (isset($params['manifestation'])) {
					if (empty($params['manifestation']['sn'])) {
						return false;
					}
					$this->load->helper('id_encrypt');
					$encoded_id = id_encode($params['manifestation']['sn']);
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
		return empty($config) ? array() : $config;
	}

	public function get_config($key)
	{
		return $this->_config[$key];
	}

	public function set_config($key, $value)
	{
		$this->_config[$key] = $value;
	}
}
// END Aws_util Class

/* End of file Aws_util.php */
